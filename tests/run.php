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

$secondExitCode = run([PHP_BINARY, $binary, 'init', '--project=' . $project]);

if ($secondExitCode === 0) {
    fail('init overwrote an existing config without --force');
}

$clearExitCode = run([PHP_BINARY, $binary, 'clear', '--project=' . $project]);

if ($clearExitCode !== 0) {
    fail('clear command failed');
}

$doctorExitCode = run([PHP_BINARY, $binary, 'doctor', '--project=' . $project]);

if ($doctorExitCode !== 1) {
    fail('doctor command should fail when Mago is unavailable in the test project');
}

testBaselinePathTranslation($project, $root);
testOutputPathTranslation($project, $root);

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
