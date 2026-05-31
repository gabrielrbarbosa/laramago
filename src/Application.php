<?php

declare(strict_types=1);

namespace Laramago;

final class Application
{
    private const VERSION = '0.1.10';

    private const CONFIG_FILE = 'mago.toml';

    private const BASELINE_FILE = 'laramago-analyzer-baseline.toml';

    private const CACHE_DIR = '.laramago/cache';

    private const MODEL_OVERLAY_DIR = '.laramago/cache/model-overlays';

    private const MODEL_OVERLAY_MAP = '.laramago/cache/model-overlays.json';

    private const FRAMEWORK_OVERLAY_DIR = '.laramago/cache/framework-overlays';

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
        $modelSubstitutions = $this->laravelModelSubstitutions($projectRoot, $arguments);
        $frameworkSubstitutions = $this->laravelFrameworkSubstitutions($projectRoot, $arguments);

        $this->line('Prepared ' . (int) (count($modelSubstitutions) / 2) . ' Laravel model overlays.');
        $this->line('Prepared ' . (int) (count($frameworkSubstitutions) / 2) . ' Laravel framework overlays.');

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

        $runtimeConfig = $this->prepareRuntimeConfig($projectRoot);
        $modelSubstitutions = $this->laravelModelSubstitutions($projectRoot, $arguments);
        $frameworkSubstitutions = $this->laravelFrameworkSubstitutions($projectRoot, $arguments);
        $substitutions = array_merge($modelSubstitutions, $frameworkSubstitutions);
        $command = [$mago, '--config', $runtimeConfig, 'analyze'];
        $command = array_merge(
            $command,
            $this->defaultAnalyzeFlags($projectRoot, $arguments, $modelSubstitutions !== []),
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
        $runtimeConfig = $this->prepareRuntimeConfig($projectRoot);
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
        $command = array_merge($command, $substitutions, $this->laravelFrameworkSubstitutions($projectRoot, $arguments));

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

        $modelSubstitutions = $this->laravelModelSubstitutions($projectRoot, $arguments);
        $substitutions = array_merge($modelSubstitutions, $this->laravelFrameworkSubstitutions($projectRoot, $arguments));

        $runtimeConfig = $this->prepareRuntimeConfig($projectRoot);

        return $this->process(array_merge([
            $mago,
            '--config',
            $runtimeConfig,
            'analyze',
        ], $this->defaultAnalyzeFlags($projectRoot, $arguments, $modelSubstitutions !== []), [
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

        $runtimeConfig = $this->prepareRuntimeConfig($projectRoot);
        $modelSubstitutions = $this->laravelModelSubstitutions($projectRoot, $arguments);
        $frameworkSubstitutions = $this->laravelFrameworkSubstitutions($projectRoot, $arguments);
        $this->line('OK   Prepared Laramago runtime config: ' . $runtimeConfig);
        $this->line('OK   Prepared ' . (int) (count($modelSubstitutions) / 2) . ' Laravel model overlays.');
        $this->line('OK   Prepared ' . (int) (count($frameworkSubstitutions) / 2) . ' Laravel framework overlays.');

        return $failed ? 1 : 0;
    }

    private function help(): int
    {
        $this->line(<<<'HELP'
Laramago

Usage:
  laramago init [--force] [--source=app] [--exclude=path/**]
  laramago prepare
  laramago analyze [--no-laravel-model-overlays] [--no-laravel-framework-overlays] [mago analyze options] [path ...]
  laramago baseline [--force]
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

    private function prepareRuntimeConfig(string $projectRoot): string
    {
        $values = $this->projectConfigValues($projectRoot);
        $runtimeConfigPath = $projectRoot . '/' . self::RUNTIME_CONFIG_FILE;

        $this->ensureDirectory(dirname($runtimeConfigPath));
        file_put_contents($runtimeConfigPath, $this->renderRuntimeConfig(
            $values['phpVersion'],
            $values['paths'],
            $values['includes'],
            $values['excludes'],
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
     * @param list<string> $includes
     * @param list<string> $excludes
     */
    private function renderRuntimeConfig(string $phpVersion, array $sourcePaths, array $includes, array $excludes): string
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
  "mixed-argument",
  "mixed-assignment",
  "mixed-array-access",
  "mixed-array-assignment",
  "possibly-invalid-argument",
  "invalid-array-element-key",
  "less-specific-return-statement",
  "mixed-return-statement",
  "non-documented-method",
  "non-documented-property",
  "mixed-property-type-coercion",
  "invalid-property-write",
  { code = "non-existent-property", in = "app/*/Concerns/*" },
  "mixed-operand",
  "mixed-property-access",
  "mixed-method-access",
  "non-existent-method",
  "possibly-null-operand",
  "invalid-return-statement",
  "possibly-null-argument",
  "ambiguous-object-method-access",
  "possible-method-access-on-null",
  "no-value",
  "falsable-return-statement",
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
            $accessors = $model['accessors'] ?? [];
            $relations = $model['relations'] ?? null;
            $scopes = $model['scopes'] ?? [];

            if (! is_string($file) || ! is_string($class) || ! is_array($properties) || ! is_array($accessors) || ! is_array($relations) || ! is_array($scopes)) {
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
            $overlay = $this->insertModelDocblock($source, $class, $properties, $accessors, $relations, $scopes);
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
        $authFacadePath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Support/Facades/Auth.php';
        $hasFactoryPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/HasFactory.php';
        $scopePath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Scope.php';
        $fromCollectionPath = $projectRoot . '/vendor/maatwebsite/excel/src/Concerns/FromCollection.php';

        $authModel = $this->detectAuthUserModel($projectRoot);

        if ($authModel !== null) {
            $authModel = '\\' . ltrim($authModel, '\\');

            if (is_file($guardPath)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Guard.php', $guardPath, $this->renderAuthGuardOverlay($authModel));
            }

            if (is_file($authFacadePath)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Auth.php', $authFacadePath, $this->renderAuthFacadeOverlay($authModel));
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
     * @param list<array{name: string, type: string}> $accessors
     * @param list<array{name: string, type: string}> $relations
     * @param list<array{name: string, parameters: string}> $scopes
     */
    private function insertModelDocblock(string $source, string $shortClass, array $properties, array $accessors, array $relations, array $scopes): string
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

        $lines[] = ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> query()';
        $lines[] = ' * @method static static|null find(mixed $id, array|string $columns = ["*"])';

        foreach ($scopes as $scope) {
            if (! is_array($scope) || ! isset($scope['name'], $scope['parameters'])) {
                continue;
            }

            $lines[] = ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> ' . $scope['name'] . '(' . $scope['parameters'] . ')';
        }

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
                && $argument !== '--no-laravel-framework-overlays'
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
