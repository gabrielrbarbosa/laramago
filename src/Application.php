<?php

declare(strict_types=1);

namespace Laramago;

final class Application
{
    private const VERSION = '0.1.43';

    private const CONFIG_FILE = 'mago.toml';

    private const BASELINE_FILE = 'laramago-analyzer-baseline.toml';

    private const CACHE_DIR = '.laramago/cache';

    private const STATE_DIR = '.laramago';

    private const LOCK_FILE = '.laramago/laramago.lock';

    private const MODEL_OVERLAY_DIR = '.laramago/cache/model-overlays';

    private const MODEL_OVERLAY_MAP = '.laramago/cache/model-overlays.json';

    private const FRAMEWORK_OVERLAY_DIR = '.laramago/cache/framework-overlays';

    private const PHPSTAN_PRAGMA_OVERLAY_DIR = '.laramago/cache/phpstan-pragma-overlays';

    private const PHPSTAN_PRAGMA_OVERLAY_MAP = '.laramago/cache/phpstan-pragma-overlays.json';

    private const EXCLUDED_SYMBOL_DIR = '.laramago/cache/excluded-symbols';

    private const LARAVEL_SYMBOL_DIR = '.laramago/cache/laravel-symbols';

    private const RUNTIME_CONFIG_FILE = '.laramago/cache/mago.toml';

    private const RUNTIME_BASELINE_FILE = '.laramago/cache/analyzer-baseline.toml';

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $arguments = array_slice($argv, 2);

