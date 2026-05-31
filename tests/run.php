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

testDoctorTreatsMissingBaselineAsOptional($project, $binary);
testBaselinePathTranslation($project, $root);
testOutputPathTranslation($project, $root);
testRuntimeConfigGeneration($project, $root);
testNativeMagoBinaryIsPreferred($project, $root);
testPhpStanMigration($project, $root, $binary);
testExcludedSymbolStubGeneration($project, $root);
testRaceSafeCacheDirectoryOperations($project, $root);
testProjectLockSerializesCacheCommands($project, $binary);
testProjectClassDiscoveryUsesConfiguredSourcePaths($project, $root);
testAnalysisIgnoresStaleRuntimeBaseline($project, $root);
testPhpStanPragmaOverlayGeneration($project, $root);
testLaravelDateHelperOverlayGeneration($project, $root);
testLaravelCollectionMacroOverlayGeneration($project, $root);
testLaravelExcelEventOverlayGeneration($project, $root);
testLaravelQueryBuilderClosureOverlayGeneration($project, $root);
testLaravelRequestPropertyReadOverlayGeneration($project, $root);
testLaravelJsonResourceDynamicMemberOverlayGeneration($project, $root);
testLaravelFormRequestDynamicPropertyOverlayGeneration($project, $root);
testCaseInsensitiveOverlaySkipsSingleAliasFiles($project, $root);
testCaseInsensitiveOverlayRespectsExcludes($project, $root);
testTraitSelfCallOverlayGeneration($project, $root);
testLaravelMetadataInferenceHelpers($root);
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

/**
 * @param list<string> $command
 * @return array{exitCode: int, output: string}
 */
