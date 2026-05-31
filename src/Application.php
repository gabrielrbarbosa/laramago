<?php

declare(strict_types=1);

namespace Laramago;

final class Application
{
    private const CONFIG_FILE = 'mago.toml';

    private const BASELINE_FILE = 'laramago-analyzer-baseline.toml';

    private const CACHE_DIR = '.laramago/cache';

    private const MODEL_OVERLAY_DIR = '.laramago/cache/model-overlays';

    private const MODEL_OVERLAY_MAP = '.laramago/cache/model-overlays.json';

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
        $config = $this->renderConfig($phpVersion, $sourcePaths, $excludes);

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
        $substitutions = $this->laravelModelSubstitutions($projectRoot, $arguments);

        $this->line('Prepared ' . (int) (count($substitutions) / 2) . ' Laravel model overlays.');

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function analyze(array $arguments): int
    {
        $projectRoot = $this->projectRoot($arguments);
        $mago = $this->findMagoBinary($projectRoot);

        if ($mago === null) {
            $this->line('Unable to find Mago. Install carthage-software/mago or laramago/laramago in this project.');

            return 1;
        }

        $substitutions = $this->laravelModelSubstitutions($projectRoot, $arguments);
        $command = [$mago, 'analyze'];
        $command = array_merge(
            $command,
            $this->defaultAnalyzeFlags($projectRoot, $arguments, $substitutions !== []),
            $substitutions,
            $this->stripLaramagoOptions($arguments),
        );

        return $this->process($command, $projectRoot);
    }

    /**
     * @param list<string> $arguments
     */
    private function baseline(array $arguments): int
    {
        $projectRoot = $this->projectRoot($arguments);
        $mago = $this->findMagoBinary($projectRoot);

        if ($mago === null) {
            $this->line('Unable to find Mago. Install carthage-software/mago or laramago/laramago in this project.');

            return 1;
        }

        $baselinePath = self::BASELINE_FILE;
        $command = [
            $mago,
            'analyze',
            '--baseline',
            $baselinePath,
            '--generate-baseline',
            '--reporting-format=count',
        ];

        $substitutions = $this->laravelModelSubstitutions($projectRoot, $arguments);
        $command = array_merge($command, $substitutions);

        if (is_file($projectRoot . '/' . $baselinePath) && ! in_array('--force', $arguments, true)) {
            $command[] = '--backup-baseline';
        }

        $command = array_merge($command, $this->stripLaramagoOptions($arguments));

        $exitCode = $this->process($command, $projectRoot);

        if ($exitCode === 0 && $substitutions !== []) {
            $this->translateBaselinePaths($projectRoot, overlayToOriginal: true, source: self::BASELINE_FILE, target: self::BASELINE_FILE);
        }

        return $exitCode;
    }

    /**
     * @param list<string> $arguments
     */
    private function verifyBaseline(array $arguments): int
    {
        $projectRoot = $this->projectRoot($arguments);
        $mago = $this->findMagoBinary($projectRoot);

        if ($mago === null) {
            $this->line('Unable to find Mago. Install carthage-software/mago or laramago/laramago in this project.');

            return 1;
        }

        $substitutions = $this->laravelModelSubstitutions($projectRoot, $arguments);

        return $this->process(array_merge([
            $mago,
            'analyze',
        ], $this->defaultAnalyzeFlags($projectRoot, $arguments, $substitutions !== []), [
            '--verify-baseline',
            '--reporting-format=count',
        ], $substitutions, $this->stripLaramagoOptions($arguments)), $projectRoot);
    }

