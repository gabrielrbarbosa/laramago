<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$binary = $root . '/bin/laramago';
$project = sys_get_temp_dir() . '/laramago-test-' . bin2hex(random_bytes(4));

mkdir($project);
file_put_contents($project . '/composer.json', json_encode([
    'require' => [
        'php' => '^8.5',
    ],
], JSON_PRETTY_PRINT));
mkdir($project . '/app');

$exitCode = run([PHP_BINARY, $binary, 'init', '--project=' . $project]);

if ($exitCode !== 0) {
    fail('init command failed');
}

$config = file_get_contents($project . '/mago.toml');

if (! is_string($config) || ! str_contains($config, 'php-version = "8.5.0"')) {
    fail('init did not detect PHP version');
}

if (! str_contains($config, 'paths = ["app"]')) {
    fail('init did not write the default Laravel app path');
}

if (! str_contains($config, 'excludes = []')) {
    fail('init should not write application-specific default excludes');
}

if (str_contains($config, '[analyzer]') || str_contains($config, '[formatter]') || str_contains($config, '[linter]')) {
    fail('init leaked Laramago runtime defaults into project mago.toml');
}

$secondExitCode = run([PHP_BINARY, $binary, 'init', '--project=' . $project]);

if ($secondExitCode === 0) {
    fail('init overwrote an existing config without --force');
}

$clearExitCode = run([PHP_BINARY, $binary, 'clear', '--project=' . $project]);

if ($clearExitCode !== 0) {
    fail('clear command failed');
}

$doctorExitCode = run([PHP_BINARY, $binary, 'doctor', '--project=' . $project]);
$expectedDoctorExitCode = is_file($root . '/vendor/bin/mago') ? 0 : 1;

if ($doctorExitCode !== $expectedDoctorExitCode) {
    fail('doctor command returned an unexpected exit code');
}

testBaselinePathTranslation($project, $root);
testOutputPathTranslation($project, $root);
testRuntimeConfigGeneration($project, $root);
testPhpStanMigration($project, $root, $binary);
testExcludedSymbolStubGeneration($project, $root);
testRaceSafeCacheDirectoryOperations($project, $root);
testModelDocblockIncludesLaravelMagic($root);
testLaravelFrameworkOverlayGeneration($project, $root);

cleanup($project);
echo "OK\n";

/**
 * @param list<string> $command
 */
function run(array $command): int
{
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);

    if (! is_resource($process)) {
        return 1;
    }

    return proc_close($process);
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function testBaselinePathTranslation(string $project, string $root): void
{
    $cache = $project . '/.laramago/cache';
    mkdir($cache, 0777, true);

    file_put_contents($cache . '/model-overlays.json', json_encode([[
        'original' => 'app/Models/User.php',
        'overlay' => '.laramago/cache/model-overlays/abc.php',
    ]], JSON_THROW_ON_ERROR));
    file_put_contents($project . '/laramago-analyzer-baseline.toml', "file = \"app/Models/User.php\"\n");

    require_once $root . '/src/Application.php';

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'translateBaselinePaths');
    $result = $method->invoke($application, $project, false, 'laramago-analyzer-baseline.toml', '.laramago/cache/analyzer-baseline.toml');

    if ($result !== true) {
        fail('baseline path translation failed');
    }

    $translated = file_get_contents($project . '/.laramago/cache/analyzer-baseline.toml');

    if ($translated !== "file = \".laramago/cache/model-overlays/abc.php\"\n") {
        fail('baseline path translation wrote unexpected content');
    }
}

function testOutputPathTranslation(string $project, string $root): void
{
    file_put_contents($project . '/.laramago/cache/model-overlays.json', json_encode([[
        'original' => 'app/Models/User.php',
        'overlay' => '.laramago/cache/model-overlays/abc.php',
    ]], JSON_THROW_ON_ERROR));

    require_once $root . '/src/Application.php';

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'translateOutputPaths');
    $output = "error in {$project}/.laramago/cache/model-overlays/abc.php\nrelative .laramago/cache/model-overlays/abc.php\n";
    $translated = $method->invoke($application, $project, $output);

    $expected = "error in {$project}/app/Models/User.php\nrelative app/Models/User.php\n";

    if ($translated !== $expected) {
        fail('output path translation wrote unexpected content');
    }
}

function testRuntimeConfigGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'prepareRuntimeConfig');
    $runtimeConfig = $method->invoke($application, $project);

    if ($runtimeConfig !== '.laramago/cache/mago.toml') {
        fail('runtime config used an unexpected path');
    }

    $config = file_get_contents($project . '/' . $runtimeConfig);

    if (! is_string($config) || ! str_contains($config, '[analyzer]') || ! str_contains($config, '[linter]')) {
        fail('runtime config did not include Laramago defaults');
    }

    if (! str_contains($config, 'paths = ["app"]') || ! str_contains($config, 'php-version = "8.5.0"')) {
        fail('runtime config did not preserve project source settings');
    }

    if (str_contains($config, 'ignore = [')) {
        fail('runtime config should not include package-level analyzer ignores');
    }

    $levelRuntimeConfig = $method->invoke($application, $project, ['--phpstan-level=6']);
    $levelConfig = file_get_contents($project . '/' . $levelRuntimeConfig);

    foreach ([
        '"mixed-argument"',
        '"invalid-argument"',
        '"non-existent-method"',
        '"non-existent-property"',
        '"too-many-arguments"',
    ] as $expected) {
        if (! is_string($levelConfig) || ! str_contains($levelConfig, $expected)) {
            fail('runtime config missed an explicit PHPStan level 6 compatibility ignore: ' . $expected);
        }
    }
}

