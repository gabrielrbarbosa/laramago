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