function captureRun(array $command): array
{
    $process = proc_open($command, [
        0 => STDIN,
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (! is_resource($process)) {
        return ['exitCode' => 1, 'output' => ''];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exitCode' => proc_close($process),
        'output' => (is_string($stdout) ? $stdout : '') . (is_string($stderr) ? $stderr : ''),
    ];
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function testDoctorTreatsMissingBaselineAsOptional(string $project, string $binary): void
{
    $result = captureRun([PHP_BINARY, $binary, 'doctor', '--project=' . $project]);
    $output = $result['output'];

    if (str_contains($output, 'WARN laramago-analyzer-baseline.toml is missing')) {
        fail('doctor should not warn when a project runs without a baseline');
    }

    if (! str_contains($output, 'OK   No Laramago baseline configured; analysis will run without one.')) {
        fail('doctor did not explain that a missing baseline is valid');
    }
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

    mkdir($project . '/database/seeders', 0777, true);
    file_put_contents($project . '/composer.json', json_encode([
        'require' => [
            'php' => '^8.5',
        ],
        'autoload' => [
            'psr-4' => [
                'App\\' => 'app/',
            ],
        ],
        'autoload-dev' => [
            'psr-4' => [
                'Database\\Seeders\\' => 'database/seeders/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

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

    if (! str_contains($config, 'includes = ["vendor", "database/seeders"]')) {
        fail('runtime config did not include Composer autoload paths outside the analyzed source paths');
    }

    if (! str_contains($config, 'find-unused-definitions = true')) {
        fail('runtime config should keep strict unused definition checks by default');
    }

    if (! str_contains($config, '{ code = "unused-pragma", in = ".laramago/cache/model-overlays/" }')) {
        fail('runtime config should ignore unused generated model overlay pragmas');
    }

    if (! str_contains($config, '{ code = "unused-pragma", in = ".laramago/cache/phpstan-pragma-overlays/" }')) {
        fail('runtime config should ignore unused generated PHPStan pragma compatibility overlays');
    }

    foreach ([
        'too-few-arguments',
        'missing-template-parameter',
        'invalid-template-parameter',
        'ambiguous-class-like-constant-access',
        'possibly-static-access-on-interface',
        'invalid-param-tag',
    ] as $code) {
        if (! str_contains($config, '{ code = "' . $code . '", in = ".laramago/cache/framework-overlays/" }')) {
            fail('runtime config should ignore generated framework overlay implementation noise: ' . $code);
        }
    }

    if (str_contains($config, '"mixed-argument"')) {
        fail('runtime config should not include PHPStan level ignores unless explicitly requested');
    }

    $levelRuntimeConfig = $method->invoke($application, $project, ['--phpstan-level=6']);
    $levelConfig = file_get_contents($project . '/' . $levelRuntimeConfig);

    foreach ([
        '"mixed-argument"',
        '"invalid-argument"',
        '"invalid-array-element"',
        '"dynamic-static-method-call"',
        '"docblock-type-mismatch"',
        '"incompatible-return-type"',
        '"match-not-exhaustive"',
        '"missing-return-statement"',
        '"invalid-array-index"',
        '"invalid-operand"',
        '"invalid-property-assignment-value"',
        '"invalid-property-read"',
        '"invalid-type-cast"',
        '"incompatible-parameter-name"',
        '"null-operand"',
        '"non-existent-method"',
        '"never-return"',
        '"non-existent-property"',
        '"possibly-null-array-access"',
        '"possibly-false-operand"',
        '"reference-reused-from-confusing-scope"',
        '"redundant-cast"',
        '"too-many-arguments"',
        '"undefined-variable"',
        '"unreachable-else-clause"',
    ] as $expected) {
        if (! is_string($levelConfig) || ! str_contains($levelConfig, $expected)) {
            fail('runtime config missed an explicit PHPStan level 6 compatibility ignore: ' . $expected);
        }
    }

    if (! str_contains($levelConfig, 'find-unused-definitions = false')) {
        fail('PHPStan level 6 compatibility should disable Mago unused definition checks');
    }
}

function testNativeMagoBinaryIsPreferred(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    $nativeDirectory = $project . '/vendor/carthage-software/mago/composer/bin/1.29.0/mago-1.29.0-x86_64-unknown-linux-gnu';
    $proxyDirectory = $project . '/vendor/bin';
    mkdir($nativeDirectory, 0777, true);
    mkdir($proxyDirectory, 0777, true);
    file_put_contents($nativeDirectory . '/mago', '#!/bin/sh');
    file_put_contents($proxyDirectory . '/mago', '#!/usr/bin/env php');
    chmod($nativeDirectory . '/mago', 0755);
    chmod($proxyDirectory . '/mago', 0755);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'findMagoBinary');
    $binary = $method->invoke($application, $project);

    if ($binary !== $nativeDirectory . '/mago') {
        fail('Laramago should prefer the native Mago binary over the Composer PHP proxy');
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
        analyse:
            - vendor/*
            - storage/*
            - database/*
            - app/Legacy/*
        analyseAndScan:
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

    file_put_contents($project . '/phpstan.neon', <<<'NEON'
parameters:
    paths:
        - app/
    level: 8
NEON);

    file_put_contents($project . '/composer.json', json_encode([
        'require' => [
            'php' => '^8.5',
        ],
        'scripts' => [
            'phpstan' => 'vendor/bin/phpstan analyse',
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $levelEightExitCode = run([PHP_BINARY, $binary, 'migrate-phpstan', '--project=' . $project, '--force', '--update-composer']);

    if ($levelEightExitCode !== 0) {
        fail('migrate-phpstan level 8 command failed');
    }

    $levelEightComposer = json_decode((string) file_get_contents($project . '/composer.json'), true);

    if (! is_array($levelEightComposer) || ($levelEightComposer['scripts']['phpstan'][0] ?? null) !== 'vendor/bin/laramago analyze --phpstan-level=8 --reporting-format=count') {
        fail('migrate-phpstan did not preserve a non-level-6 PHPStan strictness level');
    }

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanCompatibilityIgnores');
    $levelIgnores = $method->invoke($application, ['--phpstan-level=6']);
    $levelEightIgnores = $method->invoke($application, ['--phpstan-level=8']);
    $maxIgnores = $method->invoke($application, ['--phpstan-level=max']);
    $unsupportedIgnores = $method->invoke($application, ['--phpstan-level=custom']);

    if (! is_array($levelIgnores) || ! in_array('mixed-argument', $levelIgnores, true)) {
        fail('PHPStan level 6 compatibility ignores were not enabled explicitly');
    }

    if (! is_array($levelEightIgnores) || ! in_array('mixed-argument', $levelEightIgnores, true) || in_array('possibly-null-argument', $levelEightIgnores, true)) {
        fail('PHPStan level 8 compatibility should keep mixed compatibility while reporting nullable issues');
    }

    if ($maxIgnores !== []) {
        fail('PHPStan max compatibility should use native Mago strictness');
    }

    if ($unsupportedIgnores !== []) {
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

final class LegacyService extends BaseService implements LegacyContract
{
    public function __construct(public int $tenantId = 0)
    {
    }

    public function getUser(int $id): mixed
    {
        return null;
    }
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

    if (! is_string($stub) || ! str_contains($stub, 'namespace App\Excluded;') || ! str_contains($stub, 'final class LegacyService extends BaseService implements LegacyContract') || ! str_contains($stub, 'public function __construct(public int $tenantId = 0) {}') || ! str_contains($stub, 'public function getUser(int $id): mixed {}')) {
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

function testProjectLockSerializesCacheCommands(string $project, string $binary): void
{
    $lockDirectory = $project . '/.laramago';
    $lockPath = $lockDirectory . '/laramago.lock';

    if (! is_dir($lockDirectory)) {
        mkdir($lockDirectory, 0777, true);
    }

    $process = proc_open([
        PHP_BINARY,
        '-r',
        <<<'PHP'
$handle = fopen($argv[1], 'c');
flock($handle, LOCK_EX);
fwrite(STDOUT, "locked\n");
sleep(1);
flock($handle, LOCK_UN);
fclose($handle);
PHP,
        $lockPath,
    ], [
        0 => STDIN,
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (! is_resource($process)) {
        fail('unable to start lock holder process');
    }

    $ready = fgets($pipes[1]);

    if ($ready !== "locked\n") {
        proc_close($process);
        fail('lock holder process did not acquire the lock');
    }

    $startedAt = microtime(true);
    $result = captureRun([PHP_BINARY, $binary, 'clear', '--project=' . $project]);
    $elapsed = microtime(true) - $startedAt;
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $lockHolderExitCode = proc_close($process);

    if ($lockHolderExitCode !== 0 || (is_string($stderr) && $stderr !== '')) {
        fail('lock holder process failed');
    }

    if ($result['exitCode'] !== 0) {
        fail('clear command failed while waiting for the project lock');
    }

    if ($elapsed < 0.75) {
        fail('project lock did not serialize cache commands');
    }
}

function testProjectClassDiscoveryUsesConfiguredSourcePaths(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    mkdir($project . '/app/Domain/Billing', 0777, true);
    mkdir($project . '/app/Models', 0777, true);
    file_put_contents($project . '/mago.toml', <<<'TOML'
version = "1"
php-version = "8.5.0"

[source]
workspace = "."
paths = ["app"]
includes = ["vendor"]
excludes = []
TOML);

    file_put_contents($project . '/app/Domain/Billing/Invoice.php', <<<'PHP'
<?php

namespace App\Domain\Billing;

final class Invoice
{
}
PHP);

    file_put_contents($project . '/app/Models/ReadonlyModel.php', <<<'PHP'
<?php

namespace App\Models;

readonly class ReadonlyModel
{
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'discoverProjectClasses');
    $classes = $method->invoke($application, $project);

    if (! is_array($classes)) {
        fail('project class discovery returned an unexpected value');
    }

    $files = array_column($classes, 'file');

    if (! in_array('app/Domain/Billing/Invoice.php', $files, true)) {
        fail('project class discovery missed a class outside app/Models');
    }

    if (! in_array('app/Models/ReadonlyModel.php', $files, true)) {
        fail('project class discovery missed a readonly class');
    }
}

function testAnalysisIgnoresStaleRuntimeBaseline(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    @unlink($project . '/laramago-analyzer-baseline.toml');
    if (! is_dir($project . '/.laramago/cache')) {
        mkdir($project . '/.laramago/cache', 0777, true);
    }
    file_put_contents($project . '/.laramago/cache/analyzer-baseline.toml', "file = \"stale.php\"\n");

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'defaultAnalyzeFlags');
    $flags = $method->invoke($application, $project, [], true);

    if ($flags !== ['--ignore-baseline']) {
        fail('analysis should explicitly ignore baselines when no project baseline is configured');
    }

    if (is_file($project . '/.laramago/cache/analyzer-baseline.toml')) {
        fail('analysis should remove stale runtime baselines when no project baseline is configured');
    }
}

function testPhpStanPragmaOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/PhpStanIgnored.php', <<<'PHP'
<?php

namespace App;

final class PhpStanIgnored
{
    public function getUser(): mixed
    {
        return null;
    }

    public function nextLine(): void
    {
        // @phpstan-ignore-next-line
        $this->getuser();
    }

    public function sameLine(): mixed
    {
        return $this->missing(); // @phpstan-ignore-line
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $substitutions = $method->invoke($application, $project, [], []);

    if (! is_array($substitutions) || count($substitutions) !== 2) {
        fail('PHPStan pragma overlay generation returned unexpected substitutions');
    }

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    if (! is_array($map) || ($map[0]['original'] ?? null) !== 'app/PhpStanIgnored.php' || ! is_string($map[0]['overlay'] ?? null)) {
        fail('PHPStan pragma overlay generation wrote an unexpected path map');
    }

    $overlay = file_get_contents($project . '/' . $map[0]['overlay']);

    if (! is_string($overlay) || substr_count($overlay, '@mago-ignore all') !== 2) {
        fail('PHPStan pragma overlay generation did not translate ignore comments');
    }

    if (! str_contains($overlay, '@method mixed getuser(mixed ...$arguments)')) {
        fail('source compatibility overlay did not add case-insensitive method aliases');
    }
}

function testLaravelDateHelperOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    mkdir($project . '/routes', 0777, true);

    file_put_contents($project . '/app/UsesDateHelpers.php', <<<'PHP'
<?php

namespace App;

final class UsesDateHelpers
{
    public function handle(): mixed
    {
        $now = now()->subDays(1);
        $today = today();
        $response = response(['ok' => true], 202)->withHeaders(['X-Test' => '1']);
        $factory = response()->json(['ok' => true]);
        $method = $this->now();
        $static = self::today();

        return [$now, $today, $response, $factory, $method, $static];
    }

    private function now(): mixed
    {
        return null;
    }

    private static function today(): mixed
    {
        return null;
    }
}
PHP);

    file_put_contents($project . '/routes/console.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('activity_log')->where('created_at', '<', now()->subYear())->delete();
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, ['routes'], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);
    $foundAppOverlay = false;
    $foundRoutesOverlay = false;

    foreach (is_array($map) ? $map : [] as $entry) {
        if (! is_string($entry['original'] ?? null) || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (($entry['original'] ?? null) === 'app/UsesDateHelpers.php'
            && is_string($overlay)
            && str_contains($overlay, '\\Illuminate\\Support\\Carbon::now()->subDays(1)')
            && str_contains($overlay, '\\Illuminate\\Support\\Carbon::today()')
            && str_contains($overlay, '\\Illuminate\\Support\\Facades\\Response::make([\'ok\' => true], 202)->withHeaders')
            && str_contains($overlay, 'response()->json([\'ok\' => true])')
            && str_contains($overlay, '$this->now()')
            && str_contains($overlay, 'self::today()')) {
            $foundAppOverlay = true;
        }

        if (($entry['original'] ?? null) === 'routes/console.php'
            && is_string($overlay)
            && str_contains($overlay, '\\Illuminate\\Support\\Carbon::now()->subYear()')) {
            $foundRoutesOverlay = true;
        }
    }

    if (! $foundAppOverlay || ! $foundRoutesOverlay) {
        fail('Laravel date helper overlay did not rewrite global helper calls safely');
    }
}

function testLaravelCollectionMacroOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/DefinesCollectionMacro.php', <<<'PHP'
<?php

namespace App;

use Illuminate\Support\Collection;

final class DefinesCollectionMacro
{
    public function boot(): void
    {
        Collection::macro('paginate', function ($perPage): mixed {
            return $this->forPage(1, $perPage)->values();
        });
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/DefinesCollectionMacro.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '/** @var \Illuminate\Support\Collection $this */')
            && str_contains($overlay, '$this->forPage(1, $perPage)->values()')) {
            return;
        }
    }

    fail('Laravel collection macro overlay did not annotate closure $this safely');
}

function testLaravelExcelEventOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    if (! is_dir($project . '/app/Exports')) {
        mkdir($project . '/app/Exports', 0777, true);
    }

    file_put_contents($project . '/app/Exports/UsesExcelEvents.php', <<<'PHP'
<?php

namespace App\Exports;

use Maatwebsite\Excel\Events\AfterSheet;

final class UsesExcelEvents
{
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function ($event): void {
                $event->sheet->freezePane('A2');
            },
        ];
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/Exports/UsesExcelEvents.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '/** @var \Maatwebsite\Excel\Events\AfterSheet $event */')
            && str_contains($overlay, '$event->sheet->freezePane')) {
            return;
        }
    }

    fail('Laravel Excel event overlay did not annotate event callback variables');
}

function testLaravelQueryBuilderClosureOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/UsesQueryBuilderClosures.php', <<<'PHP'
<?php

namespace App;

final class UsesQueryBuilderClosures
{
    public function apply(mixed $builder): mixed
    {
        return $builder->whereIn('user_id', function ($sub) {
            $sub->from('users')->select('id');
        })->where(function ($query): void {
            $query->whereExists(function ($nested): void {
                $nested->from('orders')->select('id');
            });
        });
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/UsesQueryBuilderClosures.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '/** @var \Illuminate\Database\Query\Builder $sub */')
            && str_contains($overlay, '/** @var \Illuminate\Database\Query\Builder $query */')
            && str_contains($overlay, '/** @var \Illuminate\Database\Query\Builder $nested */')) {
            return;
        }
    }

    fail('Laravel query builder closure overlay did not annotate nested builder callbacks');
}

function testLaravelRequestPropertyReadOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    mkdir($project . '/app/Http/Controllers', 0777, true);

    file_put_contents($project . '/app/Http/Controllers/WithSearchPagination.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

trait WithSearchPagination
{
    private Request $requestPagination;
}
PHP);

    file_put_contents($project . '/app/Http/Controllers/SearchController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SearchController
{
    use WithSearchPagination;

    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->requestPagination = $request;
    }

    public function index(Request $request): mixed
    {
        $value = $request->search;
        $stored = $this->request->per_page;
        $sortBy = $this->requestPagination->sortBy;
        $hasSearch = isset($request->search);
        $request->search = 'changed';

        return [$value, $stored, $sortBy, $hasSearch];
    }

    public function helper(mixed $request): array
    {
        return [
            $request->input('name'),
            $request->method,
        ];
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/Http/Controllers/SearchController.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '$value = $request->input(\'search\');')
            && str_contains($overlay, '$stored = $this->request->input(\'per_page\');')
            && str_contains($overlay, '$sortBy = $this->requestPagination->input(\'sortBy\');')
            && str_contains($overlay, '$hasSearch = isset($request->search);')
            && str_contains($overlay, '$request->search = \'changed\';')
            && str_contains($overlay, 'public function helper(\Illuminate\Http\Request $request): array')
            && str_contains($overlay, '$request->input(\'method\')')) {
            return;
        }
    }

    fail('Laravel request property read overlay did not rewrite dynamic input access safely');
}

function testLaravelJsonResourceDynamicMemberOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    mkdir($project . '/app/Http/Resources', 0777, true);

    file_put_contents($project . '/app/Http/Resources/OrderResource.php', <<<'PHP'
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'issued_at' => $this->issued_at->format('Y-m-d'),
            'status_label' => $this->statusLabel(),
            'resource' => $this->resource,
        ];
    }
}
PHP);

    file_put_contents($project . '/app/Http/Resources/OrderCollection.php', <<<'PHP'
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class OrderCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        $orders = $this->resource;

        return $orders
            ->getCollection()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'issued_at' => $order->issued_at->format('Y-m-d'),
                ];
            })
            ->toArray();
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    $foundResource = false;
    $foundCollection = false;

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/Http/Resources/OrderResource.php' || ! is_string($entry['overlay'] ?? null)) {
            if (($entry['original'] ?? null) !== 'app/Http/Resources/OrderCollection.php' || ! is_string($entry['overlay'] ?? null)) {
                continue;
            }

            $overlay = file_get_contents($project . '/' . $entry['overlay']);

            if (is_string($overlay)
                && str_contains($overlay, '/** @var \Illuminate\Pagination\AbstractPaginator $orders */')
                && str_contains($overlay, '/** @var \Illuminate\Database\Eloquent\Model $order */')
                && str_contains($overlay, '$order->issued_at->format(\'Y-m-d\')')) {
                $foundCollection = true;
            }

            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '@mixin \Illuminate\Database\Eloquent\Model')
            && str_contains($overlay, '@property mixed $id')
            && str_contains($overlay, '@property mixed $issued_at')
            && ! str_contains($overlay, '@property mixed $resource')
            && str_contains($overlay, '@method mixed statusLabel(mixed ...$parameters)')) {
            $foundResource = true;
        }
    }

    if (! $foundResource || ! $foundCollection) {
        fail('Laravel JsonResource overlay did not document delegated resource members');
    }
}

function testLaravelFormRequestDynamicPropertyOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    mkdir($project . '/app/Http/Requests/Orders', 0777, true);

    file_put_contents($project . '/app/Http/Requests/Orders/TracksTenant.php', <<<'PHP'
<?php

namespace App\Http\Requests\Orders;

trait TracksTenant
{
    private int $clienteSistemaId;
}
PHP);

    file_put_contents($project . '/app/Http/Requests/Orders/StoreOrderRequest.php', <<<'PHP'
<?php

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    use TracksTenant;

    public function prepareForValidation(): void
    {
        $local = new \stdClass();
        $this->service = new \stdClass();
        $this->merge(['product' => $this->product]);
    }

    public function rules(): array
    {
        return [
            'quantity' => "lte:{$this->quantityLimit}",
        ];
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/Http/Requests/Orders/StoreOrderRequest.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '@property mixed $service')
            && str_contains($overlay, '@property mixed $product')
            && str_contains($overlay, '@property mixed $quantityLimit')
            && ! str_contains($overlay, '@property mixed $local')
            && ! str_contains($overlay, '@property mixed $clienteSistemaId')
            && str_contains($overlay, 'public function __set(string $key, mixed $value): void')) {
            return;
        }
    }

    fail('Laravel FormRequest overlay did not document dynamic request properties');
}