function testPhpStanMigration(string $project, string $root, string $binary): void
{
    file_put_contents($project . '/phpstan.neon', <<<'NEON'
includes:
    - ./vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app/
    level: 6
    excludePaths:
        - vendor/*
        - storage/*
        - database/*
        - app/Legacy/*
        - app/Services/NotaFiscal/*
NEON);

    file_put_contents($project . '/composer.json', json_encode([
        'require' => [
            'php' => '^8.5',
        ],
        'scripts' => [
            'test' => [
                '@phpstan',
                '@static-analysis',
                '@legacy-baseline',
            ],
            'phpstan' => [
                'vendor/bin/phpstan analyse',
            ],
            'static-analysis' => 'XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G',
            'strict:debug' => [
                'vendor/bin/phpstan analyze -c phpstan-ci.neon --debug',
            ],
            'legacy-baseline' => [
                'vendor/bin/phpstan analyse --generate-baseline',
            ],
            'unrelated' => [
                'echo phpstan',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $exitCode = run([PHP_BINARY, $binary, 'migrate-phpstan', '--project=' . $project, '--force', '--update-composer']);

    if ($exitCode !== 0) {
        fail('migrate-phpstan command failed');
    }

    $config = file_get_contents($project . '/mago.toml');

    if (! is_string($config) || ! str_contains($config, 'paths = ["app"]')) {
        fail('migrate-phpstan did not preserve PHPStan source paths');
    }

    if (! str_contains($config, 'excludes = ["app/Legacy/**", "app/Services/NotaFiscal/**"]')) {
        fail('migrate-phpstan did not normalize PHPStan exclude paths');
    }

    $composer = json_decode((string) file_get_contents($project . '/composer.json'), true);

    if (! is_array($composer) || ($composer['scripts']['phpstan'][0] ?? null) !== 'vendor/bin/laramago analyze --phpstan-level=6 --reporting-format=count') {
        fail('migrate-phpstan did not update the phpstan composer script');
    }

    if (($composer['scripts']['test'][0] ?? null) !== '@phpstan') {
        fail('migrate-phpstan should preserve unrelated composer scripts');
    }

    if (($composer['scripts']['test'][1] ?? null) !== '@static-analysis' || ($composer['scripts']['test'][2] ?? null) !== '@legacy-baseline') {
        fail('migrate-phpstan should preserve composer script aliases');
    }

    if (($composer['scripts']['static-analysis'] ?? null) !== 'vendor/bin/laramago analyze --phpstan-level=6 --reporting-format=count') {
        fail('migrate-phpstan did not replace a custom direct PHPStan analyze script');
    }

    if (($composer['scripts']['strict:debug'][0] ?? null) !== 'vendor/bin/laramago analyze --phpstan-level=6 --reporting-format=short') {
        fail('migrate-phpstan did not replace a custom debug PHPStan analyze script');
    }

    if (($composer['scripts']['legacy-baseline'][0] ?? null) !== 'vendor/bin/laramago baseline --phpstan-level=6') {
        fail('migrate-phpstan did not replace a PHPStan baseline script');
    }

    if (($composer['scripts']['unrelated'][0] ?? null) !== 'echo phpstan') {
        fail('migrate-phpstan should not replace non-analyze commands that merely mention phpstan');
    }

    if (($composer['scripts']['laramago:baseline'][0] ?? null) !== 'vendor/bin/laramago baseline --phpstan-level=6') {
        fail('migrate-phpstan did not add the baseline composer script');
    }

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanCompatibilityIgnores');
    $levelIgnores = $method->invoke($application, ['--phpstan-level=6']);
    $strictIgnores = $method->invoke($application, ['--phpstan-level=8']);

    if (! is_array($levelIgnores) || ! in_array('mixed-argument', $levelIgnores, true)) {
        fail('PHPStan level 6 compatibility ignores were not enabled explicitly');
    }

    if ($strictIgnores !== []) {
        fail('unsupported PHPStan levels should not enable compatibility ignores');
    }
}

function testExcludedSymbolStubGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    mkdir($project . '/app/Excluded', 0777, true);
    file_put_contents($project . '/app/Excluded/LegacyService.php', <<<'PHP'
<?php

namespace App\Excluded;

final class LegacyService
{
}
PHP);

    file_put_contents($project . '/mago.toml', <<<'TOML'
version = "1"
php-version = "8.5.0"

[source]
workspace = "."
paths = ["app"]
includes = ["vendor"]
excludes = ["app/Excluded/**"]
TOML);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'prepareRuntimeConfig');
    $runtimeConfig = $method->invoke($application, $project);
    $config = file_get_contents($project . '/' . $runtimeConfig);

    if (! is_string($config) || ! str_contains($config, 'includes = ["vendor", ".laramago/cache/excluded-symbols"]')) {
        fail('runtime config did not include excluded symbol stubs');
    }

    $stubs = glob($project . '/.laramago/cache/excluded-symbols/*.php');

    if ($stubs === false || count($stubs) !== 1) {
        fail('excluded symbol stub generation wrote an unexpected number of stubs');
    }

    $stub = file_get_contents($stubs[0]);

    if (! is_string($stub) || ! str_contains($stub, 'namespace App\Excluded;') || ! str_contains($stub, 'class LegacyService')) {
        fail('excluded symbol stub generation wrote an unexpected stub');
    }
}

function testRaceSafeCacheDirectoryOperations(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    $application = new Laramago\Application();
    $ensureDirectory = new ReflectionMethod($application, 'ensureDirectory');
    $removeDirectory = new ReflectionMethod($application, 'removeDirectory');
    $raceDirectory = $project . '/.laramago/cache/race-safe';

    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $severity . ': ' . $message;

        return true;
    });

    try {
        $ensureDirectory->invoke($application, $raceDirectory);
        $ensureDirectory->invoke($application, $raceDirectory);
        file_put_contents($raceDirectory . '/first.php', '<?php');
        $removeDirectory->invoke($application, $raceDirectory);
        $removeDirectory->invoke($application, $raceDirectory);
        $ensureDirectory->invoke($application, $raceDirectory);
        file_put_contents($raceDirectory . '/second.php', '<?php');
        unlink($raceDirectory . '/second.php');
        $removeDirectory->invoke($application, $raceDirectory);
    } finally {
        restore_error_handler();
    }

    if ($warnings !== []) {
        fail('cache directory operations emitted warnings: ' . implode('; ', $warnings));
    }

    if (is_dir($raceDirectory)) {
        fail('cache directory operations left the test directory behind');
    }
}

function testModelDocblockIncludesLaravelMagic(string $root): void
{
    require_once $root . '/src/Application.php';

    $source = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
}
PHP;

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'insertModelDocblock');
    $overlay = $method->invoke($application, $source, 'Product', [[
        'name' => 'id',
        'type' => 'int',
    ]], [[
        'name' => 'image_url',
        'type' => 'string|null',
    ]], [[
        'name' => 'orders',
        'type' => '\\Illuminate\\Database\\Eloquent\\Collection<int, \\App\\Models\\Order>',
    ]], [[
        'name' => 'active',
        'parameters' => 'bool $onlyVisible = null',
    ]]);

    if (! is_string($overlay)) {
        fail('model docblock overlay did not return source');
    }

    foreach ([
        '@property int $id',
        '@property-read string|null $image_url',
        '@property-read \\Illuminate\\Database\\Eloquent\\Collection<int, \\App\\Models\\Order> $orders',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> active(bool $onlyVisible = null)',
    ] as $expected) {
        if (! str_contains($overlay, $expected)) {
            fail('model docblock overlay missed expected Laravel magic: ' . $expected);
        }
    }
}

function testLaravelFrameworkOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    mkdir($project . '/config', 0777, true);
    mkdir($project . '/vendor/maatwebsite/excel/src/Concerns', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Contracts/Auth', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Support/Facades', 0777, true);

    file_put_contents($project . '/config/auth.php', <<<'PHP'
<?php

use App\Models\Usuario\Usuario;

return [
    'providers' => [
        'users' => [
            'model' => Usuario::class,
        ],
    ],
];
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Contracts/Auth/Guard.php', '<?php');

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Support/Facades/Auth.php', '<?php');

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/HasFactory.php', '<?php');

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Scope.php', '<?php');

    file_put_contents($project . '/vendor/maatwebsite/excel/src/Concerns/FromCollection.php', '<?php');

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'laravelFrameworkSubstitutions');
    $substitutions = $method->invoke($application, $project, []);

    if (! is_array($substitutions) || count($substitutions) !== 10) {
        fail('framework overlay generation returned unexpected substitutions');
    }

    $guardOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Guard.php');
    $authOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Auth.php');
    $hasFactoryOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/HasFactory.php');
    $scopeOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Scope.php');
    $fromCollectionOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/FromCollection.php');

    if (! is_string($guardOverlay) || ! str_contains($guardOverlay, '@return \\App\\Models\\Usuario\\Usuario|null')) {
        fail('guard overlay did not use the configured auth model');
    }

    if (! is_string($authOverlay) || ! str_contains($authOverlay, '@method static \\App\\Models\\Usuario\\Usuario|null user()')) {
        fail('auth facade overlay did not use the configured auth model');
    }

    if (str_contains($authOverlay, 'Laravel\\Ui\\UiServiceProvider')) {
        fail('auth facade overlay leaked optional vendor implementation details');
    }

    if (! is_string($hasFactoryOverlay) || ! str_contains($hasFactoryOverlay, '@return \\Illuminate\\Database\\Eloquent\\Factories\\Factory<static>')) {
        fail('HasFactory overlay did not expose a static model factory return type');
    }

    if (str_contains($hasFactoryOverlay, '@template')) {
        fail('HasFactory overlay still requires application models to pass a trait template parameter');
    }

    if (! is_string($scopeOverlay) || str_contains($scopeOverlay, '@template')) {
        fail('Scope overlay still requires application scopes to pass a template parameter');
    }

    if (! is_string($fromCollectionOverlay) || str_contains($fromCollectionOverlay, '@template')) {
        fail('FromCollection overlay still requires exports to pass template parameters');
    }

    $disabled = $method->invoke($application, $project, ['--no-laravel-framework-overlays']);

    if ($disabled !== []) {
        fail('framework overlays were not disabled by option');
    }
}

function cleanup(string $path): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isDir()) {
            rmdir($file->getPathname());

            continue;
        }

        if ($file instanceof SplFileInfo) {
            unlink($file->getPathname());
        }
    }

    rmdir($path);
}
