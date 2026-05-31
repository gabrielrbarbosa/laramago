<?php

declare(strict_types=1);

namespace Laramago;

require_once __DIR__ . '/Concerns/MigratesPhpStan.php';
require_once __DIR__ . '/Concerns/BuildsRuntimeConfig.php';
require_once __DIR__ . '/Concerns/BuildsLaravelFrameworkOverlays.php';
require_once __DIR__ . '/Concerns/BuildsSourceCompatibilityOverlays.php';
require_once __DIR__ . '/Concerns/BuildsLaravelModelOverlays.php';
require_once __DIR__ . '/Concerns/RunsMagoProcesses.php';

final class Application
{
    private const VERSION = '0.1.74';

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

    use Concerns\MigratesPhpStan;
    use Concerns\BuildsRuntimeConfig;
    use Concerns\BuildsLaravelFrameworkOverlays;
    use Concerns\BuildsSourceCompatibilityOverlays;
    use Concerns\BuildsLaravelModelOverlays;
    use Concerns\RunsMagoProcesses;

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

        $source = $this->phpStanConfigSource($phpStanConfig);

        if (! is_string($source)) {
            $this->line("Unable to read {$phpStanConfig}.");

            return 1;
        }

        $paths = $this->neonListValue($source, 'paths');

        if ($paths === []) {
            $paths = ['app'];
        }

        $excludes = $this->phpStanExcludePaths($source);
        $config = $this->renderProjectConfig(
            $this->detectPhpVersion($projectRoot),
            $this->normalizePhpStanPaths($paths),
            array_values(array_unique(array_merge(['vendor'], $this->phpStanDiscoveryIncludes($source)))),
            $this->normalizePhpStanPaths($excludes),
            $this->phpStanIgnoredAnalyzerIgnores($source),
        );

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

            if (! $this->analysisHasSourceFiles($projectRoot, $arguments)) {
                return 1;
            }

            $runtimeConfig = $this->prepareRuntimeConfig($projectRoot, $arguments, $mago);
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

            if (! $this->analysisHasSourceFiles($projectRoot, $arguments)) {
                return 1;
            }

            $baselinePath = self::BASELINE_FILE;
            $runtimeConfig = $this->prepareRuntimeConfig($projectRoot, $arguments, $mago);
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

            if (! $this->analysisHasSourceFiles($projectRoot, $arguments)) {
                return 1;
            }

            $modelSubstitutions = $this->laravelModelSubstitutions($projectRoot, $arguments);
            $substitutions = array_merge(
                $modelSubstitutions,
                $this->laravelFrameworkSubstitutions($projectRoot, $arguments),
                $this->phpStanPragmaSubstitutions($projectRoot, $arguments, $this->substitutionOriginalPaths($modelSubstitutions)),
            );

            $runtimeConfig = $this->prepareRuntimeConfig($projectRoot, $arguments, $mago);

            return $this->process(array_merge([
                $mago,
                '--config',
                $runtimeConfig,
                'analyze',
            ], $this->defaultAnalyzeFlags($projectRoot, $arguments, $substitutions !== []), [
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

                if ($this->analysisHasSourceFiles($projectRoot, $arguments, false)) {
                    $this->line('OK   Configured source paths contain PHP files.');
                } else {
                    $this->line('FAIL Configured source paths do not contain PHP files.');
                    $failed = true;
                }
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
  laramago analyze [--phpstan-level=0..10|max] [--find-unused-definitions] [--no-laravel-model-overlays] [--no-laravel-framework-overlays] [--no-phpstan-pragma-overlays] [mago analyze options] [path ...]
  laramago baseline [--force] [--phpstan-level=0..10|max] [--find-unused-definitions]
  laramago verify-baseline [--phpstan-level=0..10|max] [--find-unused-definitions]
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
    private function analysisHasSourceFiles(string $projectRoot, array $arguments, bool $emitFailure = true): bool
    {
        foreach ($this->explicitAnalysisTargets($arguments) as $target) {
            if ($this->pathContainsPhpFiles($projectRoot, $target)) {
                return true;
            }
        }

        foreach ($this->projectConfigValues($projectRoot)['paths'] as $path) {
            if ($this->pathContainsPhpFiles($projectRoot, $path)) {
                return true;
            }
        }

        if ($emitFailure) {
            $this->line('No PHP files found in the configured Laramago source paths. Check mago.toml [source].paths or pass an explicit path.');
        }

        return false;
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    private function explicitAnalysisTargets(array $arguments): array
    {
        $targets = [];

        foreach ($this->stripLaramagoOptions($arguments) as $argument) {
            if ($argument === '' || str_starts_with($argument, '-')) {
                continue;
            }

            $targets[] = $argument;
        }

        return $targets;
    }

    private function pathContainsPhpFiles(string $projectRoot, string $path): bool
    {
        $path = trim($path);

        if ($path === '') {
            return false;
        }

        $candidates = [];

        if (str_contains($path, '*') || str_contains($path, '?') || str_contains($path, '[')) {
            $matches = glob(str_starts_with($path, '/') ? $path : $projectRoot . '/' . $path);
            $candidates = $matches === false ? [] : $matches;
        } else {
            $candidates[] = str_starts_with($path, '/') ? $path : $projectRoot . '/' . $path;
        }

        foreach ($candidates as $candidate) {
            if ($this->candidateContainsPhpFiles($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function candidateContainsPhpFiles(string $path): bool
    {
        if (is_file($path)) {
            return str_ends_with($path, '.php');
        }

        if (! is_dir($path)) {
            return false;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
        } catch (\UnexpectedValueException) {
            return false;
        }

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                return true;
            }
        }

        return false;
    }
}