function testCaseInsensitiveOverlaySkipsSingleAliasFiles(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/OneAlias.php', <<<'PHP'
<?php

namespace App;

final class OneAlias
{
    public function getUser(): mixed
    {
        return null;
    }

    public function run(): void
    {
        $this->getUser();
    }
}
PHP);

    file_put_contents($project . '/app/TwoAliases.php', <<<'PHP'
<?php

namespace App;

final class TwoAliases
{
    public function getUser(): mixed
    {
        return null;
    }

    public function getToken(): mixed
    {
        return null;
    }

    public function run(): void
    {
        $this->getUser();
        $this->getToken();
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);
    $originals = array_column(is_array($map) ? $map : [], 'original');

    if (in_array('app/OneAlias.php', $originals, true)) {
        fail('case-insensitive overlay should skip source files with only one alias');
    }

    if (! in_array('app/TwoAliases.php', $originals, true)) {
        fail('case-insensitive overlay should include source files with multiple aliases');
    }
}

function testCaseInsensitiveOverlayRespectsExcludes(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    $configPath = $project . '/mago.toml';
    $originalConfig = file_get_contents($configPath);

    if (! is_string($originalConfig)) {
        fail('unable to read test project config');
    }

    file_put_contents($configPath, <<<'TOML'
version = "1"
php-version = "8.5.0"

[source]
workspace = "."
paths = ["app"]
includes = []
excludes = ["app/Excluded/**"]

[source.glob]
literal-separator = true
TOML);

    if (! is_dir($project . '/app/Excluded')) {
        mkdir($project . '/app/Excluded');
    }
    file_put_contents($project . '/app/Excluded/TwoAliases.php', <<<'PHP'
<?php

namespace App\Excluded;

final class TwoAliases
{
    public function getUser(): mixed
    {
        return null;
    }

    public function getToken(): mixed
    {
        return null;
    }

    public function run(): void
    {
        $this->getUser();
        $this->getToken();
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    file_put_contents($configPath, $originalConfig);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);
    $originals = array_column(is_array($map) ? $map : [], 'original');

    if (in_array('app/Excluded/TwoAliases.php', $originals, true)) {
        fail('case-insensitive overlay should not substitute excluded files');
    }
}

function testTraitSelfCallOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/RequiresHostMethods.php', <<<'PHP'
<?php

namespace App;

trait RequiresHostMethods
{
    public function indexName(): string
    {
        return $this->getTable();
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/RequiresHostMethods.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay) && str_contains($overlay, '@method mixed gettable(mixed ...$arguments)')) {
            return;
        }
    }

    fail('trait self-call overlay did not declare host-provided methods');
}

function testLaravelMetadataInferenceHelpers(string $root): void
{
    if (! class_exists('Illuminate\\Database\\Eloquent\\Model')) {
        eval('namespace Illuminate\\Database\\Eloquent; class Model {} class Collection {}');
    }

    if (! class_exists('Illuminate\\Database\\Eloquent\\Relations\\Relation')) {
        eval('namespace Illuminate\\Database\\Eloquent\\Relations; class Relation {} class HasManyThrough extends Relation {} class MorphTo extends Relation {}');
    }

    if (! class_exists('Illuminate\\Database\\Eloquent\\Attributes\\Scope')) {
        eval('namespace Illuminate\\Database\\Eloquent\\Attributes; #[\\Attribute(\\Attribute::TARGET_METHOD)] class Scope {}');
    }

    if (! class_exists('App\\Models\\Order')) {
        eval('namespace App\\Models; class Order extends \\Illuminate\\Database\\Eloquent\\Model {}');
    }

    if (! enum_exists('LaramagoMetadataStatus')) {
        eval('enum LaramagoMetadataStatus: string { case Draft = "draft"; }');
    }

    require_once $root . '/resources/laravel-model-metadata.php';

    $propertyCases = [
        ['immutable_datetime', 'datetime', false, '\\Carbon\\CarbonImmutable'],
        ['encrypted:array', 'json', true, 'array|null'],
        ['collection', 'json', false, '\\Illuminate\\Support\\Collection'],
        ['Illuminate\\Database\\Eloquent\\Casts\\AsArrayObject', 'json', false, '\\ArrayObject'],
        ['LaramagoMetadataStatus', 'varchar', false, '\\LaramagoMetadataStatus'],
    ];

    foreach ($propertyCases as [$cast, $databaseType, $nullable, $expected]) {
        $actual = propertyType($cast, $databaseType, $nullable);

        if ($actual !== $expected) {
            fail("property type inference returned {$actual}; expected {$expected}");
        }
    }

    $hasManyThrough = relationType('Illuminate\\Database\\Eloquent\\Relations\\HasManyThrough', 'App\\Models\\Order');

    if ($hasManyThrough !== '\\Illuminate\\Database\\Eloquent\\Collection<int, \\App\\Models\\Order>') {
        fail('HasManyThrough relation type inference returned an unexpected type');
    }

    $morphTo = relationType('Illuminate\\Database\\Eloquent\\Relations\\MorphTo', 'App\\Models\\Order');

    if ($morphTo !== '\\Illuminate\\Database\\Eloquent\\Model|null') {
        fail('MorphTo relation type inference returned an unexpected type');
    }

    if (! trait_exists('LaramagoScopeFixtureTrait')) {
        eval('trait LaramagoScopeFixtureTrait { protected function scopeUseIndex(mixed $query, array|string $index): mixed { return $query; } }');
    }

    if (! class_exists('LaramagoScopeFixtureModel')) {
        eval('class LaramagoScopeFixtureModel extends \\Illuminate\\Database\\Eloquent\\Model { use \\LaramagoScopeFixtureTrait; #[\\Illuminate\\Database\\Eloquent\\Attributes\\Scope] protected function active(mixed $query): mixed { return $query; } }');
    }

    $scopes = modelScopes('LaramagoScopeFixtureModel');

    if (! in_array([
        'name' => 'useIndex',
        'parameters' => 'array|string $index',
    ], $scopes, true)) {
        fail('model scope discovery missed a trait-defined local scope');
    }

    if (! in_array([
        'name' => 'active',
        'parameters' => '',
    ], $scopes, true)) {
        fail('model scope discovery missed an attribute-defined protected scope');
    }
}

function testModelDocblockIncludesLaravelMagic(string $root): void
{
    require_once $root . '/src/Application.php';

    $source = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Existing model annotations should survive generated overlays.
 *
 * @mixin \Illuminate\Database\Eloquent\Builder<Product>
 * @method static \Illuminate\Database\Eloquent\Builder<Product> visible()
 */
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
    ]], true);

    if (! is_string($overlay)) {
        fail('model docblock overlay did not return source');
    }

    foreach ([
        '@property int $id',
        '@property-read string|null $image_url',
        '@property-read \\Illuminate\\Database\\Eloquent\\Collection<int, \\App\\Models\\Order> $orders',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = "and")',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereIn(string $column, mixed $values, string $boolean = "and", bool $not = false)',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> leftJoin(string $table, mixed $first, ?string $operator = null, mixed $second = null)',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> groupBy(array|string ...$groups)',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> with(array|string ...$relations)',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> withCount(array|string $relations)',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> select(mixed ...$columns)',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> orderBy(mixed $column, mixed $direction = "asc")',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> lockForUpdate()',
        '@method static self create(array $attributes = null)',
        '@method static static|null first(array|string $columns = ["*"])',
        '@method static self firstOrFail(array|string $columns = ["*"])',
        '@method static self firstOrCreate(array $attributes = null, array $values = null)',
        '@method static self updateOrCreate(array $attributes, array $values = null)',
        '@method static self findOrFail(mixed $id, array|string $columns = ["*"])',
        '@method static \\Illuminate\\Database\\Eloquent\\Collection get(array|string $columns = ["*"])',
        '@method static \\Illuminate\\Support\\Collection pluck(string $column, mixed $key = null)',
        '@method static bool exists()',
        '@method static bool insert(array $values)',
        '@method \\Laravel\\Sanctum\\NewAccessToken createToken(string $name, array $abilities = ["*"], ?\\DateTimeInterface $expiresAt = null)',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> active(bool $onlyVisible = null)',
        '@mixin \\Illuminate\\Database\\Eloquent\\Builder<Product>',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<Product> visible()',
        'public static function where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = \'and\')',
        'public static function select(mixed ...$columns)',
        'public static function withoutglobalscopes(?array $scopes = null)',
    ] as $expected) {
        if (! str_contains($overlay, $expected)) {
            fail('model docblock overlay missed expected Laravel magic: ' . $expected);
        }
    }

    if (substr_count($overlay, '/**') !== 1) {
        fail('model docblock overlay should merge with the existing class docblock');
    }

    if (str_contains($overlay, '@method static int delete()')) {
        fail('model docblock overlay should not shadow the instance delete method');
    }

    if (strpos($overlay, '@mixin \\Illuminate\\Database\\Eloquent\\Builder<Product>') > strpos($overlay, '@laramago-generated')) {
        fail('model docblock overlay should preserve existing annotations before generated metadata');
    }

    $attributedSource = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