    /**
     * @param list<string> $arguments
     */
    private function clear(array $arguments): int
    {
        $projectRoot = $this->projectRoot($arguments);
        $cachePath = $projectRoot . '/' . self::CACHE_DIR;

        if (is_dir($cachePath)) {
            $this->removeDirectory($cachePath);
        }

        $this->line("Cleared {$cachePath}");

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function doctor(array $arguments): int
    {
        $projectRoot = $this->projectRoot($arguments);
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
            $this->line('WARN laramago-analyzer-baseline.toml is missing. Run `vendor/bin/laramago baseline` for existing projects.');
        }

        if (! is_file($projectRoot . '/bootstrap/app.php')) {
            $this->line('WARN Laravel bootstrap file was not found; model overlays will be skipped.');

            return $failed ? 1 : 0;
        }

        $this->line('OK   Laravel bootstrap file exists.');

        if (! is_dir($projectRoot . '/app/Models')) {
            $this->line('WARN app/Models was not found; model overlays will be skipped.');

            return $failed ? 1 : 0;
        }

        $substitutions = $this->laravelModelSubstitutions($projectRoot, $arguments);
        $this->line('OK   Prepared ' . (int) (count($substitutions) / 2) . ' Laravel model overlays.');

        return $failed ? 1 : 0;
    }

