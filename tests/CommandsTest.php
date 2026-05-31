<?php

declare(strict_types=1);

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

function testBaselinePathTranslationUsesPhpStanPragmaOverlays(string $project, string $root): void
{
    file_put_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json', json_encode([[
        'original' => 'app/Services/LegacyService.php',
        'overlay' => '.laramago/cache/phpstan-pragma-overlays/legacy.php',
    ]], JSON_THROW_ON_ERROR));
    file_put_contents($project . '/laramago-analyzer-baseline.toml', "file = \"app/Services/LegacyService.php\"\n");

    require_once $root . '/src/Application.php';

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'defaultAnalyzeFlags');
    $flags = $method->invoke($application, $project, [], true);

    if ($flags !== ['--baseline', '.laramago/cache/analyzer-baseline.toml']) {
        fail('analysis should use a translated runtime baseline when PHPStan pragma overlays are active');
    }

    $translated = file_get_contents($project . '/.laramago/cache/analyzer-baseline.toml');

    if ($translated !== "file = \".laramago/cache/phpstan-pragma-overlays/legacy.php\"\n") {
        fail('baseline path translation should include PHPStan pragma overlay paths');
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
    file_put_contents($project . '/mago.toml', <<<'TOML'
version = "1"
php-version = "8.5.0"

[source]
workspace = "."
paths = ["app"]
includes = ["vendor"]
excludes = []

[linter]
ignore = ["not-an-analyzer-ignore"]

[analyzer]
ignore = [
  "invalid-return-statement",
  { code = "nullable-return-statement", in = "app/" },
]
TOML);

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

    if (! str_contains($config, 'find-unused-definitions = false')) {
        fail('runtime config should keep PHPStan-compatible unused definition checks disabled by default');
    }

    if (! str_contains($config, '"invalid-return-statement"') || ! str_contains($config, '{ code = "nullable-return-statement", in = "app/" }')) {
        fail('runtime config did not preserve project analyzer ignores');
    }

    if (str_contains($config, 'not-an-analyzer-ignore')) {
        fail('runtime config should not read ignore arrays outside the analyzer section');
    }

    foreach (['"mixed-operand"', '"mixed-argument"', '"mixed-assignment"', '"mixed-method-access"', '"mixed-property-access"', '"mixed-array-access"', '"mixed-array-assignment"', '"mixed-return-statement"', '"mixed-property-type-coercion"', '"mixed-array-index"', '"invalid-iterator"', '"invalid-member-selector"', '"less-specific-return-statement"', '"less-specific-argument"', '"less-specific-nested-argument-type"', '"less-specific-nested-return-statement"', '"ambiguous-object-property-access"', '"ambiguous-object-method-access"', '"non-documented-property"', '"non-documented-method"', '"possibly-invalid-argument"', '"possibly-null-property-access"', '"possible-method-access-on-null"', '"possibly-null-argument"'] as $expectedDefaultIgnore) {
        if (! str_contains($config, $expectedDefaultIgnore)) {
            fail('runtime config missed a default Laravel dynamic data compatibility ignore: ' . $expectedDefaultIgnore);
        }
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

    $fakeMago = $project . '/fake-mago';
    file_put_contents($fakeMago, <<<'PHP'
#!/usr/bin/env php
<?php

if (($argv[1] ?? null) === 'analyze' && ($argv[2] ?? null) === '--list-codes') {
    echo json_encode(['mixed-argument', 'non-existent-method'], JSON_THROW_ON_ERROR);
    exit(0);
}

exit(1);
PHP);
    chmod($fakeMago, 0755);

    $dynamicRuntimeConfig = $method->invoke($application, $project, [], $fakeMago);
    $dynamicConfig = file_get_contents($project . '/' . $dynamicRuntimeConfig);

    foreach (['mixed-argument', 'non-existent-method'] as $code) {
        if (! is_string($dynamicConfig) || ! str_contains($dynamicConfig, '{ code = "' . $code . '", in = ".laramago/cache/framework-overlays/" }')) {
            fail('runtime config should ignore all generated framework overlay analyzer codes: ' . $code);
        }
    }

    if (str_contains($config, '"invalid-argument"')) {
        fail('runtime config should not include PHPStan level ignores unless explicitly requested');
    }

    $unusedDefinitionRuntimeConfig = $method->invoke($application, $project, ['--find-unused-definitions']);
    $unusedDefinitionConfig = file_get_contents($project . '/' . $unusedDefinitionRuntimeConfig);

    if (! str_contains((string) $unusedDefinitionConfig, 'find-unused-definitions = true')) {
        fail('runtime config should allow explicit opt-in to Mago unused definition checks');
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
        '"missing-magic-method"',
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
    scanDirectories:
        - database/factories
    scanFiles:
        - app/helpers.php
    bootstrapFiles:
        - bootstrap/static-analysis.php
    stubFiles:
        - stubs/legacy.php
    ignoreErrors:
        - identifier: argument.type
        - identifier: method.notFound
        - identifier: missingType.generics
        - identifier: missingType.iterableValue
        - identifier: offsetAccess.notFound
        - identifier: property.notFound
        - identifier: return.type
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

    if (! str_contains($config, 'includes = ["vendor", "database/factories", "app/helpers.php", "bootstrap/static-analysis.php", "stubs/legacy.php"]')) {
        fail('migrate-phpstan did not preserve PHPStan scan/bootstrap/stub discovery paths');
    }

    if (! str_contains($config, 'excludes = ["app/Legacy/**", "app/Services/NotaFiscal/**"]')) {
        fail('migrate-phpstan did not normalize PHPStan exclude paths');
    }

    foreach (['"possibly-false-argument"', '"non-existent-method"', '"missing-template-parameter"', '"invalid-array-access"', '"non-existent-property"', '"invalid-return-statement"', '"nullable-return-statement"', '"falsable-return-statement"'] as $expectedIgnore) {
        if (! str_contains($config, $expectedIgnore)) {
            fail('migrate-phpstan did not preserve PHPStan ignoreErrors identifier as analyzer ignore: ' . $expectedIgnore);
        }
    }

    if (str_contains($config, 'missing-iterable-value-type')) {
        fail('migrate-phpstan should not emit analyzer ignore codes that Mago does not support');
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
    $levelNineIgnores = $method->invoke($application, ['--phpstan-level=9']);
    $levelTenIgnores = $method->invoke($application, ['--phpstan-level=10']);
    $maxIgnores = $method->invoke($application, ['--phpstan-level=max']);
    $unsupportedIgnores = $method->invoke($application, ['--phpstan-level=custom']);

    if (! is_array($levelIgnores) || ! in_array('mixed-argument', $levelIgnores, true) || ! in_array('missing-magic-method', $levelIgnores, true)) {
        fail('PHPStan level 6 compatibility ignores were not enabled explicitly');
    }

    if (! is_array($levelEightIgnores) || ! in_array('mixed-argument', $levelEightIgnores, true) || in_array('possibly-null-argument', $levelEightIgnores, true)) {
        fail('PHPStan level 8 compatibility should keep mixed compatibility while reporting nullable issues');
    }

    if (! is_array($levelNineIgnores) || in_array('mixed-argument', $levelNineIgnores, true) || in_array('possibly-null-argument', $levelNineIgnores, true) || ! in_array('invalid-property-assignment-value', $levelNineIgnores, true)) {
        fail('PHPStan level 9 compatibility should report mixed/nullability issues while keeping Laravel compatibility ignores');
    }

    if ($levelTenIgnores !== $levelNineIgnores || $maxIgnores !== $levelTenIgnores) {
        fail('PHPStan level 10 and max compatibility should follow the highest PHPStan migration profile');
    }

    if ($unsupportedIgnores !== []) {
        fail('unsupported PHPStan levels should not enable compatibility ignores');
    }
}

function testPhpStanMigrationPreservesScopedIgnoreErrors(string $project, string $binary): void
{
    file_put_contents($project . '/phpstan.neon', <<<'NEON'
parameters:
    paths:
        - app/
    ignoreErrors:
        -
            identifier: return.type
            path: app/Legacy/Returns/*
        -
            identifier: property.notFound
            paths:
                - app/Legacy/Models/*
                - app/Imported/Models/*
NEON);

    $exitCode = run([PHP_BINARY, $binary, 'migrate-phpstan', '--project=' . $project, '--force']);

    if ($exitCode !== 0) {
        fail('migrate-phpstan scoped ignore command failed');
    }

    $config = file_get_contents($project . '/mago.toml');

    foreach ([
        '{ code = "invalid-return-statement", in = "app/Legacy/Returns/**" }',
        '{ code = "non-existent-property", in = "app/Legacy/Models/**" }',
        '{ code = "non-existent-property", in = "app/Imported/Models/**" }',
    ] as $expectedIgnore) {
        if (! is_string($config) || ! str_contains($config, $expectedIgnore)) {
            fail('migrate-phpstan did not preserve scoped ignoreErrors entry: ' . $expectedIgnore);
        }
    }

    foreach ([
        '  "invalid-return-statement",',
        '  "non-existent-property",',
    ] as $unexpectedGlobalIgnore) {
        if (is_string($config) && str_contains($config, $unexpectedGlobalIgnore)) {
            fail('migrate-phpstan should not globalize scoped ignoreErrors entry: ' . $unexpectedGlobalIgnore);
        }
    }
}

function testPhpStanMigrationReadsLocalIncludes(string $project, string $binary): void
{
    mkdir($project . '/config', 0777, true);
    file_put_contents($project . '/phpstan.neon', <<<'NEON'
includes:
    - ./vendor/larastan/larastan/extension.neon
    - config/phpstan-shared.neon

parameters:
    paths:
        - app/
    level: 6
NEON);

    file_put_contents($project . '/config/phpstan-shared.neon', <<<'NEON'
parameters:
    paths:
        - modules/Billing/
    scanFiles:
        - support/static-analysis.php
    ignoreErrors:
        -
            identifier: return.type
            path: modules/Billing/*
    excludePaths:
        analyse:
            - modules/Billing/Legacy/*
NEON);

    $exitCode = run([PHP_BINARY, $binary, 'migrate-phpstan', '--project=' . $project, '--force']);

    if ($exitCode !== 0) {
        fail('migrate-phpstan command failed for local includes');
    }

    $config = file_get_contents($project . '/mago.toml');

    if (! is_string($config) || ! str_contains($config, 'paths = ["app", "modules/Billing"]')) {
        fail('migrate-phpstan did not preserve paths from local included PHPStan configs');
    }

    if (! str_contains($config, 'includes = ["vendor", "support/static-analysis.php"]')) {
        fail('migrate-phpstan did not preserve scan files from local included PHPStan configs');
    }

    if (! str_contains($config, 'excludes = ["modules/Billing/Legacy/**"]')) {
        fail('migrate-phpstan did not preserve excludes from local included PHPStan configs');
    }

    if (! str_contains($config, '{ code = "invalid-return-statement", in = "modules/Billing/**" }')) {
        fail('migrate-phpstan did not preserve scoped ignores from local included PHPStan configs');
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

    if (! isset($pipes[1], $pipes[2])) {
        proc_close($process);
        fail('unable to open lock holder process pipes');
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

function testAnalyzeFailsWhenConfiguredSourcesAreEmpty(string $project, string $binary): void
{
    $emptyProject = $project . '/empty-source-project';
    mkdir($emptyProject . '/app', 0777, true);
    file_put_contents($emptyProject . '/composer.json', json_encode([
        'require' => [
            'php' => '^8.5',
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    file_put_contents($emptyProject . '/mago.toml', <<<'TOML'
version = "1"
php-version = "8.5.0"

[source]
workspace = "."
paths = ["app"]
includes = ["vendor"]
excludes = []

[source.glob]
literal-separator = true
TOML);

    $result = captureRun([PHP_BINARY, $binary, 'analyze', '--project=' . $emptyProject, '--phpstan-level=6', '--reporting-format=count']);

    if ($result['exitCode'] === 0) {
        fail('analyze should fail when configured source paths contain no PHP files');
    }

    if (! str_contains($result['output'], 'No PHP files found in the configured Laramago source paths.')) {
        fail('analyze should explain empty configured source paths');
    }
}

function testCodesCommandDoesNotRequireConfiguredSources(string $project, string $binary): void
{
    $emptyProject = $project . '/empty-codes-project';
    mkdir($emptyProject . '/app', 0777, true);
    file_put_contents($emptyProject . '/composer.json', json_encode([
        'require' => [
            'php' => '^8.5',
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    file_put_contents($emptyProject . '/mago.toml', <<<'TOML'
version = "1"
php-version = "8.5.0"

[source]
workspace = "."
paths = ["app"]
includes = ["vendor"]
excludes = []

[source.glob]
literal-separator = true
TOML);

    $result = captureRun([PHP_BINARY, $binary, 'codes', '--project=' . $emptyProject]);

    if ($result['exitCode'] !== 0) {
        fail('codes command should not require configured source paths to contain PHP files');
    }

    if (str_contains($result['output'], 'No PHP files found in the configured Laramago source paths.')) {
        fail('codes command should bypass the empty-source analysis guard');
    }

    if (! str_contains($result['output'], 'mixed-argument')) {
        fail('codes command should list analyzer issue codes');
    }
}

function testStdinInputDoesNotRequireConfiguredSources(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    $emptyProject = $project . '/empty-stdin-project';
    mkdir($emptyProject . '/app', 0777, true);
    file_put_contents($emptyProject . '/composer.json', json_encode([
        'require' => [
            'php' => '^8.5',
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    file_put_contents($emptyProject . '/mago.toml', <<<'TOML'
version = "1"
php-version = "8.5.0"

[source]
workspace = "."
paths = ["app"]
includes = ["vendor"]
excludes = []

[source.glob]
literal-separator = true
TOML);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'analysisHasSourceFiles');

    if ($method->invoke($application, $emptyProject, ['--stdin-input', 'app/Unsaved.php'], false) !== true) {
        fail('stdin-input should bypass the empty-source analysis guard for editor integrations');
    }

    if ($method->invoke($application, $emptyProject, ['app/Unsaved.php'], false) !== false) {
        fail('non-stdin virtual paths should not bypass the empty-source analysis guard');
    }
}

function testPhpStanLevelAnalyzeRunsEndToEnd(string $project, string $binary): void
{
    $fixture = $project . '/level-fixture';

    mkdir($fixture . '/app/Http/Controllers', 0777, true);
    mkdir($fixture . '/vendor/Illuminate/Http', 0777, true);

    file_put_contents($fixture . '/composer.json', json_encode([
        'require' => [
            'php' => '^8.5',
        ],
        'autoload' => [
            'psr-4' => [
                'App\\' => 'app/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($fixture . '/mago.toml', <<<'TOML'
version = "1"
php-version = "8.5.0"

[source]
workspace = "."
paths = ["app"]
includes = ["vendor"]
excludes = []

[source.glob]
literal-separator = true
TOML);

    file_put_contents($fixture . '/vendor/Illuminate/Http/Request.php', <<<'PHP'
<?php

namespace Illuminate\Http;

class Request
{
    public function input(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}
PHP);

    file_put_contents($fixture . '/app/Http/Controllers/ReportController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

final class ReportController
{
    public function index(Request $request): array
    {
        $columns = $request->input('columns');

        foreach ($columns as $column) {
            $selected[] = $column;
        }

        return $selected ?? [];
    }

    public function strictReturn(): int
    {
        return false;
    }
}
PHP);

    $strictResult = captureRun([PHP_BINARY, $binary, 'analyze', '--project=' . $fixture, '--reporting-format=count']);

    if ($strictResult['exitCode'] === 0 || ! str_contains($strictResult['output'], 'error:')) {
        fail('native strict analysis should still report real issues in the end-to-end fixture');
    }

    $levelResult = captureRun([PHP_BINARY, $binary, 'analyze', '--project=' . $fixture, '--phpstan-level=6', '--reporting-format=count']);

    if ($levelResult['exitCode'] !== 0 || ! str_contains($levelResult['output'], 'No issues found.')) {
        fail('PHPStan level 6 analysis should pass the end-to-end migration fixture');
    }

    $levelNineResult = captureRun([PHP_BINARY, $binary, 'analyze', '--project=' . $fixture, '--phpstan-level=9', '--reporting-format=short']);

    if ($levelNineResult['exitCode'] === 0 || ! str_contains($levelNineResult['output'], 'strictReturn') || str_contains($levelNineResult['output'], '$columns')) {
        fail('PHPStan level 9 analysis should report strict return issues without falling back to raw mixed-data noise');
    }

    $baselineResult = captureRun([PHP_BINARY, $binary, 'baseline', '--project=' . $fixture, '--phpstan-level=6', '--force']);

    if ($baselineResult['exitCode'] !== 0 || ! is_file($fixture . '/laramago-analyzer-baseline.toml')) {
        fail('PHPStan level 6 baseline should be generated for the end-to-end migration fixture');
    }

    $strictVerifyResult = captureRun([PHP_BINARY, $binary, 'verify-baseline', '--project=' . $fixture]);

    if ($strictVerifyResult['exitCode'] === 0 || ! str_contains($strictVerifyResult['output'], 'Baseline is outdated')) {
        fail('strict baseline verification should not silently pass a PHPStan level 6 migration baseline');
    }

    $levelVerifyResult = captureRun([PHP_BINARY, $binary, 'verify-baseline', '--project=' . $fixture, '--phpstan-level=6']);

    if ($levelVerifyResult['exitCode'] !== 0 || ! str_contains($levelVerifyResult['output'], 'Baseline is up to date')) {
        fail('PHPStan level 6 baseline verification should pass with the same migration profile');
    }
}