        return match ($command) {
            'init' => $this->init($arguments),
            'prepare' => $this->prepare($arguments),
            'analyze' => $this->analyze($arguments),
            'baseline' => $this->baseline($arguments),
            'verify-baseline' => $this->verifyBaseline($arguments),
            'doctor' => $this->doctor($arguments),
            'clear' => $this->clear($arguments),
            'count' => $this->analyze(array_merge(['--reporting-format=count'], $arguments)),
            'codes' => $this->analyze(array_merge(['--list-codes'], $arguments)),
            'help', '--help', '-h' => $this->help(),
            'migrate-phpstan' => $this->migratePhpStan($arguments),
            'version', '--version', '-V' => $this->version(),
            default => $this->unknown($command),
        };
    }

    /**
     * @param list<string> $arguments
     */
    private function init(array $arguments): int
    {
        $projectRoot = $this->projectRoot($arguments);
        $force = in_array('--force', $arguments, true);
        $configPath = $projectRoot . '/' . self::CONFIG_FILE;

        if (is_file($configPath) && ! $force) {
            $this->line('mago.toml already exists. Use --force to replace it.');

            return 1;
        }

        $sourcePaths = $this->optionValues($arguments, '--source=');
        $excludes = $this->optionValues($arguments, '--exclude=');

        if ($sourcePaths === []) {
            $sourcePaths = ['app'];
        }

        if ($excludes === []) {
            $excludes = $this->defaultLaravelExcludes($projectRoot);
        }

        $phpVersion = $this->detectPhpVersion($projectRoot);
        $config = $this->renderProjectConfig($phpVersion, $sourcePaths, ['vendor'], $excludes);

        if (file_put_contents($configPath, $config) === false) {
            $this->line("Unable to write {$configPath}");

            return 1;
        }

        $this->line("Wrote {$configPath}");

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function prepare(array $arguments): int
    {
        $projectRoot = $this->projectRoot($arguments);

        return $this->withProjectLock($projectRoot, function () use ($projectRoot, $arguments): int {
            $modelSubstitutions = $this->laravelModelSubstitutions($projectRoot, $arguments);
            $frameworkSubstitutions = $this->laravelFrameworkSubstitutions($projectRoot, $arguments);

            $this->line('Prepared ' . (int) (count($modelSubstitutions) / 2) . ' Laravel model overlays.');
            $this->line('Prepared ' . (int) (count($frameworkSubstitutions) / 2) . ' Laravel framework overlays.');

            return 0;
        });
    }

    /**
     * @param list<string> $arguments
     */
    private function migratePhpStan(array $arguments): int
    {
        $projectRoot = $this->projectRoot($arguments);
        $force = in_array('--force', $arguments, true);
        $configPath = $projectRoot . '/' . self::CONFIG_FILE;

        if (is_file($configPath) && ! $force) {
            $this->line('mago.toml already exists. Use --force to replace it.');

            return 1;
        }

        $phpStanConfig = $this->phpStanConfigPath($projectRoot, $arguments);

        if ($phpStanConfig === null) {
            $this->line('Unable to find phpstan.neon, phpstan.neon.dist, phpstan-ci.neon, or phpstan-parallel.neon.');

            return 1;
        }

        $source = file_get_contents($phpStanConfig);

        if (! is_string($source)) {
            $this->line("Unable to read {$phpStanConfig}.");

            return 1;
        }

        $paths = $this->neonListValue($source, 'paths');

        if ($paths === []) {
            $paths = ['app'];
        }

        $excludes = $this->phpStanExcludePaths($source);
        $config = $this->renderProjectConfig($this->detectPhpVersion($projectRoot), $this->normalizePhpStanPaths($paths), ['vendor'], $this->normalizePhpStanPaths($excludes));

        if (file_put_contents($configPath, $config) === false) {
            $this->line("Unable to write {$configPath}.");

            return 1;
        }

        $level = $this->neonScalarValue($source, 'level');
        $levelFlag = $this->phpStanLevelFlag($level);

        $this->line("Read {$phpStanConfig}");
        $this->line("Wrote {$configPath}");
        $analyzeScript = 'vendor/bin/laramago analyze' . $levelFlag . ' --reporting-format=count';
        $debugScript = 'vendor/bin/laramago analyze' . $levelFlag . ' --reporting-format=short';
        $baselineScript = 'vendor/bin/laramago baseline' . $levelFlag;

        if (in_array('--update-composer', $arguments, true) && $this->updateComposerScripts($projectRoot, $analyzeScript, $debugScript, $baselineScript)) {
            $this->line('Updated composer.json scripts.');
        }

        $this->line('Suggested script: ' . $analyzeScript);

        if ($levelFlag !== '') {
            $this->line('Suggested baseline: ' . $baselineScript);
        } elseif ($level !== null) {
            $this->line("Detected PHPStan level {$level}; use Mago report/fail-level flags or a baseline to choose equivalent strictness.");
        }

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function analyze(array $arguments): int
    {
        $projectRoot = $this->projectRoot($arguments);

        return $this->withProjectLock($projectRoot, function () use ($projectRoot, $arguments): int {
            $mago = $this->findMagoBinary($projectRoot);

            if ($mago === null) {
                $this->line('Unable to find Mago. Install carthage-software/mago or laramago/laramago in this project.');

                return 1;
            }

            $runtimeConfig = $this->prepareRuntimeConfig($projectRoot, $arguments);
            $modelSubstitutions = $this->laravelModelSubstitutions($projectRoot, $arguments);
            $frameworkSubstitutions = $this->laravelFrameworkSubstitutions($projectRoot, $arguments);
            $pragmaSubstitutions = $this->phpStanPragmaSubstitutions($projectRoot, $arguments, $this->substitutionOriginalPaths($modelSubstitutions));
            $substitutions = array_merge($modelSubstitutions, $frameworkSubstitutions, $pragmaSubstitutions);
            $command = [$mago, '--config', $runtimeConfig, 'analyze'];
            $command = array_merge(
                $command,
                $this->defaultAnalyzeFlags($projectRoot, $arguments, $substitutions !== []),
                $substitutions,
                $this->stripLaramagoOptions($arguments),
            );

            return $this->process($command, $projectRoot);
        });
    }

    /**
     * @param list<string> $arguments
     */
    private function baseline(array $arguments): int
    {
        $projectRoot = $this->projectRoot($arguments);

        return $this->withProjectLock($projectRoot, function () use ($projectRoot, $arguments): int {
            $mago = $this->findMagoBinary($projectRoot);

            if ($mago === null) {
                $this->line('Unable to find Mago. Install carthage-software/mago or laramago/laramago in this project.');

                return 1;
            }

            $baselinePath = self::BASELINE_FILE;
            $runtimeConfig = $this->prepareRuntimeConfig($projectRoot, $arguments);
            $command = [
                $mago,
                '--config',
                $runtimeConfig,
                'analyze',
                '--baseline',
                $baselinePath,
                '--generate-baseline',
                '--reporting-format=count',
            ];

            $substitutions = $this->laravelModelSubstitutions($projectRoot, $arguments);
            $allSubstitutions = array_merge(
                $substitutions,
                $this->laravelFrameworkSubstitutions($projectRoot, $arguments),
                $this->phpStanPragmaSubstitutions($projectRoot, $arguments, $this->substitutionOriginalPaths($substitutions)),
            );
            $command = array_merge(
                $command,
                $allSubstitutions,
            );

            if (is_file($projectRoot . '/' . $baselinePath) && ! in_array('--force', $arguments, true)) {
                $command[] = '--backup-baseline';
            }

            $command = array_merge($command, $this->stripLaramagoOptions($arguments));

            $exitCode = $this->process($command, $projectRoot);

            if ($exitCode === 0 && $allSubstitutions !== []) {
                $this->translateBaselinePaths($projectRoot, overlayToOriginal: true, source: self::BASELINE_FILE, target: self::BASELINE_FILE);
            }

            return $exitCode;
        });
    }

    /**
     * @param list<string> $arguments
     */
    private function verifyBaseline(array $arguments): int
    {
        $projectRoot = $this->projectRoot($arguments);

        return $this->withProjectLock($projectRoot, function () use ($projectRoot, $arguments): int {
            $mago = $this->findMagoBinary($projectRoot);

            if ($mago === null) {
                $this->line('Unable to find Mago. Install carthage-software/mago or laramago/laramago in this project.');

                return 1;
            }

            $modelSubstitutions = $this->laravelModelSubstitutions($projectRoot, $arguments);
            $substitutions = array_merge(
                $modelSubstitutions,
                $this->laravelFrameworkSubstitutions($projectRoot, $arguments),
                $this->phpStanPragmaSubstitutions($projectRoot, $arguments, $this->substitutionOriginalPaths($modelSubstitutions)),
            );

            $runtimeConfig = $this->prepareRuntimeConfig($projectRoot, $arguments);

            return $this->process(array_merge([
                $mago,
                '--config',
                $runtimeConfig,
                'analyze',
            ], $this->defaultAnalyzeFlags($projectRoot, $arguments, $modelSubstitutions !== []), [
                '--verify-baseline',
                '--reporting-format=count',
            ], $substitutions, $this->stripLaramagoOptions($arguments)), $projectRoot);
        });
    }

    /**
     * @param list<string> $arguments
     */
    private function clear(array $arguments): int
    {
        $projectRoot = $this->projectRoot($arguments);

        return $this->withProjectLock($projectRoot, function () use ($projectRoot): int {
            $cachePath = $projectRoot . '/' . self::CACHE_DIR;

            if (is_dir($cachePath)) {
                $this->removeDirectory($cachePath);
            }

            $this->line("Cleared {$cachePath}");

            return 0;
        });
    }

    /**
     * @param list<string> $arguments
     */
    private function doctor(array $arguments): int
    {
        $projectRoot = $this->projectRoot($arguments);

        return $this->withProjectLock($projectRoot, function () use ($projectRoot, $arguments): int {
            $failed = false;

            $this->line("Project: {$projectRoot}");

            if ($this->findMagoBinary($projectRoot) === null) {
                $this->line('FAIL Mago binary was not found.');
                $failed = true;
            } else {
                $this->line('OK   Mago binary is available.');
            }

            if (is_file($projectRoot . '/' . self::CONFIG_FILE)) {
                $this->line('OK   mago.toml exists.');
            } else {
                $this->line('FAIL mago.toml is missing. Run `vendor/bin/laramago init`.');
                $failed = true;
            }

            if (is_file($projectRoot . '/' . self::BASELINE_FILE)) {
                $this->line('OK   laramago-analyzer-baseline.toml exists.');
            } else {
                $this->line('OK   No Laramago baseline configured; analysis will run without one.');
            }

            if (! is_file($projectRoot . '/bootstrap/app.php')) {
                $this->line('WARN Laravel bootstrap file was not found; model overlays will be skipped.');

                return $failed ? 1 : 0;
            }

            $this->line('OK   Laravel bootstrap file exists.');

            $runtimeConfig = $this->prepareRuntimeConfig($projectRoot, $arguments);
            $modelSubstitutions = $this->laravelModelSubstitutions($projectRoot, $arguments);
            $frameworkSubstitutions = $this->laravelFrameworkSubstitutions($projectRoot, $arguments);
            $this->line('OK   Prepared Laramago runtime config: ' . $runtimeConfig);
            $this->line('OK   Prepared ' . (int) (count($modelSubstitutions) / 2) . ' Laravel model overlays.');
            $this->line('OK   Prepared ' . (int) (count($frameworkSubstitutions) / 2) . ' Laravel framework overlays.');

            return $failed ? 1 : 0;
        });
    }

    private function help(): int
    {
        $this->line(<<<'HELP'
Laramago

Usage:
  laramago init [--force] [--source=app] [--exclude=path/**]
  laramago migrate-phpstan [--force] [--phpstan-config=phpstan.neon] [--update-composer]
  laramago prepare
  laramago analyze [--phpstan-level=0..10|max] [--no-laravel-model-overlays] [--no-laravel-framework-overlays] [--no-phpstan-pragma-overlays] [mago analyze options] [path ...]
  laramago baseline [--force] [--phpstan-level=0..10|max]
  laramago verify-baseline
  laramago doctor
  laramago count [path ...]
  laramago codes [path ...]
  laramago clear

The analyze command automatically uses laramago-analyzer-baseline.toml, Laravel model overlays, and Laravel framework overlays when available.
HELP);

        return 0;
    }

    private function version(): int
    {
        $this->line('laramago ' . self::VERSION);

        return 0;
    }

    private function unknown(string $command): int
    {
        $this->line("Unknown command: {$command}");
        $this->help();

        return 1;
    }

    /**
     * @param list<string> $arguments
     */
    private function projectRoot(array $arguments): string
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--project=')) {
                $project = substr($argument, strlen('--project='));

                return rtrim((string) realpath($project), '/') ?: rtrim($project, '/');
            }
        }

        return (string) getcwd();
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function optionValues(array $arguments, string $prefix): array
    {
        $values = [];

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, $prefix)) {
                $values[] = substr($argument, strlen($prefix));
            }
        }

        return $values;
    }

    /**
     * @param list<string> $arguments
     */
    private function phpStanConfigPath(string $projectRoot, array $arguments): ?string
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--phpstan-config=')) {
                $path = substr($argument, strlen('--phpstan-config='));
                $absolutePath = str_starts_with($path, '/') ? $path : $projectRoot . '/' . $path;

                return is_file($absolutePath) ? $absolutePath : null;
            }
        }

        foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan-ci.neon', 'phpstan-parallel.neon'] as $candidate) {
            $path = $projectRoot . '/' . $candidate;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function neonListValue(string $source, string $key): array
    {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*:\s*\[([^\]]*)\]/m', $source, $inlineMatches) === 1) {
            preg_match_all('/[\'"]?([^\'",\s]+)[\'"]?/', $inlineMatches[1], $valueMatches);

            return array_values(array_filter(array_map('trim', $valueMatches[1]), static fn (string $value): bool => $value !== ''));
        }

        $lines = preg_split('/\R/', $source);

        if (! is_array($lines)) {
            return [];
        }

        $values = [];
        $inList = false;
        $indent = 0;

        foreach ($lines as $line) {
            if (! $inList && preg_match('/^(\s*)' . preg_quote($key, '/') . '\s*:\s*$/', $line, $matches) === 1) {
                $inList = true;
                $indent = strlen($matches[1]);
                continue;
            }

            if (! $inList) {
                continue;
            }

            if (trim($line) === '') {
                continue;
            }

            $lineIndent = strlen($line) - strlen(ltrim($line));

            if ($lineIndent <= $indent) {
                break;
            }

            if (preg_match('/^\s*-\s*[\'"]?([^\'"#]+)[\'"]?/', $line, $valueMatches) === 1) {
                $values[] = trim($valueMatches[1]);
            }
        }

        return $values;
    }

    private function neonScalarValue(string $source, string $key): ?string
    {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*:\s*[\'"]?([^\'"#\s]+)[\'"]?/m', $source, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private function phpStanLevelFlag(?string $level): string
    {
        if ($level === null) {
            return '';
        }

        $level = strtolower(trim($level));

        if ($level === 'max') {
            return ' --phpstan-level=max';
        }

        if (preg_match('/^(?:[0-9]|10)$/', $level) === 1) {
            return " --phpstan-level={$level}";
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function phpStanExcludePaths(string $source): array
    {
        $flatExcludes = $this->neonListValue($source, 'excludePaths');

        if ($flatExcludes !== []) {
            return $flatExcludes;
        }

        return $this->neonNestedListValues($source, 'excludePaths', ['analyse', 'analyseAndScan']);
    }

    /**
     * @param list<string> $nestedKeys
     * @return list<string>
     */
    private function neonNestedListValues(string $source, string $key, array $nestedKeys): array
    {
        $lines = preg_split('/\R/', $source);

        if (! is_array($lines)) {
            return [];
        }

        $values = [];
        $inParent = false;
        $parentIndent = 0;
        $inNestedList = false;
        $nestedIndent = 0;

        foreach ($lines as $line) {
            if (! $inParent && preg_match('/^(\s*)' . preg_quote($key, '/') . '\s*:\s*$/', $line, $matches) === 1) {
                $inParent = true;
                $parentIndent = strlen($matches[1]);
                continue;
            }

            if (! $inParent) {
                continue;
            }

            if (trim($line) === '') {
                continue;
            }

            $lineIndent = strlen($line) - strlen(ltrim($line));

            if ($lineIndent <= $parentIndent) {
                break;
            }

            if (preg_match('/^(\s*)(' . implode('|', array_map(static fn (string $nestedKey): string => preg_quote($nestedKey, '/'), $nestedKeys)) . ')\s*:\s*$/', $line, $nestedMatches) === 1) {
                $inNestedList = true;
                $nestedIndent = strlen($nestedMatches[1]);
                continue;
            }

            if (! $inNestedList) {
                continue;
            }

            if ($lineIndent <= $nestedIndent) {
                $inNestedList = false;
                continue;
            }

            if (preg_match('/^\s*-\s*[\'"]?([^\'"#]+)[\'"]?/', $line, $valueMatches) === 1) {
                $values[] = trim($valueMatches[1]);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function normalizePhpStanPaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            $path = trim($path);

            if ($path === '' || str_starts_with($path, '%')) {
                continue;
            }

            $path = preg_replace('#^\./#', '', $path) ?? $path;
            $path = rtrim($path, '/');

            if ($path === 'vendor' || str_starts_with($path, 'vendor/') || $path === 'storage' || str_starts_with($path, 'storage/') || $path === 'database' || str_starts_with($path, 'database/')) {
                continue;
            }

            if (str_ends_with($path, '/*')) {
                $path = substr($path, 0, -2) . '/**';
            }

            $normalized[] = $path;
        }

        return array_values(array_unique($normalized));
    }

    private function updateComposerScripts(string $projectRoot, string $analyzeScript, string $debugScript, string $baselineScript): bool
    {
        $composerPath = $projectRoot . '/composer.json';

        if (! is_file($composerPath)) {
            return false;
        }

        $source = file_get_contents($composerPath);

        if (! is_string($source)) {
            return false;
        }

        try {
            $composer = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (! is_array($composer)) {
            return false;
        }

        $scripts = $composer['scripts'] ?? [];

        if (! is_array($scripts)) {
            $scripts = [];
        }

        $scripts = $this->replacePhpStanComposerScriptCommands($scripts, $analyzeScript, $debugScript, $baselineScript);
        $scripts['phpstan'] = [$analyzeScript];
        $scripts['phpstan:ci'] = [$analyzeScript];
        $scripts['phpstan:ci:debug'] = [$debugScript];
        $scripts['laramago:baseline'] = [$baselineScript];
        $composer['scripts'] = $scripts;

        $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            return false;
        }

        return file_put_contents($composerPath, $encoded . PHP_EOL) !== false;
    }

    /**
     * @param array<array-key, mixed> $scripts
     * @return array<array-key, mixed>
     */
    private function replacePhpStanComposerScriptCommands(array $scripts, string $analyzeScript, string $debugScript, string $baselineScript): array
    {
        foreach ($scripts as $name => $script) {
            $scriptName = is_string($name) ? $name : '';
            $replacement = $this->laramagoScriptForComposerScript($scriptName, $analyzeScript, $debugScript, $baselineScript);

            if (is_string($script)) {
                $scripts[$name] = $this->replacePhpStanComposerCommand($script, $replacement, $baselineScript);
                continue;
            }

            if (! is_array($script)) {
                continue;
            }

            foreach ($script as $index => $command) {
                if (is_string($command)) {
                    $script[$index] = $this->replacePhpStanComposerCommand($command, $replacement, $baselineScript);
                }
            }

            $scripts[$name] = $script;
        }

        return $scripts;
    }

    private function laramagoScriptForComposerScript(string $scriptName, string $analyzeScript, string $debugScript, string $baselineScript): string
    {
        $scriptName = strtolower($scriptName);

        if (str_contains($scriptName, 'baseline')) {
            return $baselineScript;
        }

        if (str_contains($scriptName, 'debug')) {
            return $debugScript;
        }

        return $analyzeScript;
    }

    private function replacePhpStanComposerCommand(string $command, string $replacement, string $baselineScript): string
    {
        $trimmed = ltrim($command);

        if ($trimmed === '' || str_starts_with($trimmed, '@')) {
            return $command;
        }

        if (! $this->isPhpStanAnalyzeCommand($command)) {
            return $command;
        }

        return str_contains($command, '--generate-baseline') ? $baselineScript : $replacement;
    }

    private function isPhpStanAnalyzeCommand(string $command): bool
    {
        return preg_match(
            '/(?:^|\s)(?:\.\/)?(?:vendor\/bin\/)?phpstan(?:\.phar)?(?:\s+(?:(?:--?[A-Za-z0-9_-]+)(?:=\S+)?|\S+))*\s+(?:analyse|analyze)(?:\s|$)/',
            $command,
        ) === 1;
    }

    /**
     * @return list<string>
     */
    private function defaultLaravelExcludes(string $projectRoot): array
    {
        return [];
    }

    private function detectPhpVersion(string $projectRoot): string
    {
        $composerPath = $projectRoot . '/composer.json';

        if (! is_file($composerPath)) {
            return '8.4.0';
        }

        $composer = json_decode((string) file_get_contents($composerPath), true);
        $constraint = is_array($composer) ? ($composer['require']['php'] ?? null) : null;

        if (! is_string($constraint)) {
            return '8.4.0';
        }

        if (preg_match('/8\.(\d+)/', $constraint, $matches) === 1) {
            return '8.' . $matches[1] . '.0';
        }

        return '8.4.0';
    }

    /**
     * @param list<string> $sourcePaths
     * @param list<string> $includes
     * @param list<string> $excludes
     */
    private function renderProjectConfig(string $phpVersion, array $sourcePaths, array $includes, array $excludes): string
    {
        $paths = $this->tomlArray($sourcePaths);
        $includesValue = $this->tomlArray($includes);
        $excludesValue = $this->tomlArray($excludes);

        return <<<TOML
version = "1"
php-version = "{$phpVersion}"

[source]
workspace = "."
paths = {$paths}
includes = {$includesValue}
excludes = {$excludesValue}

[source.glob]
literal-separator = true
TOML;
    }

    /**
     * @param list<string> $arguments
     */
    private function prepareRuntimeConfig(string $projectRoot, array $arguments = []): string
    {
        $values = $this->projectConfigValues($projectRoot);
        $runtimeConfigPath = $projectRoot . '/' . self::RUNTIME_CONFIG_FILE;
        $includes = $values['includes'];
        $includes = array_values(array_unique(array_merge(
            $includes,
            $this->composerAutoloadIncludes($projectRoot, $values['paths'], $includes),
        )));
        $excludedSymbolInclude = $this->prepareExcludedSymbolStubs($projectRoot, $values['excludes']);
        $laravelSymbolInclude = $this->prepareLaravelSymbolStubs($projectRoot);

        if ($excludedSymbolInclude !== null) {
            $includes[] = $excludedSymbolInclude;
        }

        if ($laravelSymbolInclude !== null) {
            $includes[] = $laravelSymbolInclude;
        }

        $includes = array_values(array_unique($includes));

        $this->ensureDirectory(dirname($runtimeConfigPath));
        file_put_contents($runtimeConfigPath, $this->renderRuntimeConfig(
            $values['phpVersion'],
            $values['paths'],
            $includes,
            $values['excludes'],
            $arguments,
        ));

        return self::RUNTIME_CONFIG_FILE;
    }

    /**
     * @return array{phpVersion: string, paths: list<string>, includes: list<string>, excludes: list<string>}
     */
    private function projectConfigValues(string $projectRoot): array
    {
        $values = [
            'phpVersion' => $this->detectPhpVersion($projectRoot),
            'paths' => ['app'],
            'includes' => ['vendor'],
            'excludes' => $this->defaultLaravelExcludes($projectRoot),
        ];

        $configPath = $projectRoot . '/' . self::CONFIG_FILE;

        if (! is_file($configPath)) {
            return $values;
        }

        $config = file_get_contents($configPath);

        if (! is_string($config)) {
            return $values;
        }

        $phpVersion = $this->tomlStringValue($config, 'php-version');

        if ($phpVersion !== null) {
            $values['phpVersion'] = $phpVersion;
        }

        foreach (['paths', 'includes', 'excludes'] as $key) {
            $arrayValue = $this->tomlArrayValue($config, $key);

            if ($arrayValue !== null) {
                $values[$key] = $arrayValue;
            }
        }

        return $values;
    }

    /**
     * @param list<string> $sourcePaths
     * @param list<string> $configuredIncludes
     * @return list<string>
     */
    private function composerAutoloadIncludes(string $projectRoot, array $sourcePaths, array $configuredIncludes): array
    {
        $composerPath = $projectRoot . '/composer.json';

        if (! is_file($composerPath)) {
            return [];
        }

        try {
            $composer = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($composer)) {
            return [];
        }

        $paths = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload = $composer[$section] ?? null;

            if (! is_array($autoload)) {
                continue;
            }

            $psr4 = $autoload['psr-4'] ?? null;

            if (is_array($psr4)) {
                foreach ($psr4 as $path) {
                    foreach ($this->composerAutoloadPathValues($path) as $autoloadPath) {
                        $paths[] = $autoloadPath;
                    }
                }
            }

            $classmap = $autoload['classmap'] ?? null;

            if (is_array($classmap)) {
                foreach ($classmap as $path) {
                    foreach ($this->composerAutoloadPathValues($path) as $autoloadPath) {
                        $paths[] = $autoloadPath;
                    }
                }
            }
        }

        $paths = array_values(array_unique(array_filter(
            array_map($this->normalizeProjectPath(...), $paths),
            fn (string $path): bool => $this->shouldIncludeComposerAutoloadPath($projectRoot, $path, $sourcePaths, $configuredIncludes),
        )));

        sort($paths);

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function composerAutoloadPathValues(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    private function normalizeProjectPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('#^\./#', '', $path) ?? $path;

        return rtrim($path, '/');
    }

    /**
     * @param list<string> $sourcePaths
     * @param list<string> $configuredIncludes
     */
    private function shouldIncludeComposerAutoloadPath(string $projectRoot, string $path, array $sourcePaths, array $configuredIncludes): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '../')) {
            return false;
        }

        if (! is_dir($projectRoot . '/' . $path)) {
            return false;
        }

        foreach (array_merge($sourcePaths, $configuredIncludes) as $existingPath) {
            $existingPath = $this->normalizeProjectPath($existingPath);

            if ($path === $existingPath || str_starts_with($path . '/', $existingPath . '/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $sourcePaths
     * @param list<string> $includes
     * @param list<string> $excludes
     * @param list<string> $arguments
     */
    private function renderRuntimeConfig(string $phpVersion, array $sourcePaths, array $includes, array $excludes, array $arguments = []): string
    {
        $paths = $this->tomlArray($sourcePaths);
        $includesValue = $this->tomlArray($includes);
        $excludesValue = $this->tomlArray($excludes);
        $ignoreBlock = $this->renderAnalyzerIgnoreBlock($this->runtimeAnalyzerIgnores($arguments));
        $findUnusedDefinitions = $this->usesPhpStanCompatibilityProfile($arguments) ? 'false' : 'true';

        return <<<TOML
version = "1"
php-version = "{$phpVersion}"

[source]
workspace = "."
paths = {$paths}
includes = {$includesValue}
excludes = {$excludesValue}

[source.glob]
literal-separator = true

[formatter]
preset = "pint"
space-after-logical-not-unary-prefix-operator = true
space-within-grouping-parenthesis = false
inline-empty-control-braces = true
inline-empty-closure-braces = true
inline-empty-function-braces = true
inline-empty-method-braces = true
inline-empty-constructor-braces = true
inline-empty-classlike-braces = true
inline-empty-anonymous-class-braces = true

[linter]
integrations = ["laravel"]

[linter.rules]
ambiguous-function-call = { enabled = false }
literal-named-argument = { enabled = false }
halstead = { effort-threshold = 7000 }
strict-types = { enabled = false }
no-empty-comment = { preserve-single-line-comments = true }
too-many-methods = { threshold = 20 }
excessive-parameter-list = { threshold = 10 }
no-shorthand-ternary = { enabled = false }
braced-string-interpolation = { enabled = false }
no-isset = { allow-array-checks = true }

[analyzer]
plugins = []
{$ignoreBlock}
find-unused-definitions = {$findUnusedDefinitions}
find-unused-expressions = false
analyze-dead-code = false
memoize-properties = true
allow-possibly-undefined-array-keys = true
check-throws = false
check-missing-override = false
find-unused-parameters = false
strict-list-index-checks = false
no-boolean-literal-comparison = false
check-missing-type-hints = false
register-super-globals = true
TOML;
    }

    /**
     * @param list<string> $arguments
     * @return list<string|array{code: string, in: string}>
     */
    private function runtimeAnalyzerIgnores(array $arguments): array
    {
        return array_merge($this->phpStanCompatibilityIgnores($arguments), [
            [
                'code' => 'unused-pragma',
                'in' => self::MODEL_OVERLAY_DIR . '/',
            ],
            [
                'code' => 'unused-pragma',
                'in' => self::PHPSTAN_PRAGMA_OVERLAY_DIR . '/',
            ],
            [
                'code' => 'possibly-non-existent-method',
                'in' => self::PHPSTAN_PRAGMA_OVERLAY_DIR . '/',
            ],
            [
                'code' => 'missing-return-statement',
                'in' => self::EXCLUDED_SYMBOL_DIR . '/',
            ],
            [
                'code' => 'too-few-arguments',
                'in' => self::FRAMEWORK_OVERLAY_DIR . '/',
            ],
            [
                'code' => 'too-many-arguments',
                'in' => self::FRAMEWORK_OVERLAY_DIR . '/',
            ],
            [
                'code' => 'missing-template-parameter',
                'in' => self::FRAMEWORK_OVERLAY_DIR . '/',
            ],
            [
                'code' => 'invalid-template-parameter',
                'in' => self::FRAMEWORK_OVERLAY_DIR . '/',
            ],
            [
                'code' => 'ambiguous-class-like-constant-access',
                'in' => self::FRAMEWORK_OVERLAY_DIR . '/',
            ],
            [
                'code' => 'possibly-static-access-on-interface',
                'in' => self::FRAMEWORK_OVERLAY_DIR . '/',
            ],
            [
                'code' => 'invalid-param-tag',
                'in' => self::FRAMEWORK_OVERLAY_DIR . '/',
            ],
            [
                'code' => 'deprecated-method',
                'in' => self::FRAMEWORK_OVERLAY_DIR . '/',
            ],
            [
                'code' => 'unimplemented-abstract-method',
                'in' => self::FRAMEWORK_OVERLAY_DIR . '/',
            ],
        ]);
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function phpStanCompatibilityIgnores(array $arguments): array
    {
        $level = $this->phpStanCompatibilityLevel($arguments);

        if ($level === null || $level >= 9) {
            return [];
        }

        $ignores = [
            'mixed-argument',
            'mixed-assignment',
            'mixed-array-access',
            'mixed-array-assignment',
            'undefined-variable',
            'undefined-int-array-index',
            'undefined-string-array-index',
            'invalid-argument',
            'invalid-array-access',
            'invalid-array-element',
            'invalid-array-index',
            'invalid-destructuring-source',
            'null-array-index',
            'null-argument',
            'null-operand',
            'possibly-invalid-argument',
            'possibly-invalid-array-access',
            'possibly-false-argument',
            'possibly-false-array-access',
            'possibly-false-iterator',
            'possibly-false-operand',
            'invalid-array-element-key',
            'invalid-operand',
            'invalid-callable',
            'invalid-iterator',
            'invalid-member-selector',
            'invalid-method-access',
            'invalid-property-assignment-value',
            'invalid-pass-by-reference',
            'invalid-property-access',
            'invalid-property-read',
            'dynamic-static-method-call',
            'docblock-type-mismatch',
            'impossible-assignment',
            'impossible-condition',
            'impossible-null-type-comparison',
            'impossible-type-comparison',
            'incompatible-return-type',
            'less-specific-return-statement',
            'less-specific-argument',
            'less-specific-nested-argument-type',
            'less-specific-nested-return-statement',
            'array-to-string-conversion',
            'implicit-to-string-cast',
            'incompatible-parameter-name',
            'mixed-return-statement',
            'mixed-array-index',
            'mixed-clone',
            'mismatched-array-index',
            'reference-to-undefined-variable',
            'non-documented-method',
            'non-documented-property',
            'non-iterable-object-iteration',
            'mixed-property-type-coercion',
            'property-type-coercion',
            'invalid-property-write',
            'non-existent-property',
            'ambiguous-object-property-access',
            'mixed-operand',
            'mixed-property-access',
            'mixed-method-access',
            'non-existent-method',
            'non-existent-class-constant',
            'invalid-type-cast',
            'redundant-cast',
            'redundant-comparison',
            'redundant-condition',
            'redundant-docblock-type',
            'redundant-isset-check',
            'redundant-logical-operation',
            'redundant-null-coalesce',
            'redundant-type-comparison',
            'string-constant-selector',
            'string-member-selector',
            'unsafe-instantiation',
            'unknown-class-instantiation',
            'unknown-member-selector-type',
            'ambiguous-instantiation-target',
            'nullable-return-statement',
            'possibly-null-property-access',
            'possibly-null-array-access',
            'possibly-null-array-index',
            'possibly-null-iterator',
            'possibly-null-operand',
            'possibly-invalid-operand',
            'possibly-undefined-variable',
            'possibly-undefined-string-array-index',
            'invalid-return-statement',
            'possibly-null-argument',
            'ambiguous-object-method-access',
            'possible-method-access-on-null',
            'no-value',
            'falsable-return-statement',
            'false-operand',
            'missing-return-statement',
            'never-return',
            'match-not-exhaustive',
            'reference-reused-from-confusing-scope',
            'unreachable-else-clause',
            'template-constraint-violation',
            'too-many-arguments',
        ];

        if ($level <= 7) {
            return $ignores;
        }

        $levelEightReportedCodes = [
            'falsable-return-statement',
            'false-operand',
            'invalid-return-statement',
            'null-argument',
            'null-array-index',
            'null-operand',
            'nullable-return-statement',
            'possible-method-access-on-null',
            'possibly-false-argument',
            'possibly-false-array-access',
            'possibly-false-iterator',
            'possibly-false-operand',
            'possibly-invalid-operand',
            'possibly-null-argument',
            'possibly-null-array-access',
            'possibly-null-array-index',
            'possibly-null-iterator',
            'possibly-null-operand',
            'possibly-null-property-access',
        ];

        return array_values(array_diff($ignores, $levelEightReportedCodes));
    }

    /**
     * @param list<string> $arguments
     */
    private function usesPhpStanCompatibilityProfile(array $arguments): bool
    {
        return $this->phpStanCompatibilityLevel($arguments) !== null;
    }

    /**
     * @param list<string> $arguments
     */
    private function phpStanCompatibilityLevel(array $arguments): ?int
    {
        foreach ($arguments as $argument) {
            if (! str_starts_with($argument, '--phpstan-level=')) {
                continue;
            }

            $level = strtolower(substr($argument, strlen('--phpstan-level=')));

            if ($level === 'max') {
                return 10;
            }

            if (preg_match('/^(?:[0-9]|10)$/', $level) === 1) {
                return (int) $level;
            }
        }

        return null;
    }

    /**
     * @param list<string|array{code: string, in: string}> $codes
     */
    private function renderAnalyzerIgnoreBlock(array $codes): string
    {
        if ($codes === []) {
            return '';
        }

        return 'ignore = [' . PHP_EOL
            . implode(PHP_EOL, array_map(static function (string|array $ignore): string {
                if (is_string($ignore)) {
                    return '  "' . $ignore . '",';
                }

                return '  { code = "' . $ignore['code'] . '", in = "' . $ignore['in'] . '" },';
            }, $codes))
            . PHP_EOL
            . ']';
    }

    private function tomlStringValue(string $config, string $key): ?string
    {
        if (preg_match('/^' . preg_quote($key, '/') . '\s*=\s*"([^"]*)"/m', $config, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @return list<string>|null
     */
    private function tomlArrayValue(string $config, string $key): ?array
    {
        if (preg_match('/^' . preg_quote($key, '/') . '\s*=\s*\[([^\]]*)\]/m', $config, $matches) !== 1) {
            return null;
        }

        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $matches[1], $stringMatches);

        return array_map(
            static fn (string $value): string => str_replace('\"', '"', $value),
            $stringMatches[1],
        );
    }

    /**
     * @param list<string> $values
     */
    private function tomlArray(array $values): string
    {
        if ($values === []) {
            return '[]';
        }

        return '[' . implode(', ', array_map(
            static fn (string $value): string => '"' . str_replace('"', '\"', $value) . '"',
            $values
        )) . ']';
    }

    private function findMagoBinary(string $projectRoot): ?string
    {
        $candidates = array_merge($this->magoNativeBinaryCandidates($projectRoot), [
            $projectRoot . '/vendor/bin/mago',
            ...$this->magoNativeBinaryCandidates(dirname(__DIR__)),
            dirname(__DIR__) . '/vendor/bin/mago',
            dirname(__DIR__, 3) . '/bin/mago',
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function magoNativeBinaryCandidates(string $root): array
    {
        $pattern = $root . '/vendor/carthage-software/mago/composer/bin/*/mago-*/mago*';
        $matches = glob($pattern);

        if ($matches === false) {
            return [];
        }

        rsort($matches);

        return array_values(array_filter($matches, static fn (string $path): bool => is_file($path)));
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function laravelModelSubstitutions(string $projectRoot, array $arguments): array
    {
        if (in_array('--no-laravel-model-overlays', $arguments, true)) {
            return [];
        }

        if (! is_file($projectRoot . '/bootstrap/app.php')) {
            return [];
        }

        $classes = $this->discoverProjectClasses($projectRoot);

        if ($classes === []) {
            return [];
        }

        $this->ensureDirectory($projectRoot . '/' . self::MODEL_OVERLAY_DIR);
        $classesPath = $projectRoot . '/' . self::CACHE_DIR . '/model-classes.json';
        file_put_contents($classesPath, json_encode($classes, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $metadata = $this->capture([
            PHP_BINARY,
            dirname(__DIR__) . '/resources/laravel-model-metadata.php',
            $projectRoot,
            $classesPath,
        ], $projectRoot);

        if ($metadata['exitCode'] !== 0) {
            $this->error('Laramago could not inspect Laravel model metadata; continuing without model overlays.');

            if ($metadata['stderr'] !== '') {
                $this->error(trim($metadata['stderr']));
            }

            return [];
        }

        try {
            $models = json_decode($metadata['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->error('Laramago received invalid Laravel model metadata: ' . $exception->getMessage());

            return [];
        }

        if (! is_array($models)) {
            return [];
        }

        $substitutions = [];
        $pathMap = [];

        foreach ($models as $model) {
            if (! is_array($model)) {
                continue;
            }

            $file = $model['file'] ?? null;
            $class = $model['shortClass'] ?? null;
            $properties = $model['properties'] ?? null;
            $accessors = $model['accessors'] ?? [];
            $relations = $model['relations'] ?? null;
            $scopes = $model['scopes'] ?? [];
            $usesSanctumApiTokens = $model['usesSanctumApiTokens'] ?? false;

            if (! is_string($file) || ! is_string($class) || ! is_array($properties) || ! is_array($accessors) || ! is_array($relations) || ! is_array($scopes) || ! is_bool($usesSanctumApiTokens)) {
                continue;
            }

            $sourcePath = $projectRoot . '/' . $file;

            if (! is_file($sourcePath)) {
                continue;
            }

            $source = file_get_contents($sourcePath);

            if (! is_string($source)) {
                continue;
            }

            $overlayRelativePath = self::MODEL_OVERLAY_DIR . '/' . sha1($file) . '.php';
            $overlay = $this->translatePhpStanPragmas($this->insertModelDocblock($source, $class, $properties, $accessors, $relations, $scopes, $usesSanctumApiTokens));
            file_put_contents($projectRoot . '/' . $overlayRelativePath, $overlay);

            $pathMap[] = [
                'original' => $file,
                'overlay' => $overlayRelativePath,
            ];

            $substitutions[] = '--substitute';
            $substitutions[] = $sourcePath . '=' . $projectRoot . '/' . $overlayRelativePath;
        }

        file_put_contents($projectRoot . '/' . self::MODEL_OVERLAY_MAP, json_encode($pathMap, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $substitutions;
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function laravelFrameworkSubstitutions(string $projectRoot, array $arguments): array
    {
        if (in_array('--no-laravel-framework-overlays', $arguments, true)) {
            return [];
        }

        $overlays = [];

        $guardPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Contracts/Auth/Guard.php';
        $authManagerPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Auth/AuthManager.php';
        $authFacadePath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Support/Facades/Auth.php';
        $applicationContractPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Contracts/Foundation/Application.php';
        $httpFacadePath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Support/Facades/Http.php';
        $supportCarbonPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Support/Carbon.php';
        $baseCarbonPath = $projectRoot . '/vendor/nesbot/carbon/src/Carbon/Carbon.php';
        $baseCarbonImmutablePath = $projectRoot . '/vendor/nesbot/carbon/src/Carbon/CarbonImmutable.php';
        $foundationHelpersPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Foundation/helpers.php';
        $eloquentBuilderPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php';
        $eloquentModelPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php';
        $hasAttributesPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php';
        $queryBuilderPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php';
        $controllerMiddlewareOptionsPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Routing/ControllerMiddlewareOptions.php';
        $notificationPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Notifications/Notification.php';
        $shouldBroadcastPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Contracts/Broadcasting/ShouldBroadcast.php';
        $hasFactoryPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/HasFactory.php';
        $scopePath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Scope.php';
        $fromCollectionPath = $projectRoot . '/vendor/maatwebsite/excel/src/Concerns/FromCollection.php';
        $socialiteProviderPath = $projectRoot . '/vendor/laravel/socialite/src/Contracts/Provider.php';
        $socialiteUserPath = $projectRoot . '/vendor/laravel/socialite/src/Two/User.php';

        $authModel = $this->detectAuthUserModel($projectRoot);

        if ($authModel !== null) {
            $authModel = '\\' . ltrim($authModel, '\\');

            if (is_file($guardPath)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Guard.php', $guardPath, $this->renderAuthGuardOverlay($authModel));
            }

            if (is_file($authManagerPath)) {
                $authManagerSource = file_get_contents($authManagerPath);

                if (is_string($authManagerSource)) {
                    $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'AuthManager.php', $authManagerPath, $this->renderAuthManagerOverlay($authManagerSource, $authModel));
                }
            }

            if (is_file($authFacadePath)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Auth.php', $authFacadePath, $this->renderAuthFacadeOverlay($authModel));
            }

            if (is_file($foundationHelpersPath)) {
                $foundationHelpersSource = file_get_contents($foundationHelpersPath);

                if (is_string($foundationHelpersSource)) {
                    $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'FoundationHelpers.php', $foundationHelpersPath, $this->renderFoundationHelpersOverlay($foundationHelpersSource));
                }
            }
        }

        if (is_file($httpFacadePath)) {
            $httpFacadeSource = file_get_contents($httpFacadePath);

            if (is_string($httpFacadeSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Http.php', $httpFacadePath, $this->renderHttpFacadeOverlay($httpFacadeSource));
            }
        }

        if (is_file($applicationContractPath)) {
            $applicationContractSource = file_get_contents($applicationContractPath);

            if (is_string($applicationContractSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'ApplicationContract.php', $applicationContractPath, $this->renderApplicationContractOverlay($applicationContractSource));
            }
        }

        if (is_file($supportCarbonPath)) {
            $supportCarbonSource = file_get_contents($supportCarbonPath);

            if (is_string($supportCarbonSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'SupportCarbon.php', $supportCarbonPath, $this->renderSupportCarbonOverlay($supportCarbonSource));
            }
        }

        if (is_file($baseCarbonPath)) {
            $baseCarbonSource = file_get_contents($baseCarbonPath);

            if (is_string($baseCarbonSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'BaseCarbon.php', $baseCarbonPath, $this->renderCarbonDateOverlay($baseCarbonSource, 'Carbon', '\\Carbon\\Carbon'));
            }
        }

        if (is_file($baseCarbonImmutablePath)) {
            $baseCarbonImmutableSource = file_get_contents($baseCarbonImmutablePath);

            if (is_string($baseCarbonImmutableSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'BaseCarbonImmutable.php', $baseCarbonImmutablePath, $this->renderCarbonDateOverlay($baseCarbonImmutableSource, 'CarbonImmutable', '\\Carbon\\CarbonImmutable'));
            }
        }

        if (is_file($eloquentBuilderPath)) {
            $eloquentBuilderSource = file_get_contents($eloquentBuilderPath);

            if (is_string($eloquentBuilderSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Builder.php', $eloquentBuilderPath, $this->renderEloquentBuilderOverlay($eloquentBuilderSource));
            }
        }

        if (is_file($eloquentModelPath)) {
            $eloquentModelSource = file_get_contents($eloquentModelPath);

            if (is_string($eloquentModelSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'EloquentModel.php', $eloquentModelPath, $this->renderEloquentModelFrameworkOverlay($eloquentModelSource));
            }
        }

        if (is_file($hasAttributesPath)) {
            $hasAttributesSource = file_get_contents($hasAttributesPath);

            if (is_string($hasAttributesSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'HasAttributes.php', $hasAttributesPath, $this->renderHasAttributesOverlay($hasAttributesSource));
            }
        }

        if (is_file($queryBuilderPath)) {
            $queryBuilderSource = file_get_contents($queryBuilderPath);

            if (is_string($queryBuilderSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'QueryBuilder.php', $queryBuilderPath, $this->renderQueryBuilderOverlay($queryBuilderSource));
            }
        }

        if (is_file($controllerMiddlewareOptionsPath)) {
            $controllerMiddlewareOptionsSource = file_get_contents($controllerMiddlewareOptionsPath);

            if (is_string($controllerMiddlewareOptionsSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'ControllerMiddlewareOptions.php', $controllerMiddlewareOptionsPath, $this->renderControllerMiddlewareOptionsOverlay($controllerMiddlewareOptionsSource));
            }
        }

        if (is_file($notificationPath)) {
            $notificationSource = file_get_contents($notificationPath);

            if (is_string($notificationSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Notification.php', $notificationPath, $this->renderNotificationOverlay($notificationSource, $projectRoot, $arguments));
            }
        }

        if (is_file($shouldBroadcastPath)) {
            $shouldBroadcastSource = file_get_contents($shouldBroadcastPath);

            if (is_string($shouldBroadcastSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'ShouldBroadcast.php', $shouldBroadcastPath, $this->renderShouldBroadcastOverlay($shouldBroadcastSource));
            }
        }

        if (is_file($hasFactoryPath)) {
            $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'HasFactory.php', $hasFactoryPath, $this->renderHasFactoryOverlay());
        }

        if (is_file($scopePath)) {
            $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Scope.php', $scopePath, $this->renderScopeOverlay());
        }

        if (is_file($fromCollectionPath)) {
            $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'FromCollection.php', $fromCollectionPath, $this->renderFromCollectionOverlay());
        }

        if (is_file($socialiteProviderPath)) {
            $socialiteProviderSource = file_get_contents($socialiteProviderPath);

            if (is_string($socialiteProviderSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'SocialiteProvider.php', $socialiteProviderPath, $this->renderSocialiteProviderOverlay($socialiteProviderSource));
            }
        }

        if (is_file($socialiteUserPath)) {
            $socialiteUserSource = file_get_contents($socialiteUserPath);

            if (is_string($socialiteUserSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'SocialiteUser.php', $socialiteUserPath, $this->renderSocialiteUserOverlay($socialiteUserSource));
            }
        }

        $substitutions = [];

        foreach ($overlays as $overlay) {
            if ($overlay === null) {
                continue;
            }

            $substitutions[] = '--substitute';
            $substitutions[] = $overlay['original'] . '=' . $overlay['overlay'];
        }

        return $substitutions;
    }

    private function renderEloquentBuilderOverlay(string $source): string
    {
        return $this->insertClassDocblockLines($source, 'Builder', [
            ' * @method $this join(string $table, mixed $first, ?string $operator = null, mixed $second = null, string $type = "inner", bool $where = false)',
            ' * @method $this leftJoin(string $table, mixed $first, ?string $operator = null, mixed $second = null)',
            ' * @method $this rightJoin(string $table, mixed $first, ?string $operator = null, mixed $second = null)',
            ' * @method $this crossJoin(string $table, mixed $first = null, ?string $operator = null, mixed $second = null)',
            ' * @method $this groupBy(array|string ...$groups)',
            ' * @method $this having(string $column, ?string $operator = null, mixed $value = null, string $boolean = "and")',
            ' * @method $this orHaving(string $column, ?string $operator = null, mixed $value = null)',
            ' * @method $this select(mixed ...$columns)',
            ' * @method $this addSelect(array|string ...$columns)',
            ' * @method $this with(array|string ...$relations)',
            ' * @method $this selectRaw(string $expression, array $bindings = [])',
            ' * @method $this selectraw(string $expression, array $bindings = [])',
            ' * @method $this whereLike(string $column, mixed $value, bool $caseSensitive = false, string $boolean = "and", bool $not = false)',
            ' * @method $this whereIntegerInRaw(string $column, mixed $values, string $boolean = "and", bool $not = false)',
            ' * @method $this whereIntegerNotInRaw(string $column, mixed $values, string $boolean = "and")',
            ' * @method $this whereintegerinraw(string $column, mixed $values, string $boolean = "and", bool $not = false)',
            ' * @method $this whereintegernotinraw(string $column, mixed $values, string $boolean = "and")',
            ' * @method $this withoutGlobalScope(mixed $scope)',
            ' * @method $this withoutGlobalScopes(array|null $scopes = null)',
            ' * @method $this withoutglobalscope(mixed $scope)',
            ' * @method $this withoutglobalscopes(array|null $scopes = null)',
            ' * @method $this onlyTrashed()',
            ' * @method $this withTrashed(bool $withTrashed = true)',
            ' * @method $this withoutTrashed()',
            ' * @method $this onlytrashed()',
            ' * @method \Illuminate\Database\Query\Builder toBase()',
            ' * @method \Illuminate\Database\Query\Builder tobase()',
        ]);
    }

    private function renderAuthManagerOverlay(string $source, string $authModel): string
    {
        return $this->insertClassDocblockLines($source, 'AuthManager', [
            ' * @method ' . $authModel . '|null user()',
            ' * @method int|string|null id()',
            ' * @method bool check()',
            ' * @method bool guest()',
        ]);
    }

    private function renderFoundationHelpersOverlay(string $source): string
    {
        return str_replace(
            [
                '@return ($guard is null ? \Illuminate\Contracts\Auth\Factory : \Illuminate\Contracts\Auth\Guard)',
                'function auth($guard = null): AuthFactory|Guard',
                'function now($tz = null): CarbonInterface',
                'function today($tz = null): CarbonInterface',
            ],
            [
                '@return ($guard is null ? \Illuminate\Auth\AuthManager : \Illuminate\Contracts\Auth\Guard)',
                'function auth($guard = null): \Illuminate\Auth\AuthManager|Guard',
                'function now($tz = null): \Illuminate\Support\Carbon',
                'function today($tz = null): \Illuminate\Support\Carbon',
            ],
            $source,
        );
    }

    private function renderHttpFacadeOverlay(string $source): string
    {
        return str_replace(
            [
                '\Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface get(',
                '\Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface head(',
                '\Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface post(',
                '\Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface patch(',
                '\Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface put(',
                '\Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface delete(',
                '\Illuminate\Http\Client\Response|\Illuminate\Http\Client\Promises\LazyPromise send(',
            ],
            [
                '\Illuminate\Http\Client\Response get(',
                '\Illuminate\Http\Client\Response head(',
                '\Illuminate\Http\Client\Response post(',
                '\Illuminate\Http\Client\Response patch(',
                '\Illuminate\Http\Client\Response put(',
                '\Illuminate\Http\Client\Response delete(',
                '\Illuminate\Http\Client\Response send(',
            ],
            $source,
        );
    }

    private function renderApplicationContractOverlay(string $source): string
    {
        if (str_contains($source, 'function isProduction(')) {
            return $source;
        }

        return $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Determine if the application environment is production.
     */
    public function isProduction(): bool;
PHP);
    }

    private function renderSupportCarbonOverlay(string $source): string
    {
        return $this->renderCarbonDateOverlay($source, 'Carbon', '\\Illuminate\\Support\\Carbon');
    }

    private function renderCarbonDateOverlay(string $source, string $className, string $staticReturnType): string
    {
        return $this->insertClassDocblockLines($source, $className, $this->carbonAliasDocblockLines($staticReturnType));
    }

    /**
     * @return list<string>
     */
    private function carbonAliasDocblockLines(string $staticReturnType): array
    {
        return [
            ' * @method static ' . $staticReturnType . ' parse(mixed $time = null, mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' createfromformat(string $format, mixed $time, mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' now(mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' today(mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' tomorrow(mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' yesterday(mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' make(mixed $var, mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' instance(\DateTimeInterface $date)',
            ' * @method static ' . $staticReturnType . ' createfrominterface(\DateTimeInterface $date)',
            ' * @method static ' . $staticReturnType . ' createfromtimestamp(mixed $timestamp, mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' createfromtimestampms(mixed $timestamp, mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' createfromdate(?int $year = null, ?int $month = null, ?int $day = null, mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' createfromtime(?int $hour = null, ?int $minute = null, ?int $second = null, ?int $microsecond = null, mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' create(int $year = 0, int $month = 1, int $day = 1, int $hour = 0, int $minute = 0, int $second = 0, mixed $timezone = null)',
            ' * @method float diffinseconds(mixed $date = null, bool $absolute = false)',
            ' * @method $this addseconds(int|float $value = 1)',
            ' * @method $this addminutes(int|float $value = 1)',
            ' * @method $this addhours(int|float $value = 1)',
            ' * @method $this adddays(int|float $value = 1)',
            ' * @method $this addday()',
            ' * @method $this addmonths(int|float $value = 1)',
            ' * @method $this addmonth()',
            ' * @method $this addmonthnooverflow(int|float $value = 1)',
            ' * @method $this addyears(int|float $value = 1)',
            ' * @method $this addyear()',
            ' * @method $this subseconds(int|float $value = 1)',
            ' * @method $this subminutes(int|float $value = 1)',
            ' * @method $this subhours(int|float $value = 1)',
            ' * @method $this subdays(int|float $value = 1)',
            ' * @method $this subday()',
            ' * @method $this submonths(int|float $value = 1)',
            ' * @method $this submonth()',
            ' * @method $this submonthnooverflow(int|float $value = 1)',
            ' * @method $this subyears(int|float $value = 1)',
            ' * @method $this subyear()',
            ' * @method $this startofday()',
            ' * @method $this endofday()',
            ' * @method $this startofmonth()',
            ' * @method $this endofmonth()',
            ' * @method $this firstofmonth(mixed $dayOfWeek = null)',
            ' * @method $this lastofmonth(mixed $dayOfWeek = null)',
            ' * @method mixed weekday(?int $value = null)',
            ' * @method string todatetimestring(string $unitPrecision = "second")',
            ' * @method string toiso8601string()',
            ' * @method string torfc2822string()',
            ' * @method string gettranslatedmonthname(?string $context = null, ?string $key = null, mixed $locale = null)',
        ];
    }

    private function renderEloquentModelFrameworkOverlay(string $source): string
    {
        $source = str_replace(
            [
                'public function load($relations)',
                'public function loadMissing($relations)',
                'public function loadCount($relations)',
                'protected function increment($column, $amount = 1, array $extra = [])',
                'protected function decrement($column, $amount = 1, array $extra = [])',
            ],
            [
                'public function load($relations, ...$additionalRelations)',
                'public function loadMissing($relations, ...$additionalRelations)',
                'public function loadCount($relations, ...$additionalRelations)',
                'public function increment($column, $amount = 1, array $extra = [])',
                'public function decrement($column, $amount = 1, array $extra = [])',
            ],
            $source,
        );

        return $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     */
    public static function create(array $attributes = []): static
    {
        return new static;
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     */
    public static function withoutGlobalScope(mixed $scope): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     */
    public static function withoutGlobalScopes(?array $scopes = null): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     */
    public static function where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     */
    public static function orWhere(mixed $column, mixed $operator = null, mixed $value = null): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     */
    public static function select(mixed ...$columns): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     */
    public static function selectRaw(string $expression, array $bindings = []): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     */
    public static function orderBy(mixed $column, mixed $direction = 'asc'): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     */
    public static function find(mixed $id, array|string $columns = ['*']): mixed
    {
        return null;
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     */
    public static function findOrFail(mixed $id, array|string $columns = ['*']): mixed
    {
        return new static;
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     */
    public static function firstOrCreate(array $attributes = [], array $values = []): static
    {
        return new static;
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     */
    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        return new static;
    }

PHP);
    }

    private function insertBeforeFinalClassBrace(string $source, string $code): string
    {
        $position = strrpos($source, '}');

        if ($position === false) {
            return $source;
        }

        return substr($source, 0, $position) . $code . PHP_EOL . substr($source, $position);
    }

    private function renderHasAttributesOverlay(string $source): string
    {
        return str_replace(
            [
                'public function only($attributes)',
                'public function except($attributes)',
            ],
            [
                'public function only($attributes, ...$additionalAttributes)',
                'public function except($attributes, ...$additionalAttributes)',
            ],
            $source,
        );
    }

    private function renderQueryBuilderOverlay(string $source): string
    {
        $source = str_replace(
            [
                'public function select($columns = [\'*\'])',
                'public function addSelect($column)',
                'public function distinct()',
                '@param  SortDirection|\'asc\'|\'desc\'  $direction',
            ],
            [
                'public function select($columns = [\'*\'], ...$additionalColumns)',
                'public function addSelect($column, ...$additionalColumns)',
                'public function distinct(...$columns)',
                '@param  SortDirection|string  $direction',
            ],
            $source,
        );

        return $this->insertClassDocblockLines($source, 'Builder', [
            ' * @method $this selectRaw(string $expression, array $bindings = [])',
            ' * @method $this selectraw(string $expression, array $bindings = [])',
            ' * @method $this whereLike(string $column, mixed $value, bool $caseSensitive = false, string $boolean = "and", bool $not = false)',
            ' * @method $this whereIntegerInRaw(string $column, mixed $values, string $boolean = "and", bool $not = false)',
            ' * @method $this whereIntegerNotInRaw(string $column, mixed $values, string $boolean = "and")',
            ' * @method $this whereintegerinraw(string $column, mixed $values, string $boolean = "and", bool $not = false)',
            ' * @method $this whereintegernotinraw(string $column, mixed $values, string $boolean = "and")',
            ' * @method $this withoutGlobalScope(mixed $scope)',
            ' * @method $this withoutGlobalScopes(array|null $scopes = null)',
            ' * @method $this withoutglobalscope(mixed $scope)',
            ' * @method $this withoutglobalscopes(array|null $scopes = null)',
            ' * @method \Illuminate\Database\Query\Builder toBase()',
            ' * @method \Illuminate\Database\Query\Builder tobase()',
        ]);
    }

    private function renderControllerMiddlewareOptionsOverlay(string $source): string
    {
        return str_replace(
            [
                'public function only($methods)',
                'public function except($methods)',
            ],
            [
                'public function only($methods, ...$additionalMethods)',
                'public function except($methods, ...$additionalMethods)',
            ],
            $source,
        );
    }

    /**
     * @param list<string> $arguments
     */
    private function renderNotificationOverlay(string $source, string $projectRoot, array $arguments): string
    {
        $members = $this->notificationDynamicMembers($projectRoot, $arguments);
        $declarations = [];

        foreach ($members['methods'] as $method) {
            if (! preg_match('/^\s*public\s+function\s+' . preg_quote($method, '/') . '\s*\(/im', $source)) {
                $declarations[] = '    public function ' . $method . '(mixed ...$arguments): mixed {}';
            }
        }

        if ($members['properties'] !== [] && ! str_contains($source, 'function __get(')) {
            $declarations[] = <<<'PHP'
    public function __get(string $key): mixed
    {
        return null;
    }
PHP;
        }

        if ($declarations === []) {
            return $source;
        }

        return $this->insertBeforeFinalClassBrace($source, PHP_EOL . implode(PHP_EOL . PHP_EOL, $declarations) . PHP_EOL);
    }

    private function renderShouldBroadcastOverlay(string $source): string
    {
        return str_replace(
            '@return \Illuminate\Broadcasting\Channel|\Illuminate\Broadcasting\Channel[]|string[]|string',
            '@return mixed',
            $source,
        );
    }

    /**
     * @param list<string> $arguments
     * @return array{methods: list<string>, properties: list<string>}
     */
    private function notificationDynamicMembers(string $projectRoot, array $arguments): array
    {
        $methods = [];
        $properties = [];
        $seenFiles = [];
        $config = $this->projectConfigValues($projectRoot);

        foreach ($this->sourceOverlayPaths($projectRoot, $arguments, $config['paths']) as $path) {
            foreach ($this->sourcePhpFiles($projectRoot, $path) as $file) {
                if (isset($seenFiles[$file])) {
                    continue;
                }

                $seenFiles[$file] = true;
                $relativePath = ltrim(substr($file, strlen($projectRoot)), '/');

                if ($this->isExcludedProjectPath($relativePath, $config['excludes'])) {
                    continue;
                }

                $source = file_get_contents($file);

                if (! is_string($source)) {
                    continue;
                }

                if (preg_match_all('/\$notification\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches) !== false) {
                    foreach ($matches[1] ?? [] as $method) {
                        $methods[strtolower($method)] = true;
                    }
                }

                if (preg_match_all('/\$notification\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\b(?!\s*\()/', $source, $matches) !== false) {
                    foreach ($matches[1] ?? [] as $property) {
                        $properties[$property] = true;
                    }
                }
            }
        }

        $methodNames = array_keys($methods);
        $propertyNames = array_keys($properties);
        sort($methodNames);
        sort($propertyNames);

        return [
            'methods' => $methodNames,
            'properties' => $propertyNames,
        ];
    }

    private function renderSocialiteProviderOverlay(string $source): string
    {
        $source = str_replace(
            '@return \Laravel\Socialite\Contracts\User',
            '@return \Laravel\Socialite\Contracts\User|null',
            $source,
        );

        if (! str_contains($source, 'function with(')) {
            $source = $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Set request parameters for the provider.
     */
    public function with(array $parameters): static;
PHP);
        }

        if (! str_contains($source, 'function scopes(')) {
            $source = $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Set scopes for the provider.
     */
    public function scopes(array $scopes): static;
PHP);
        }

        if (! str_contains($source, 'function stateless(')) {
            $source = $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Indicate that the provider should operate statelessly.
     */
    public function stateless(): static;
PHP);
        }

        return $source;
    }

    private function renderSocialiteUserOverlay(string $source): string
    {
        if (str_contains($source, 'function setAccessTokenResponseBody(')) {
            return $source;
        }

        return $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Store the raw access token response body used by SocialiteProviders.
     */
    public function setAccessTokenResponseBody(array $body): static
    {
        return $this;
    }
PHP);
    }

    private function renderScopeOverlay(): string
    {
        return <<<'PHP'
<?php

namespace Illuminate\Database\Eloquent;

interface Scope
{
    public function apply(Builder $builder, Model $model);
}
PHP;
    }

    private function renderFromCollectionOverlay(): string
    {
        return <<<'PHP'
<?php

namespace Maatwebsite\Excel\Concerns;

use Illuminate\Support\Enumerable;

interface FromCollection
{
    public function collection(): Enumerable;
}
PHP;
    }

    private function renderHasFactoryOverlay(): string
    {
        return <<<'PHP'
<?php

namespace Illuminate\Database\Eloquent\Factories;

trait HasFactory
{
    /**
     * @param (callable(array<string, mixed>, static|null): array<string, mixed>)|array<string, mixed>|int|null $count
     * @param (callable(array<string, mixed>, static|null): array<string, mixed>)|array<string, mixed> $state
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>
     */
    public static function factory($count = null, $state = [])
    {
        throw new \LogicException('Laramago analysis overlay.');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>|null
     */
    protected static function newFactory()
    {
        return null;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>|null
     */
    protected static function getUseFactoryAttribute()
    {
        return null;
    }
}
PHP;
    }

    private function renderAuthGuardOverlay(string $authModel): string
    {
        return <<<PHP
<?php

namespace Illuminate\Contracts\Auth;

interface Guard
{
    public function check();

    public function guest();

    /**
     * @return {$authModel}|null
     */
    public function user();

    /**
     * @return int|string|null
     */
    public function id();

    public function validate(array \$credentials = []);

    public function hasUser();

    public function setUser(Authenticatable \$user);
}
PHP;
    }

    private function renderAuthFacadeOverlay(string $authModel): string
    {
        return <<<PHP
<?php

namespace Illuminate\Support\Facades;

/**
 * @method static \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard guard(\UnitEnum|string|null \$name = null)
 * @method static bool check()
 * @method static bool guest()
 * @method static {$authModel}|null user()
 * @method static int|string|null id()
 * @method static bool validate(array \$credentials = [])
 * @method static bool hasUser()
 * @method static \Illuminate\Contracts\Auth\Guard setUser(\Illuminate\Contracts\Auth\Authenticatable \$user)
 * @method static bool attempt(array \$credentials = [], bool \$remember = false)
 * @method static bool once(array \$credentials = [])
 * @method static void login(\Illuminate\Contracts\Auth\Authenticatable \$user, bool \$remember = false)
 * @method static {$authModel}|false loginUsingId(mixed \$id, bool \$remember = false)
 * @method static {$authModel}|false onceUsingId(mixed \$id)
 * @method static bool viaRemember()
 * @method static void logout()
 * @method static {$authModel}|null getUser()
 * @method static {$authModel} authenticate()
 * @method static {$authModel}|null getLastAttempted()
 * @method static {$authModel}|null logoutOtherDevices(string \$password)
 *
 * @see \Illuminate\Auth\AuthManager
 * @see \Illuminate\Auth\SessionGuard
 */
class Auth extends Facade
{
}
PHP;
    }

    /**
     * @return array{original: string, overlay: string}|null
     */
    private function writeFrameworkOverlay(string $projectRoot, string $fileName, string $originalPath, string $source): ?array
    {
        $overlayPath = $projectRoot . '/' . self::FRAMEWORK_OVERLAY_DIR . '/' . $fileName;
        $this->ensureDirectory(dirname($overlayPath));

        if (file_put_contents($overlayPath, $source) === false) {
            return null;
        }

        return [
            'original' => $originalPath,
            'overlay' => $overlayPath,
        ];
    }

    /**
     * @param list<string> $arguments
     * @param array<string, true> $skippedOriginalPaths
     * @return list<string>
     */
    private function phpStanPragmaSubstitutions(string $projectRoot, array $arguments, array $skippedOriginalPaths = []): array
    {
        if (in_array('--no-phpstan-pragma-overlays', $arguments, true)) {
            return [];
        }

        $overlayDirectory = $projectRoot . '/' . self::PHPSTAN_PRAGMA_OVERLAY_DIR;
        $caseInsensitiveAliasCandidates = $this->caseInsensitiveMethodAliasCandidates($projectRoot);

        if (is_dir($overlayDirectory)) {
            $this->removeDirectory($overlayDirectory);
        }

        $substitutions = [];
        $pathMap = [];
        $seenFiles = [];
        $config = $this->projectConfigValues($projectRoot);
        $excludes = $config['excludes'];

        foreach ($this->sourceOverlayPaths($projectRoot, $arguments, $config['paths']) as $path) {
            foreach ($this->sourcePhpFiles($projectRoot, $path) as $file) {
                if (isset($seenFiles[$file]) || isset($skippedOriginalPaths[$file])) {
                    continue;
                }

                $relativePath = ltrim(substr($file, strlen($projectRoot)), '/');

                if ($this->isExcludedProjectPath($relativePath, $excludes)) {
                    continue;
                }

                $seenFiles[$file] = true;
                $source = file_get_contents($file);

                if (! is_string($source)) {
                    continue;
                }

                $translated = $this->translatePhpStanPragmas($source);
                $translated = $this->translateLaravelDateHelperCalls($translated);
                $translated = $this->annotateLaravelCollectionMacroClosures($translated);
                $translated = $this->rewriteLaravelRequestPropertyReads($translated);
                $translated = $this->annotateLaravelJsonResourceDynamicMembers($translated, $relativePath);
                $translated = $this->annotateLaravelFormRequestDynamicProperties($translated, $relativePath, $projectRoot);
                $minimumAliases = $translated === $source ? 2 : 1;
                $overlay = $this->insertTraitSelfCallMethods($this->insertCaseInsensitiveMethodAliases($translated, $caseInsensitiveAliasCandidates, $minimumAliases));

                if ($overlay === $source) {
                    continue;
                }

                $overlayRelativePath = self::PHPSTAN_PRAGMA_OVERLAY_DIR . '/' . sha1($relativePath) . '.php';
                $this->ensureDirectory($overlayDirectory);
                file_put_contents($projectRoot . '/' . $overlayRelativePath, $overlay);

                $pathMap[] = [
                    'original' => $relativePath,
                    'overlay' => $overlayRelativePath,
                ];

                $substitutions[] = '--substitute';
                $substitutions[] = $file . '=' . $projectRoot . '/' . $overlayRelativePath;
            }
        }

        file_put_contents($projectRoot . '/' . self::PHPSTAN_PRAGMA_OVERLAY_MAP, json_encode($pathMap, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $substitutions;
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $configuredPaths
     * @return list<string>
     */
    private function sourceOverlayPaths(string $projectRoot, array $arguments, array $configuredPaths): array
    {
        $paths = [];

        foreach ($configuredPaths as $path) {
            $paths[$this->normalizeProjectPath($path)] = true;
        }

        foreach ($this->stripLaramagoOptions($arguments) as $argument) {
            if ($argument === '' || str_starts_with($argument, '-')) {
                continue;
            }

            $path = $this->normalizeProjectPath($argument);

            if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '../')) {
                continue;
            }

            if (is_file($projectRoot . '/' . $path) || is_dir($projectRoot . '/' . $path)) {
                $paths[$path] = true;
            }
        }

        return array_values(array_filter(array_keys($paths), static fn (string $path): bool => $path !== ''));
    }

    /**
     * @param list<string> $excludes
     */
    private function isExcludedProjectPath(string $path, array $excludes): bool
    {
        $path = $this->normalizeProjectPath($path);

        foreach ($excludes as $exclude) {
            $exclude = $this->normalizeProjectPath($exclude);

            if ($exclude === '') {
                continue;
            }

            if (str_ends_with($exclude, '/**')) {
                $directory = rtrim(substr($exclude, 0, -3), '/');

                if ($path === $directory || str_starts_with($path, $directory . '/')) {
                    return true;
                }

                continue;
            }

            if ($path === $exclude || str_starts_with($path, rtrim($exclude, '/') . '/')) {
                return true;
            }

            if (fnmatch($exclude, $path, FNM_PATHNAME)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function sourcePhpFiles(string $projectRoot, string $path): array
    {
        $path = $this->normalizeProjectPath($this->baseDirectoryFromGlob($path));

        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '../')) {
            return [];
        }

        $absolutePath = $projectRoot . '/' . $path;

        if (is_file($absolutePath) && str_ends_with($absolutePath, '.php')) {
            return [$absolutePath];
        }

        if (is_dir($absolutePath)) {
            return $this->phpFiles($absolutePath);
        }

        return [];
    }

    private function translatePhpStanPragmas(string $source): string
    {
        $lines = preg_split('/(\R)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($lines)) {
            return $source;
        }

        $translated = '';

        for ($index = 0; $index < count($lines); $index += 2) {
            $line = (string) $lines[$index];
            $lineEnding = (string) ($lines[$index + 1] ?? '');

            if (str_contains($line, '@phpstan-ignore-line')) {
                preg_match('/^\s*/', $line, $matches);
                $translated .= ($matches[0] ?? '') . '// @mago-ignore all' . $lineEnding . $line . $lineEnding;

                continue;
            }

            $line = preg_replace('/@phpstan-ignore-next-line\b[^\r\n*]*/', '@mago-ignore all', $line) ?? $line;
            $line = preg_replace('/@phpstan-ignore\b[^\r\n*]*/', '@mago-ignore all', $line) ?? $line;
            $translated .= $line . $lineEnding;
        }

        return $translated;
    }

    private function translateLaravelDateHelperCalls(string $source): string
    {
        $tokens = token_get_all($source);
        $translated = '';
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (! is_array($token) || $token[0] !== T_STRING || ! in_array(strtolower($token[1]), ['now', 'today', 'response'], true)) {
                $translated .= is_array($token) ? $token[1] : $token;

                continue;
            }

            $helper = strtolower($token[1]);
            $next = $this->nextMeaningfulTokenIndex($tokens, $index + 1);

            if ($next === null || $tokens[$next] !== '(') {
                $translated .= $token[1];

                continue;
            }

            $previous = $this->previousMeaningfulTokenIndex($tokens, $index - 1);
            $previousToken = $previous === null ? null : $tokens[$previous];

            if (is_array($previousToken) && in_array($previousToken[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                $translated .= $token[1];

                continue;
            }

            if ($previousToken === '\\') {
                $translated .= $token[1];

                continue;
            }

            if ($helper === 'response') {
                if (! $this->hasCallArguments($tokens, $next)) {
                    $translated .= $token[1];

                    continue;
                }

                $translated .= '\\Illuminate\\Support\\Facades\\Response::make';

                continue;
            }

            $translated .= '\\Illuminate\\Support\\Carbon::' . $helper;
        }

        return $translated;
    }

    /**
     * @param list<array|string> $tokens
     */
    private function hasCallArguments(array $tokens, int $openParenthesis): bool
    {
        $depth = 0;
        $count = count($tokens);

        for ($index = $openParenthesis; $index < $count; $index++) {
            $token = $tokens[$index];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '(') {
                $depth++;

                continue;
            }

            if ($text === ')') {
                $depth--;

                if ($depth === 0) {
                    return false;
                }

                continue;
            }

            if ($depth === 1 && ! (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                return true;
            }
        }

        return false;
    }

    private function annotateLaravelCollectionMacroClosures(string $source): string
    {
        $translated = preg_replace_callback(
            '/((?:\\\\?Illuminate\\\\Support\\\\)?Collection::macro\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*function\s*\([^)]*\)\s*(?::\s*[^{]+)?\{)(?!\s*\/\*\*\s*@var\s+[^*]*\$this)/m',
            static fn (array $matches): string => $matches[1] . PHP_EOL . '                    /** @var \Illuminate\Support\Collection $this */',
            $source,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function rewriteLaravelRequestPropertyReads(string $source): string
    {
        $lines = preg_split('/(\R)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($lines)) {
            return $source;
        }

        $translated = '';

        for ($index = 0; $index < count($lines); $index += 2) {
            $line = (string) $lines[$index];
            $lineEnding = (string) ($lines[$index + 1] ?? '');

            if (! str_contains($line, 'isset(')) {
                $line = preg_replace_callback(
                    '/\$request\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\b(?!\s*(?:\(|=|\+=|-=|\*=|\/=|%=|\.=|\?\?=|\+\+|--))/',
                    static fn (array $matches): string => '$request->input(\'' . $matches[1] . '\')',
                    $line,
                ) ?? $line;
                $line = preg_replace_callback(
                    '/\$this\s*->\s*request\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\b(?!\s*(?:\(|=|\+=|-=|\*=|\/=|%=|\.=|\?\?=|\+\+|--))/',
                    static fn (array $matches): string => '$this->request->input(\'' . $matches[1] . '\')',
                    $line,
                ) ?? $line;
            }

            $translated .= $line . $lineEnding;
        }

        return $translated;
    }

    private function annotateLaravelJsonResourceDynamicMembers(string $source, string $relativePath): string
    {
        $normalizedPath = str_replace('\\', '/', $relativePath);

        if (! str_contains($normalizedPath, '/Http/Resources/') && ! str_starts_with($normalizedPath, 'app/Http/Resources/')) {
            return $source;
        }

        if (preg_match('/^(?:(?:abstract|final|readonly)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\b/m', $source, $classMatches) !== 1) {
            return $source;
        }

        $parent = strtolower($classMatches[2]);

        if (! str_ends_with($parent, 'jsonresource') && ! str_ends_with($parent, 'resourcecollection')) {
            return $source;
        }

        $declaredProperties = $this->declaredProperties($source);
        $properties = [];

        if (preg_match_all('/\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\b(?!\s*\()/', $source, $propertyMatches) > 0) {
            foreach ($propertyMatches[1] as $property) {
                if (isset($declaredProperties[$property]) || in_array($property, ['resource', 'with', 'additional', 'wrap', 'forceWrapping', 'preserveKeys'], true)) {
                    continue;
                }

                $properties[$property] = true;
            }
        }

        $methods = [];

        if (preg_match_all('/\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $methodMatches) > 0) {
            foreach ($methodMatches[1] as $method) {
                if (in_array($method, ['additional', 'jsonOptions', 'jsonSerialize', 'resolve', 'response', 'toArray', 'toAttributes', 'toJson', 'toPrettyJson', 'toResponse', 'with', 'withResponse'], true)) {
                    continue;
                }

                $methods[$method] = true;
            }
        }

        if ($properties === [] && $methods === []) {
            return $source;
        }

        $lines = [
            ' * @mixin \Illuminate\Database\Eloquent\Model',
        ];

        foreach (array_keys($properties) as $property) {
            $lines[] = ' * @property mixed $' . $property;
        }

        foreach (array_keys($methods) as $method) {
            $lines[] = ' * @method mixed ' . $method . '(mixed ...$parameters)';
        }

        return $this->insertClassDocblockLines($source, $classMatches[1], $lines);
    }

    private function annotateLaravelFormRequestDynamicProperties(string $source, string $relativePath, string $projectRoot): string
    {
        if (! str_contains(str_replace('\\', '/', $relativePath), '/Http/Requests/') && ! str_starts_with(str_replace('\\', '/', $relativePath), 'app/Http/Requests/')) {
            return $source;
        }

        if (preg_match('/^(?:(?:abstract|final|readonly)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\b/m', $source, $classMatches) !== 1) {
            return $source;
        }

        $parent = strtolower($classMatches[2]);

        if (! str_ends_with($parent, 'request')) {
            return $source;
        }

        if (preg_match_all('/\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\b(?!\s*\()/', $source, $matches) === 0) {
            return $source;
        }

        $declaredProperties = $this->declaredProperties($source) + $this->usedTraitDeclaredProperties($projectRoot, $source);
        $properties = [];

        foreach ($matches[1] as $property) {
            if (in_array($property, ['request', 'container', 'redirector'], true)) {
                continue;
            }

            if (isset($declaredProperties[$property])) {
                continue;
            }

            $properties[$property] = true;
        }

        if ($properties === []) {
            return $source;
        }

        $lines = [];

        foreach (array_keys($properties) as $property) {
            $lines[] = ' * @property mixed $' . $property;
        }

        $source = $this->insertClassDocblockLines($source, $classMatches[1], $lines);

        if (! str_contains($source, 'function __set(')) {
            $source = $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Laramago overlay for Laravel FormRequest dynamic input/state properties.
     */
    public function __set(string $key, mixed $value): void
    {
    }
PHP);
        }

        return $source;
    }

    /**
     * @return array<string, true>
     */
    private function usedTraitDeclaredProperties(string $projectRoot, string $source): array
    {
        if (preg_match_all('/^[ \t]+use\s+([^;]+);/m', $source, $matches) === 0) {
            return [];
        }

        $properties = [];

        foreach ($matches[1] as $declaration) {
            foreach (explode(',', $declaration) as $trait) {
                $trait = trim(preg_replace('/\s+as\s+.*$/i', '', $trait) ?? $trait);

                if ($trait === '') {
                    continue;
                }

                $class = str_contains($trait, '\\')
                    ? ltrim($trait, '\\')
                    : ($this->importedClassName($source, $trait) ?? $this->namespacedClassName($source, $trait));

                if ($class === null) {
                    continue;
                }

                $path = $this->projectClassPath($projectRoot, $class);

                if ($path === null || ! is_file($path)) {
                    continue;
                }

                $traitSource = file_get_contents($path);

                if (! is_string($traitSource)) {
                    continue;
                }

                $properties += $this->declaredProperties($traitSource);
            }
        }

        return $properties;
    }

    /**
     * @return array<string, true>
     */
    private function declaredProperties(string $source): array
    {
        if (preg_match_all('/^[ \t]*(?:public|protected|private)\s+(?:readonly\s+)?(?:static\s+)?(?:[^$;\r\n=]+\s+)?\$([A-Za-z_][A-Za-z0-9_]*)/m', $source, $matches) === 0) {
            return [];
        }

        $properties = [];

        foreach ($matches[1] as $property) {
            $properties[$property] = true;
        }

        return $properties;
    }

    private function namespacedClassName(string $source, string $shortName): ?string
    {
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]) . '\\' . $shortName;
    }

    private function projectClassPath(string $projectRoot, string $class): ?string
    {
        if (str_starts_with($class, 'App\\')) {
            return $projectRoot . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        }

        return null;
    }

    /**
     * @param list<string> $substitutions
     * @return array<string, true>
     */
    private function substitutionOriginalPaths(array $substitutions): array
    {
        $paths = [];

        foreach ($substitutions as $index => $argument) {
            if ($argument !== '--substitute') {
                continue;
            }

            $substitution = $substitutions[$index + 1] ?? null;

            if (! is_string($substitution)) {
                continue;
            }

            $position = strpos($substitution, '=');

            if ($position !== false) {
                $paths[substr($substitution, 0, $position)] = true;
            }
        }

        return $paths;
    }

    private function detectAuthUserModel(string $projectRoot): ?string
    {
        $configPath = $projectRoot . '/config/auth.php';

        if (! is_file($configPath)) {
            return is_file($projectRoot . '/app/Models/User.php') ? 'App\\Models\\User' : null;
        }

        $source = file_get_contents($configPath);

        if (! is_string($source)) {
            return null;
        }

        if (preg_match('/[\'"]model[\'"]\s*=>\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class/', $source, $matches) === 1) {
            $class = $matches[1];

            if (str_contains($class, '\\')) {
                return ltrim($class, '\\');
            }

            return $this->importedClassName($source, $class) ?? 'App\\Models\\' . $class;
        }

        if (preg_match('/[\'"]model[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $source, $matches) === 1) {
            return str_replace('\\\\', '\\', $matches[1]);
        }

        return is_file($projectRoot . '/app/Models/User.php') ? 'App\\Models\\User' : null;
    }

    private function importedClassName(string $source, string $shortName): ?string
    {
        if (preg_match_all('/^use\s+([^;]+);/m', $source, $matches) === 0) {
            return null;
        }

        foreach ($matches[1] as $import) {
            $import = trim($import);
            $alias = $import;

            if (preg_match('/^(.+)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $import, $aliasMatches) === 1) {
                $import = trim($aliasMatches[1]);
                $alias = trim($aliasMatches[2]);
            }

            if (substr($alias, strrpos($alias, '\\') === false ? 0 : strrpos($alias, '\\') + 1) === $shortName) {
                return ltrim($import, '\\');
            }
        }

        return null;
    }

    /**
     * @return list<array{file: string, class: string}>
     */
    private function discoverProjectClasses(string $projectRoot): array
    {
        $paths = $this->projectConfigValues($projectRoot)['paths'];

        if (is_dir($projectRoot . '/app/Models')) {
            array_unshift($paths, 'app/Models');
        }

        $classes = [];
        $seenFiles = [];

        foreach (array_values(array_unique($paths)) as $path) {
            foreach ($this->discoverClasses($projectRoot, $path) as $class) {
                if (isset($seenFiles[$class['file']])) {
                    continue;
                }

                $seenFiles[$class['file']] = true;
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return list<array{file: string, class: string}>
     */
    private function discoverClasses(string $projectRoot, string $path): array
    {
        $classes = [];
        $path = $this->normalizeProjectPath($this->baseDirectoryFromGlob($path));

        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '../')) {
            return [];
        }

        $absolutePath = $projectRoot . '/' . $path;

        if (is_file($absolutePath)) {
            $files = [$absolutePath];
            $baseDirectory = dirname($absolutePath);
            $relativeBase = dirname($path);
        } elseif (is_dir($absolutePath)) {
            $files = $this->phpFiles($absolutePath);
            $baseDirectory = $absolutePath;
            $relativeBase = $path;
        } else {
            return [];
        }

        foreach ($files as $file) {
            $source = file_get_contents($file);

            if (! is_string($source)) {
                continue;
            }

            if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespaceMatches) !== 1) {
                continue;
            }

            if (preg_match('/^(?:(?:abstract|final|readonly)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $source, $classMatches) !== 1) {
                continue;
            }

            $relativePath = is_file($absolutePath)
                ? $path
                : $relativeBase . substr($file, strlen($baseDirectory));
            $classes[] = [
                'file' => str_replace(DIRECTORY_SEPARATOR, '/', $relativePath),
                'class' => trim($namespaceMatches[1]) . '\\' . $classMatches[1],
            ];
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param list<string> $excludes
     */
    private function prepareExcludedSymbolStubs(string $projectRoot, array $excludes): ?string
    {
        $symbolDirectory = $projectRoot . '/' . self::EXCLUDED_SYMBOL_DIR;

        if (is_dir($symbolDirectory)) {
            $this->removeDirectory($symbolDirectory);
        }

        $written = 0;

        foreach ($excludes as $exclude) {
            $directory = $projectRoot . '/' . $this->baseDirectoryFromGlob($exclude);

            if (! is_dir($directory)) {
                continue;
            }

            foreach ($this->phpFiles($directory) as $file) {
                $stub = $this->classlikeSymbolStub($file);

                if ($stub === null) {
                    continue;
                }

                $this->ensureDirectory($symbolDirectory);
                file_put_contents($symbolDirectory . '/' . sha1($file) . '.php', $stub);
                $written++;
            }
        }

        return $written > 0 ? self::EXCLUDED_SYMBOL_DIR : null;
    }

    private function prepareLaravelSymbolStubs(string $projectRoot): ?string
    {
        $symbolDirectory = $projectRoot . '/' . self::LARAVEL_SYMBOL_DIR;

        if (is_dir($symbolDirectory)) {
            $this->removeDirectory($symbolDirectory);
        }

        $aliases = [
            'App' => 'Illuminate\\Support\\Facades\\App',
            'Arr' => 'Illuminate\\Support\\Arr',
            'Artisan' => 'Illuminate\\Support\\Facades\\Artisan',
            'Auth' => 'Illuminate\\Support\\Facades\\Auth',
            'Blade' => 'Illuminate\\Support\\Facades\\Blade',
            'Cache' => 'Illuminate\\Support\\Facades\\Cache',
            'Config' => 'Illuminate\\Support\\Facades\\Config',
            'Cookie' => 'Illuminate\\Support\\Facades\\Cookie',
            'Crypt' => 'Illuminate\\Support\\Facades\\Crypt',
            'Date' => 'Illuminate\\Support\\Facades\\Date',
            'DB' => 'Illuminate\\Support\\Facades\\DB',
            'Event' => 'Illuminate\\Support\\Facades\\Event',
            'File' => 'Illuminate\\Support\\Facades\\File',
            'Gate' => 'Illuminate\\Support\\Facades\\Gate',
            'Hash' => 'Illuminate\\Support\\Facades\\Hash',
            'Http' => 'Illuminate\\Support\\Facades\\Http',
            'Lang' => 'Illuminate\\Support\\Facades\\Lang',
            'Log' => 'Illuminate\\Support\\Facades\\Log',
            'Mail' => 'Illuminate\\Support\\Facades\\Mail',
            'Notification' => 'Illuminate\\Support\\Facades\\Notification',
            'Password' => 'Illuminate\\Support\\Facades\\Password',
            'Process' => 'Illuminate\\Support\\Facades\\Process',
            'Queue' => 'Illuminate\\Support\\Facades\\Queue',
            'RateLimiter' => 'Illuminate\\Support\\Facades\\RateLimiter',
            'Redirect' => 'Illuminate\\Support\\Facades\\Redirect',
            'Request' => 'Illuminate\\Support\\Facades\\Request',
            'Response' => 'Illuminate\\Support\\Facades\\Response',
            'Route' => 'Illuminate\\Support\\Facades\\Route',
            'Schedule' => 'Illuminate\\Support\\Facades\\Schedule',
            'Schema' => 'Illuminate\\Support\\Facades\\Schema',
            'Session' => 'Illuminate\\Support\\Facades\\Session',
            'Storage' => 'Illuminate\\Support\\Facades\\Storage',
            'Str' => 'Illuminate\\Support\\Str',
            'URL' => 'Illuminate\\Support\\Facades\\URL',
            'Validator' => 'Illuminate\\Support\\Facades\\Validator',
            'View' => 'Illuminate\\Support\\Facades\\View',
        ];

        $written = 0;

        foreach ($aliases as $alias => $target) {
            $path = $this->laravelClassPath($projectRoot, $target);

            if ($path === null || ! is_file($path)) {
                continue;
            }

            $this->ensureDirectory($symbolDirectory);
            file_put_contents($symbolDirectory . '/' . $alias . '.php', <<<PHP
<?php

class {$alias} extends \\{$target}
{
}
PHP);
            $written++;
        }

        return $written > 0 ? self::LARAVEL_SYMBOL_DIR : null;
    }

    private function laravelClassPath(string $projectRoot, string $class): ?string
    {
        if (! str_starts_with($class, 'Illuminate\\')) {
            return null;
        }

        return $projectRoot . '/vendor/laravel/framework/src/Illuminate/' . str_replace('\\', '/', substr($class, strlen('Illuminate\\'))) . '.php';
    }

    private function baseDirectoryFromGlob(string $path): string
    {
        $globPosition = strcspn($path, '*?[');
        $base = substr($path, 0, $globPosition);

        return rtrim($base === '' ? $path : $base, '/');
    }

    private function classlikeSymbolStub(string $file): ?string
    {
        $source = file_get_contents($file);

        if (! is_string($source)) {
            return null;
        }

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespaceMatches) !== 1) {
            return null;
        }

        if (preg_match('/^((?:(?:abstract|final|readonly)\s+)*(class|interface|trait|enum)\s+[A-Za-z_][A-Za-z0-9_]*(?:\s+[^{;]+)?)/m', $source, $classMatches) !== 1) {
            return null;
        }

        $declaration = trim($classMatches[1]);
        $kind = $classMatches[2];
        $namespace = trim($namespaceMatches[1]);
        $methods = $this->publicMethodStubs($source, $kind);

        return <<<PHP
<?php

namespace {$namespace};

{$declaration}
{
{$methods}
}
PHP;
    }

    private function publicMethodStubs(string $source, string $kind): string
    {
        $tokens = token_get_all($source);
        $methods = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            if (! is_array($tokens[$index]) || $tokens[$index][0] !== T_PUBLIC) {
                continue;
            }

            $cursor = $this->nextMeaningfulTokenIndex($tokens, $index + 1);

            if ($cursor === null) {
                continue;
            }

            if (is_array($tokens[$cursor]) && in_array($tokens[$cursor][0], [T_STATIC, T_ABSTRACT, T_FINAL], true)) {
                $cursor = $this->nextMeaningfulTokenIndex($tokens, $cursor + 1);
            }

            if ($cursor === null || ! is_array($tokens[$cursor]) || $tokens[$cursor][0] !== T_FUNCTION) {
                continue;
            }

            $signature = '';

            for ($cursor = $index; $cursor < $count; $cursor++) {
                $token = $tokens[$cursor];
                $text = is_array($token) ? $token[1] : $token;

                if ($text === '{' || $text === ';') {
                    break;
                }

                $signature .= $text;
            }

            $signature = trim(preg_replace('/\s+/', ' ', $signature) ?? $signature);

            if ($signature === '') {
                continue;
            }

            $methods[] = '    ' . $signature . ($kind === 'interface' ? ';' : ' {}');
        }

        return $methods === [] ? '' : implode(PHP_EOL, $methods) . PHP_EOL;
    }

    /**
     * @param list<array|string> $tokens
     */
    private function nextMeaningfulTokenIndex(array $tokens, int $start): ?int
    {
        $count = count($tokens);

        for ($index = $start; $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /**
     * @param list<array|string> $tokens
     */
    private function previousMeaningfulTokenIndex(array $tokens, int $start): ?int
    {
        for ($index = $start; $index >= 0; $index--) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /**
     * @param list<array{name: string, type: string}> $properties
     * @param list<array{name: string, type: string}> $accessors
     * @param list<array{name: string, type: string}> $relations
     * @param list<array{name: string, parameters: string}> $scopes
     */
    private function insertModelDocblock(string $source, string $shortClass, array $properties, array $accessors, array $relations, array $scopes, bool $usesSanctumApiTokens = false): string
    {
        $lines = [
            ' * @laramago-generated',
        ];

        foreach ($properties as $property) {
            if (! is_array($property) || ! isset($property['name'], $property['type'])) {
                continue;
            }

            $lines[] = ' * @property ' . $property['type'] . ' $' . $property['name'];
        }

        foreach ($accessors as $accessor) {
            if (! is_array($accessor) || ! isset($accessor['name'], $accessor['type'])) {
                continue;
            }

            $lines[] = ' * @property-read ' . $accessor['type'] . ' $' . $accessor['name'];
        }

        foreach ($relations as $relation) {
            if (! is_array($relation) || ! isset($relation['name'], $relation['type'])) {
                continue;
            }

            $lines[] = ' * @property-read ' . $relation['type'] . ' $' . $relation['name'];
        }

        foreach ($this->eloquentModelMagicMethods() as $method) {
            $lines[] = $method;
        }

        if ($usesSanctumApiTokens) {
            $lines[] = ' * @method \\Laravel\\Sanctum\\NewAccessToken createToken(string $name, array $abilities = ["*"], ?\\DateTimeInterface $expiresAt = null)';
        }

        foreach ($scopes as $scope) {
            if (! is_array($scope) || ! isset($scope['name'], $scope['parameters'])) {
                continue;
            }

            $lines[] = ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> ' . $scope['name'] . '(' . $scope['parameters'] . ')';
        }

        foreach ($this->caseInsensitiveMethodAliasLines($source) as $method) {
            $lines[] = $method;
        }

        $docblock = '/**' . PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL . ' */' . PHP_EOL;
        return $this->insertModelStaticDelegationMethods($this->insertClassDocblockLines($source, $shortClass, $lines, $docblock));
    }

    private function insertModelStaticDelegationMethods(string $source): string
    {
        $defined = [];

        if (preg_match_all('/^\s*public\s+static\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches) !== false) {
            foreach ($matches[1] ?? [] as $method) {
                $defined[strtolower($method)] = true;
            }
        }

        $methods = [
            'create' => <<<'PHP'

    public static function create(array $attributes = []): static
    {
        return new static;
    }
PHP,
            'where' => <<<'PHP'

    public static function where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }
PHP,
            'orwhere' => <<<'PHP'

    public static function orWhere(mixed $column, mixed $operator = null, mixed $value = null): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }
PHP,
            'select' => <<<'PHP'

    public static function select(mixed ...$columns): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }
PHP,
            'selectraw' => <<<'PHP'

    public static function selectraw(string $expression, array $bindings = []): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }
PHP,
            'withoutglobalscopes' => <<<'PHP'

    public static function withoutglobalscopes(?array $scopes = null): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }
PHP,
            'find' => <<<'PHP'

    public static function find(mixed $id, array|string $columns = ['*']): mixed
    {
        return null;
    }
PHP,
            'findorfail' => <<<'PHP'

    public static function findOrFail(mixed $id, array|string $columns = ['*']): mixed
    {
        return new static;
    }
PHP,
        ];

        $insertions = [];

        foreach ($methods as $method => $declaration) {
            if (! isset($defined[$method])) {
                $insertions[] = $declaration;
            }
        }

        if ($insertions === []) {
            return $source;
        }

        return $this->insertBeforeFinalClassBrace($source, implode(PHP_EOL, $insertions) . PHP_EOL);
    }

    /**
     * @param array<string, true>|null $candidates
     */
    private function insertCaseInsensitiveMethodAliases(string $source, ?array $candidates = null, int $minimumAliases = 1): string
    {
        $lines = $this->caseInsensitiveMethodAliasLines($source, $candidates);

        if (count($lines) < $minimumAliases) {
            return $source;
        }

        if (preg_match('/^(?:(?:abstract|final|readonly)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $source, $matches) !== 1) {
            return $source;
        }

        return $this->insertClassDocblockLines($source, $matches[1], $lines);
    }

    private function insertTraitSelfCallMethods(string $source): string
    {
        if (preg_match('/^trait\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $source, $traitMatches) !== 1) {
            return $source;
        }

        if (preg_match_all('/^\s*(?:public|protected|private)\s+(?:static\s+)?function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $methodMatches) === false) {
            return $source;
        }

        $defined = [];

        foreach ($methodMatches[1] ?? [] as $method) {
            $defined[strtolower($method)] = true;
        }

        if (preg_match_all('/\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $callMatches) === 0) {
            return $source;
        }

        $lines = [];
        $seen = [];

        foreach ($callMatches[1] as $method) {
            $alias = strtolower($method);

            if (isset($defined[$alias]) || isset($seen[$alias]) || str_starts_with($alias, '__')) {
                continue;
            }

            $seen[$alias] = true;
            $lines[] = ' * @method mixed ' . $alias . '(mixed ...$arguments)';
        }

        if ($lines === []) {
            return $source;
        }

        return $this->insertTraitDocblockLines($source, $traitMatches[1], $lines);
    }

    /**
     * @param list<string> $lines
     */
    private function insertTraitDocblockLines(string $source, string $shortTrait, array $lines): string
    {
        $pattern = '/^trait\s+' . preg_quote($shortTrait, '/') . '\b/m';

        if (preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $source;
        }

        $declarationOffset = $matches[0][1];
        $existingDocblock = $this->classDocblockBeforeOffset($source, $declarationOffset);

        if ($existingDocblock !== null) {
            $mergedDocblock = $this->mergeGeneratedDocblockLines($existingDocblock['docblock'], $lines);

            return substr($source, 0, $existingDocblock['offset']) . $mergedDocblock . substr($source, $declarationOffset);
        }

        $docblock = '/**' . PHP_EOL . ' * @laramago-generated' . PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL . ' */' . PHP_EOL;

        return substr($source, 0, $declarationOffset) . $docblock . substr($source, $declarationOffset);
    }

    /**
     * @return array<string, true>
     */
    private function caseInsensitiveMethodAliasCandidates(string $projectRoot): array
    {
        $candidates = [];
        $seenFiles = [];

        foreach ($this->projectConfigValues($projectRoot)['paths'] as $path) {
            foreach ($this->sourcePhpFiles($projectRoot, $path) as $file) {
                if (isset($seenFiles[$file])) {
                    continue;
                }

                $seenFiles[$file] = true;
                $source = file_get_contents($file);

                if (! is_string($source)) {
                    continue;
                }

                if (preg_match_all('/(?:->|::)\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches) === 0) {
                    continue;
                }

                foreach ($matches[1] as $method) {
                    $candidates[strtolower($method)] = true;
                }
            }
        }

        return $candidates;
    }

    /**
     * @param array<string, true>|null $candidates
     * @return list<string>
     */
    private function caseInsensitiveMethodAliasLines(string $source, ?array $candidates = null): array
    {
        if (preg_match('/^(?:(?:abstract|final|readonly)\s+)*class\s+[A-Za-z_][A-Za-z0-9_]*\b/m', $source) !== 1) {
            return [];
        }

        if (preg_match_all('/^\s*public\s+(static\s+)?function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $lines = [];
        $seen = [];

        foreach ($matches as $match) {
            $method = $match[2];
            $alias = strtolower($method);

            if ($alias === $method || str_starts_with($method, '__')) {
                continue;
            }

            if ($candidates !== null && ! isset($candidates[$alias])) {
                continue;
            }

            $key = (($match[1] ?? '') !== '' ? 'static ' : '') . $alias;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $lines[] = ' * @method ' . (($match[1] ?? '') !== '' ? 'static ' : '') . 'mixed ' . $alias . '(mixed ...$arguments)';
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private function insertClassDocblockLines(string $source, string $shortClass, array $lines, ?string $newDocblock = null): string
    {
        $pattern = '/^(?:(?:abstract|final|readonly)\s+)*class\s+' . preg_quote($shortClass, '/') . '\b/m';

        if (preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $source;
        }

        $declarationOffset = $matches[0][1];
        $existingDocblock = $this->classDocblockBeforeOffset($source, $declarationOffset);

        if ($existingDocblock !== null) {
            $mergedDocblock = $this->mergeGeneratedDocblockLines($existingDocblock['docblock'], $lines);

            return substr($source, 0, $existingDocblock['offset']) . $mergedDocblock . substr($source, $declarationOffset);
        }

        $newDocblock ??= '/**' . PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL . ' */' . PHP_EOL;

        return substr($source, 0, $declarationOffset) . $newDocblock . substr($source, $declarationOffset);
    }

    /**
     * @return list<string>
     */
    private function eloquentModelMagicMethods(): array
    {
        return [
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> query()',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> newQuery()',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = "and")',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> orWhere(mixed $column, mixed $operator = null, mixed $value = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereIn(string $column, mixed $values, string $boolean = "and", bool $not = false)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereNotIn(string $column, mixed $values)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereNull(string|array $columns, string $boolean = "and", bool $not = false)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereNotNull(string|array $columns)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereDate(string $column, mixed $operator, mixed $value = null, string $boolean = "and")',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> join(string $table, mixed $first, ?string $operator = null, mixed $second = null, string $type = "inner", bool $where = false)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> leftJoin(string $table, mixed $first, ?string $operator = null, mixed $second = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> rightJoin(string $table, mixed $first, ?string $operator = null, mixed $second = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> crossJoin(string $table, mixed $first = null, ?string $operator = null, mixed $second = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> groupBy(array|string ...$groups)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> having(string $column, ?string $operator = null, mixed $value = null, string $boolean = "and")',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> orHaving(string $column, ?string $operator = null, mixed $value = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> with(array|string ...$relations)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> withCount(array|string $relations)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> select(mixed ...$columns)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> selectRaw(string $expression, array $bindings = [])',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> selectraw(string $expression, array $bindings = [])',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> orderBy(mixed $column, mixed $direction = "asc")',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> orderByRaw(string $sql, array $bindings = [])',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> latest(string|null $column = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> oldest(string|null $column = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> limit(int $value)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> take(int $value)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> offset(int $value)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> skip(int $value)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereLike(string $column, mixed $value, bool $caseSensitive = false, string $boolean = "and", bool $not = false)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> wherelike(string $column, mixed $value, bool $caseSensitive = false, string $boolean = "and", bool $not = false)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereIntegerInRaw(string $column, mixed $values, string $boolean = "and", bool $not = false)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereIntegerNotInRaw(string $column, mixed $values, string $boolean = "and")',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereintegerinraw(string $column, mixed $values, string $boolean = "and", bool $not = false)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereintegernotinraw(string $column, mixed $values, string $boolean = "and")',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> withoutGlobalScope(mixed $scope)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> withoutGlobalScopes(array|null $scopes = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> withoutglobalscope(mixed $scope)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> withoutglobalscopes(array|null $scopes = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> onlyTrashed()',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> withTrashed(bool $withTrashed = true)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> withoutTrashed()',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> onlytrashed()',
            ' * @method static self create(array $attributes = null)',
            ' * @method static self forceCreate(array $attributes)',
            ' * @method static static|null first(array|string $columns = ["*"])',
            ' * @method static self firstOrFail(array|string $columns = ["*"])',
            ' * @method static self findorfail(mixed $id, array|string $columns = ["*"])',
            ' * @method static static|null firstWhere(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = "and")',
            ' * @method static self firstOrCreate(array $attributes = null, array $values = null)',
            ' * @method static self firstOrNew(array $attributes = null, array $values = null)',
            ' * @method static self updateOrCreate(array $attributes, array $values = null)',
            ' * @method static self sole(array|string $columns = ["*"])',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Collection all(array|string $columns = ["*"])',
            ' * @method static static|null find(mixed $id, array|string $columns = ["*"])',
            ' * @method static self findOrFail(mixed $id, array|string $columns = ["*"])',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Collection get(array|string $columns = ["*"])',
            ' * @method static \\Illuminate\\Support\\Collection pluck(string $column, mixed $key = null)',
            ' * @method static \\Illuminate\\LazyCollection cursor()',
            ' * @method static bool chunk(int $count, callable $callback)',
            ' * @method static bool each(callable $callback, int $count = 1000)',
            ' * @method static mixed value(string $column)',
            ' * @method static mixed valueOrFail(string $column)',
            ' * @method static int count(string $columns = "*")',
            ' * @method static mixed sum(string $column)',
            ' * @method static mixed avg(string $column)',
            ' * @method static mixed min(string $column)',
            ' * @method static mixed max(string $column)',
            ' * @method static bool exists()',
            ' * @method static bool doesntExist()',
            ' * @method static int destroy(mixed $ids)',
            ' * @method static bool insert(array $values)',
            ' * @method static int upsert(array $values, array|string $uniqueBy, array|null $update = null)',
            ' * @method static void truncate()',
        ];
    }

    /**
     * @return array{offset: int, docblock: string}|null
     */
    private function classDocblockBeforeOffset(string $source, int $offset): ?array
    {
        $prefix = substr($source, 0, $offset);

        if (preg_match('/(\/\*\*.*?\*\/)\s*$/s', $prefix, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return [
            'offset' => $matches[1][1],
            'docblock' => $matches[1][0],
        ];
    }

    /**
     * @param list<string> $generatedLines
     */
    private function mergeGeneratedDocblockLines(string $docblock, array $generatedLines): string
    {
        $merged = preg_replace('/\s*\*\/\s*$/', '', rtrim($docblock));

        if (! is_string($merged)) {
            return $docblock;
        }

        foreach ($generatedLines as $line) {
            if (str_contains($merged, trim($line))) {
                continue;
            }

            $merged .= PHP_EOL . $line;
        }

        return $merged . PHP_EOL . ' */' . PHP_EOL;
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function defaultAnalyzeFlags(string $projectRoot, array $arguments, bool $usesOverlays): array
    {
        $hasBaselineOption = false;

        foreach ($arguments as $argument) {
            if ($argument === '--ignore-baseline' || $argument === '--baseline' || str_starts_with($argument, '--baseline=')) {
                $hasBaselineOption = true;
                break;
            }
        }

        if ($hasBaselineOption) {
            return [];
        }

        if (! is_file($projectRoot . '/' . self::BASELINE_FILE)) {
            $runtimeBaseline = $projectRoot . '/' . self::RUNTIME_BASELINE_FILE;

            if (is_file($runtimeBaseline)) {
                @unlink($runtimeBaseline);
            }

            return ['--ignore-baseline'];
        }

        if ($usesOverlays && $this->translateBaselinePaths($projectRoot, overlayToOriginal: false, source: self::BASELINE_FILE, target: self::RUNTIME_BASELINE_FILE)) {
            return ['--baseline', self::RUNTIME_BASELINE_FILE];
        }

        return ['--baseline', self::BASELINE_FILE];
    }

    private function translateBaselinePaths(string $projectRoot, bool $overlayToOriginal, string $source, string $target): bool
    {
        $sourcePath = $projectRoot . '/' . $source;
        $targetPath = $projectRoot . '/' . $target;

        if (! is_file($sourcePath)) {
            return false;
        }

        $map = $this->overlayPathMap($projectRoot);

        if ($map === []) {
            return false;
        }

        $baseline = file_get_contents($sourcePath);

        if (! is_string($baseline)) {
            return false;
        }

        foreach ($map as $entry) {
            if (! is_array($entry) || ! isset($entry['original'], $entry['overlay']) || ! is_string($entry['original']) || ! is_string($entry['overlay'])) {
                continue;
            }

            $from = $overlayToOriginal ? $entry['overlay'] : $entry['original'];
            $to = $overlayToOriginal ? $entry['original'] : $entry['overlay'];
            $baseline = str_replace('file = "' . $from . '"', 'file = "' . $to . '"', $baseline);
        }

        $this->ensureDirectory(dirname($targetPath));

        return file_put_contents($targetPath, $baseline) !== false;
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function stripLaramagoOptions(array $arguments): array
    {
        return array_values(array_filter(
            $arguments,
            static fn (string $argument): bool => ! str_starts_with($argument, '--project=')
                && ! str_starts_with($argument, '--phpstan-level=')
                && $argument !== '--force'
                && $argument !== '--no-laravel-model-overlays'
                && $argument !== '--no-laravel-framework-overlays'
                && $argument !== '--no-phpstan-pragma-overlays'
        ));
    }

    /**
     * @param list<string> $command
     */
    private function process(array $command, string $cwd): int
    {
        $stdoutPath = tempnam(sys_get_temp_dir(), 'laramago-stdout-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'laramago-stderr-');

        if (! is_string($stdoutPath) || ! is_string($stderrPath)) {
            $this->line('Unable to create temporary output files.');

            return 1;
        }

        $process = proc_open($command, [
            0 => STDIN,
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ], $pipes, $cwd);

        if (! is_resource($process)) {
            $this->line('Unable to start process.');

            return 1;
        }

        $exitCode = proc_close($process);
        $stdout = file_get_contents($stdoutPath);
        $stderr = file_get_contents($stderrPath);
        @unlink($stdoutPath);
        @unlink($stderrPath);

        if (is_string($stdout) && $stdout !== '') {
            fwrite(STDOUT, $this->translateOutputPaths($cwd, $stdout));
        }

        if (is_string($stderr) && $stderr !== '') {
            fwrite(STDERR, $this->translateOutputPaths($cwd, $stderr));
        }

        return $exitCode;
    }

    private function translateOutputPaths(string $projectRoot, string $output): string
    {
        if ($output === '') {
            return $output;
        }

        foreach ($this->overlayPathMap($projectRoot) as $entry) {
            if (! is_array($entry) || ! isset($entry['original'], $entry['overlay']) || ! is_string($entry['original']) || ! is_string($entry['overlay'])) {
                continue;
            }

            $output = str_replace($projectRoot . '/' . $entry['overlay'], $projectRoot . '/' . $entry['original'], $output);
            $output = str_replace($entry['overlay'], $entry['original'], $output);
        }

        return $output;
    }

    /**
     * @return list<array{original: string, overlay: string}>
     */
    private function overlayPathMap(string $projectRoot): array
    {
        $map = [];

        foreach ([self::MODEL_OVERLAY_MAP, self::PHPSTAN_PRAGMA_OVERLAY_MAP] as $mapFile) {
            $mapPath = $projectRoot . '/' . $mapFile;

            if (! is_file($mapPath)) {
                continue;
            }

            try {
                $entries = json_decode((string) file_get_contents($mapPath), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry) || ! isset($entry['original'], $entry['overlay']) || ! is_string($entry['original']) || ! is_string($entry['overlay'])) {
                    continue;
                }

                $map[] = [
                    'original' => $entry['original'],
                    'overlay' => $entry['overlay'],
                ];
            }
        }

        return $map;
    }

    /**
     * @param list<string> $command
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function capture(array $command, string $cwd): array
    {
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $cwd);

        if (! is_resource($process)) {
            return ['exitCode' => 1, 'stdout' => '', 'stderr' => 'Unable to start process.'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function withProjectLock(string $projectRoot, \Closure $callback): int
    {
        $this->ensureDirectory($projectRoot . '/' . self::STATE_DIR);

        $handle = fopen($projectRoot . '/' . self::LOCK_FILE, 'c');

        if ($handle === false) {
            $this->line('Unable to open Laramago project lock.');

            return 1;
        }

        if (! flock($handle, LOCK_EX)) {
            fclose($handle);
            $this->line('Unable to acquire Laramago project lock.');

            return 1;
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create directory {$directory}.");
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
        } catch (\UnexpectedValueException) {
            return;
        }

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isDir()) {
                $path = $file->getPathname();

                if (is_dir($path)) {
                    @rmdir($path);
                }

                continue;
            }

            if ($file instanceof \SplFileInfo) {
                $path = $file->getPathname();

                if (is_file($path) || is_link($path)) {
                    @unlink($path);
                }
            }
        }

        if (is_dir($directory)) {
            @rmdir($directory);
        }
    }

    private function line(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    private function error(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
}