    private function help(): int
    {
        $this->line(<<<'HELP'
Laramago

Usage:
  laramago init [--force] [--source=app] [--exclude=path/**]
  laramago prepare
  laramago analyze [mago analyze options] [path ...]
  laramago baseline [--force]
  laramago verify-baseline
  laramago doctor
  laramago count [path ...]
  laramago codes [path ...]
  laramago clear

The analyze command automatically uses laramago-analyzer-baseline.toml and Laravel model overlays when available.
HELP);

        return 0;
    }

    private function version(): int
    {
        $this->line('laramago 0.1.0');

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
     * @return list<string>
     */
    private function defaultLaravelExcludes(string $projectRoot): array
    {
        $candidates = [
            'app/Helpers/Integracao/**',
            'app/Http/Controllers/NotaFiscal/**',
            'app/Services/Integracao/**',
            'app/Services/NotaFiscal/**',
        ];

        return array_values(array_filter(
            $candidates,
            static fn (string $path): bool => file_exists($projectRoot . '/' . rtrim($path, '/*'))
        ));
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
     * @param list<string> $excludes
     */
    private function renderConfig(string $phpVersion, array $sourcePaths, array $excludes): string
    {
        $paths = $this->tomlArray($sourcePaths);
        $excludesValue = $this->tomlArray($excludes);

        return <<<TOML
version = "1"
php-version = "{$phpVersion}"

[source]
workspace = "."
paths = {$paths}
includes = ["vendor"]
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
ignore = [
  { code = "mixed-argument", in = "app/" },
  { code = "mixed-assignment", in = "app/" },
  { code = "possibly-invalid-argument", in = "app/" },
  { code = "invalid-array-element-key", in = "app/" },
  { code = "less-specific-return-statement", in = "app/" },
  { code = "mixed-return-statement", in = "app/" },
  { code = "non-documented-method", in = "app/" },
  { code = "non-documented-property", in = "app/" },
  { code = "mixed-property-type-coercion", in = "app/" },
  { code = "invalid-property-write", in = "app/" },
  { code = "non-existent-property", in = "app/*/Concerns/*" },
  { code = "mixed-operand", in = "app/" },
  { code = "mixed-method-access", in = "app/" },
  { code = "non-existent-method", in = "app/" },
  { code = "possibly-null-operand", in = "app/" },
  { code = "invalid-return-statement", in = "app/" },
  { code = "possibly-null-argument", in = "app/" },
  { code = "ambiguous-object-method-access", in = "app/" },
  { code = "possible-method-access-on-null", in = "app/" },
  { code = "no-value", in = "app/" },
  { code = "falsable-return-statement", in = "app/" },
]
find-unused-definitions = true
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
        $candidates = [
            $projectRoot . '/vendor/bin/mago',
            dirname(__DIR__) . '/vendor/bin/mago',
            dirname(__DIR__, 3) . '/bin/mago',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
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

        if (! is_file($projectRoot . '/bootstrap/app.php') || ! is_dir($projectRoot . '/app/Models')) {
            return [];
        }

        $classes = $this->discoverClasses($projectRoot . '/app/Models', 'app/Models');

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
            $relations = $model['relations'] ?? null;

            if (! is_string($file) || ! is_string($class) || ! is_array($properties) || ! is_array($relations)) {
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
            $overlay = $this->insertModelDocblock($source, $class, $properties, $relations);
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
     * @return list<array{file: string, class: string}>
     */
    private function discoverClasses(string $directory, string $relativeDirectory): array
    {
        $classes = [];
        $files = $this->phpFiles($directory);

        foreach ($files as $file) {
            $source = file_get_contents($file);

            if (! is_string($source)) {
                continue;
            }

            if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespaceMatches) !== 1) {
                continue;
            }

            if (preg_match('/^(?:abstract\s+|final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $source, $classMatches) !== 1) {
                continue;
            }

            $relativePath = $relativeDirectory . substr($file, strlen($directory));
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
     * @param list<array{name: string, type: string}> $properties
     * @param list<array{name: string, type: string}> $relations
     */
    private function insertModelDocblock(string $source, string $shortClass, array $properties, array $relations): string
    {
        $lines = [
            '/**',
            ' * @laramago-generated',
        ];

        foreach ($properties as $property) {
            if (! is_array($property) || ! isset($property['name'], $property['type'])) {
                continue;
            }

            $lines[] = ' * @property ' . $property['type'] . ' $' . $property['name'];
        }

        foreach ($relations as $relation) {
            if (! is_array($relation) || ! isset($relation['name'], $relation['type'])) {
                continue;
            }

            $lines[] = ' * @property-read ' . $relation['type'] . ' $' . $relation['name'];
        }

        $lines[] = ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> query()';
        $lines[] = ' * @method static static|null find(mixed $id, array|string $columns = ["*"])';
        $lines[] = ' */';

        $docblock = implode(PHP_EOL, $lines) . PHP_EOL;
        $pattern = '/^((?:abstract|final)\s+)?class\s+' . preg_quote($shortClass, '/') . '\b/m';

        if (preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $source;
        }

        return substr($source, 0, $matches[0][1]) . $docblock . substr($source, $matches[0][1]);
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

        if ($hasBaselineOption || ! is_file($projectRoot . '/' . self::BASELINE_FILE)) {
            return [];
        }

        if ($usesOverlays && $this->translateBaselinePaths($projectRoot, overlayToOriginal: false, source: self::BASELINE_FILE, target: self::RUNTIME_BASELINE_FILE)) {
            return ['--baseline', self::RUNTIME_BASELINE_FILE];
        }

        return ['--baseline', self::BASELINE_FILE];
    }

    private function translateBaselinePaths(string $projectRoot, bool $overlayToOriginal, string $source, string $target): bool
    {
        $mapPath = $projectRoot . '/' . self::MODEL_OVERLAY_MAP;
        $sourcePath = $projectRoot . '/' . $source;
        $targetPath = $projectRoot . '/' . $target;

        if (! is_file($mapPath) || ! is_file($sourcePath)) {
            return false;
        }

        try {
            $map = json_decode((string) file_get_contents($mapPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (! is_array($map)) {
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
                && $argument !== '--force'
                && $argument !== '--no-laravel-model-overlays'
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
        $mapPath = $projectRoot . '/' . self::MODEL_OVERLAY_MAP;

        if (! is_file($mapPath) || $output === '') {
            return $output;
        }

        try {
            $map = json_decode((string) file_get_contents($mapPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $output;
        }

        if (! is_array($map)) {
            return $output;
        }

        foreach ($map as $entry) {
            if (! is_array($entry) || ! isset($entry['original'], $entry['overlay']) || ! is_string($entry['original']) || ! is_string($entry['overlay'])) {
                continue;
            }

            $output = str_replace($projectRoot . '/' . $entry['overlay'], $projectRoot . '/' . $entry['original'], $output);
            $output = str_replace($entry['overlay'], $entry['original'], $output);
        }

        return $output;
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

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isDir()) {
                rmdir($file->getPathname());
                continue;
            }

            if ($file instanceof \SplFileInfo) {
                unlink($file->getPathname());
            }
        }

        rmdir($directory);
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