#[ScopedBy([])]
class AttributedProduct extends Model
{
}
PHP;

    $attributedOverlay = $method->invoke($application, $attributedSource, 'AttributedProduct', [[
        'name' => 'id',
        'type' => 'int',
    ]], [], [], [], false);

    if (! is_string($attributedOverlay)
        || strpos($attributedOverlay, '@property int $id') === false
        || strpos($attributedOverlay, '@property int $id') > strpos($attributedOverlay, '#[ScopedBy([])]')) {
        fail('model docblock overlay should be inserted before class attributes');
    }
}

function testLaravelFrameworkOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    mkdir($project . '/config', 0777, true);
    mkdir($project . '/vendor/maatwebsite/excel/src/Concerns', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Database/Query', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Auth', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Contracts/Broadcasting', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Contracts/Auth', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Contracts/Foundation', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Foundation', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Notifications', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Routing', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Support/Facades', 0777, true);
    mkdir($project . '/vendor/laravel/socialite/src/Contracts', 0777, true);
    mkdir($project . '/vendor/laravel/socialite/src/Two', 0777, true);
    mkdir($project . '/vendor/nesbot/carbon/src/Carbon', 0777, true);

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

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Contracts/Broadcasting/ShouldBroadcast.php', <<<'PHP'
<?php

namespace Illuminate\Contracts\Broadcasting;

interface ShouldBroadcast
{
    /**
     * @return \Illuminate\Broadcasting\Channel|\Illuminate\Broadcasting\Channel[]|string[]|string
     */
    public function broadcastOn();
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Auth/AuthManager.php', <<<'PHP'
<?php

namespace Illuminate\Auth;

/**
 * @mixin \Illuminate\Contracts\Auth\StatefulGuard
 */
class AuthManager
{
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Support/Facades/Auth.php', '<?php');

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Contracts/Foundation/Application.php', <<<'PHP'
<?php

namespace Illuminate\Contracts\Foundation;

interface Application
{
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Foundation/helpers.php', <<<'PHP'
<?php

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Carbon\CarbonInterface;

if (! function_exists('auth')) {
    /**
     * Get the available auth instance.
     *
     * @param  string|null  $guard
     * @return ($guard is null ? \Illuminate\Contracts\Auth\Factory : \Illuminate\Contracts\Auth\Guard)
     */
    function auth($guard = null): AuthFactory|Guard
    {
    }
}

if (! function_exists('now')) {
    function now($tz = null): CarbonInterface
    {
    }
}

if (! function_exists('today')) {
    function today($tz = null): CarbonInterface
    {
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Support/Facades/Http.php', <<<'PHP'
<?php

namespace Illuminate\Support\Facades;

/**
 * @method static \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface get(string $url, array|string|null $query = null)
 * @method static \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface post(string $url, array|\JsonSerializable|\Illuminate\Contracts\Support\Arrayable $data = [])
 * @method static \Illuminate\Http\Client\Response|\Illuminate\Http\Client\Promises\LazyPromise send(string $method, string $url, array $options = [])
 */
class Http
{
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Support/Carbon.php', <<<'PHP'
<?php

namespace Illuminate\Support;

class Carbon extends \Carbon\Carbon
{
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Notifications/Notification.php', <<<'PHP'
<?php

namespace Illuminate\Notifications;

class Notification
{
}
PHP);

    file_put_contents($project . '/app/UsesNotificationChannel.php', <<<'PHP'
<?php

namespace App;

use Illuminate\Notifications\Notification;

final class UsesNotificationChannel
{
    public function send(Notification $notification): mixed
    {
        return [$notification->toWhatsapp($this), $notification->sendType];
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/socialite/src/Contracts/Provider.php', <<<'PHP'
<?php

namespace Laravel\Socialite\Contracts;

interface Provider
{
    /**
     * @return \Laravel\Socialite\Contracts\User
     */
    public function user();

    public function redirect();
}
PHP);

    file_put_contents($project . '/vendor/laravel/socialite/src/Two/User.php', <<<'PHP'
<?php

namespace Laravel\Socialite\Two;

class User
{
}
PHP);

    file_put_contents($project . '/vendor/nesbot/carbon/src/Carbon/Carbon.php', <<<'PHP'
<?php

namespace Carbon;

class Carbon extends \DateTime
{
}
PHP);

    file_put_contents($project . '/vendor/nesbot/carbon/src/Carbon/CarbonImmutable.php', <<<'PHP'
<?php

namespace Carbon;

class CarbonImmutable extends \DateTimeImmutable
{
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php', <<<'PHP'
<?php

namespace Illuminate\Database\Eloquent;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @mixin \Illuminate\Database\Query\Builder
 */
class Builder
{
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php', <<<'PHP'
<?php

namespace Illuminate\Database\Eloquent;

class Model
{
    public function load($relations)
    {
    }

    public function loadMissing($relations)
    {
    }

    public function loadCount($relations)
    {
    }

    protected function increment($column, $amount = 1, array $extra = [])
    {
    }

    protected function decrement($column, $amount = 1, array $extra = [])
    {
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php', <<<'PHP'
<?php

namespace Illuminate\Database\Eloquent\Concerns;

trait HasAttributes
{
    public function only($attributes)
    {
    }

    public function except($attributes)
    {
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php', <<<'PHP'
<?php

namespace Illuminate\Database\Query;

class Builder
{
    public function select($columns = ['*'])
    {
    }

    public function addSelect($column)
    {
    }

    public function distinct()
    {
    }

    /**
     * @param  SortDirection|'asc'|'desc'  $direction
     */
    public function orderBy($column, $direction = 'asc')
    {
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Routing/ControllerMiddlewareOptions.php', <<<'PHP'
<?php

namespace Illuminate\Routing;

class ControllerMiddlewareOptions
{
    public function only($methods)
    {
    }

    public function except($methods)
    {
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/HasFactory.php', '<?php');

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Scope.php', '<?php');

    file_put_contents($project . '/vendor/maatwebsite/excel/src/Concerns/FromCollection.php', '<?php');

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'laravelFrameworkSubstitutions');
    $substitutions = $method->invoke($application, $project, []);

    if (! is_array($substitutions) || count($substitutions) !== 42) {
        fail('framework overlay generation returned unexpected substitutions');
    }

    $guardOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Guard.php');
    $authManagerOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/AuthManager.php');
    $authOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Auth.php');
    $applicationContractOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/ApplicationContract.php');
    $httpOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Http.php');
    $supportCarbonOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/SupportCarbon.php');
    $baseCarbonOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/BaseCarbon.php');
    $baseCarbonImmutableOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/BaseCarbonImmutable.php');
    $foundationHelpersOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/FoundationHelpers.php');
    $notificationOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Notification.php');
    $shouldBroadcastOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/ShouldBroadcast.php');
    $eloquentBuilderOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Builder.php');
    $eloquentModelOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/EloquentModel.php');
    $hasAttributesOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/HasAttributes.php');
    $queryBuilderOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/QueryBuilder.php');
    $controllerMiddlewareOptionsOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/ControllerMiddlewareOptions.php');
    $hasFactoryOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/HasFactory.php');
    $scopeOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Scope.php');
    $fromCollectionOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/FromCollection.php');
    $socialiteProviderOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/SocialiteProvider.php');
    $socialiteUserOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/SocialiteUser.php');

    if (! is_string($guardOverlay) || ! str_contains($guardOverlay, '@return \\App\\Models\\Usuario\\Usuario|null')) {
        fail('guard overlay did not use the configured auth model');
    }

    if (! is_string($authManagerOverlay) || ! str_contains($authManagerOverlay, '@method \\App\\Models\\Usuario\\Usuario|null user()') || ! str_contains($authManagerOverlay, '@method int|string|null id()')) {
        fail('auth manager overlay did not expose delegated guard methods');
    }

    if (! is_string($authOverlay) || ! str_contains($authOverlay, '@method static \\App\\Models\\Usuario\\Usuario|null user()')) {
        fail('auth facade overlay did not use the configured auth model');
    }

    if (! is_string($foundationHelpersOverlay) || ! str_contains($foundationHelpersOverlay, '@return ($guard is null ? \\Illuminate\\Auth\\AuthManager : \\Illuminate\\Contracts\\Auth\\Guard)') || ! str_contains($foundationHelpersOverlay, 'function auth($guard = null): \\Illuminate\\Auth\\AuthManager|Guard') || ! str_contains($foundationHelpersOverlay, 'function now($tz = null): \\Illuminate\\Support\\Carbon')) {
        fail('foundation helpers overlay did not expose the default auth manager return type');
    }

    if (! is_string($httpOverlay) || ! str_contains($httpOverlay, '@method static \\Illuminate\\Http\\Client\\Response get(') || ! str_contains($httpOverlay, '@method static \\Illuminate\\Http\\Client\\Response send(')) {
        fail('HTTP facade overlay did not expose synchronous response return types');
    }

    if (! is_string($applicationContractOverlay) || ! str_contains($applicationContractOverlay, 'public function isProduction(): bool;')) {
        fail('application contract overlay did not expose production environment helper');
    }

    if (! is_string($supportCarbonOverlay) || ! str_contains($supportCarbonOverlay, '@method float diffinseconds(') || ! str_contains($supportCarbonOverlay, '@method $this startofmonth(')) {
        fail('support Carbon overlay did not expose lowercase Carbon method aliases');
    }

    if (! is_string($baseCarbonOverlay) || ! str_contains($baseCarbonOverlay, '@method static \\Carbon\\Carbon parse(') || ! str_contains($baseCarbonOverlay, '@method $this subdays(')) {
        fail('base Carbon overlay did not expose lowercase Carbon method aliases');
    }

    if (! is_string($baseCarbonImmutableOverlay) || ! str_contains($baseCarbonImmutableOverlay, '@method static \\Carbon\\CarbonImmutable parse(') || ! str_contains($baseCarbonImmutableOverlay, '@method $this subdays(')) {
        fail('base Carbon immutable overlay did not expose lowercase Carbon method aliases');
    }

    if (! is_string($notificationOverlay) || str_contains($notificationOverlay, '@property mixed $sendType') || ! str_contains($notificationOverlay, 'public function __get(string $key): mixed') || ! str_contains($notificationOverlay, 'public function towhatsapp(mixed $notifiable): mixed {}') || str_contains($notificationOverlay, 'public mixed $sendType;')) {
        fail('notification overlay did not expose project custom channel members');
    }

    if (! is_string($shouldBroadcastOverlay) || ! str_contains($shouldBroadcastOverlay, '@return mixed')) {
        fail('ShouldBroadcast overlay did not relax Laravel broadcast channel return docs');
    }

    if (! is_string($socialiteProviderOverlay) || ! str_contains($socialiteProviderOverlay, '@return \\Laravel\\Socialite\\Contracts\\User|null') || ! str_contains($socialiteProviderOverlay, 'public function with(array $parameters): static;') || ! str_contains($socialiteProviderOverlay, 'public function scopes(array $scopes): static;')) {
        fail('Socialite provider overlay did not expose fluent provider methods');
    }

    if (! is_string($socialiteUserOverlay) || ! str_contains($socialiteUserOverlay, 'public function setAccessTokenResponseBody(array $body): static')) {
        fail('Socialite user overlay did not expose SocialiteProviders extension methods');
    }

    if (str_contains($authOverlay, 'Laravel\\Ui\\UiServiceProvider')) {
        fail('auth facade overlay leaked optional vendor implementation details');
    }

    if (! is_string($eloquentBuilderOverlay) || ! str_contains($eloquentBuilderOverlay, '@method $this leftJoin(') || ! str_contains($eloquentBuilderOverlay, '@method $this select(mixed ...$columns)') || ! str_contains($eloquentBuilderOverlay, '@method $this withoutglobalscopes(') || ! str_contains($eloquentBuilderOverlay, '@mixin \\Illuminate\\Database\\Query\\Builder')) {
        fail('Eloquent builder overlay did not preserve source and add delegated chain methods');
    }

    if (! is_string($eloquentModelOverlay) || ! str_contains($eloquentModelOverlay, 'public function loadMissing($relations, ...$additionalRelations)') || ! str_contains($eloquentModelOverlay, 'public function increment($column, $amount = 1, array $extra = [])') || ! str_contains($eloquentModelOverlay, 'public static function withoutGlobalScopes(?array $scopes = null)') || ! str_contains($eloquentModelOverlay, 'public static function where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = \'and\')') || ! str_contains($eloquentModelOverlay, 'public static function select(mixed ...$columns)') || ! str_contains($eloquentModelOverlay, 'public static function lockForUpdate()')) {
        fail('Eloquent model overlay did not expose dynamic static builder delegation');
    }

    if (! is_string($hasAttributesOverlay) || ! str_contains($hasAttributesOverlay, 'public function only($attributes, ...$additionalAttributes)') || ! str_contains($hasAttributesOverlay, 'public function except($attributes, ...$additionalAttributes)')) {
        fail('HasAttributes overlay did not expose variadic attribute selectors');
    }

    if (! is_string($queryBuilderOverlay) || ! str_contains($queryBuilderOverlay, 'public function select($columns = [\'*\'], ...$additionalColumns)') || ! str_contains($queryBuilderOverlay, 'public function addSelect($column, ...$additionalColumns)') || ! str_contains($queryBuilderOverlay, 'public function distinct(...$columns)') || ! str_contains($queryBuilderOverlay, '@param  SortDirection|string  $direction') || ! str_contains($queryBuilderOverlay, '@method $this whereintegernotinraw(')) {
        fail('query builder overlay did not expose variadic column selectors');
    }

    if (! is_string($controllerMiddlewareOptionsOverlay) || ! str_contains($controllerMiddlewareOptionsOverlay, 'public function only($methods, ...$additionalMethods)') || ! str_contains($controllerMiddlewareOptionsOverlay, 'public function except($methods, ...$additionalMethods)')) {
        fail('ControllerMiddlewareOptions overlay did not expose variadic middleware filters');
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
