<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$binary = $root . '/bin/laramago';
$project = sys_get_temp_dir() . '/laramago-test-' . bin2hex(random_bytes(4));

require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/CommandsTest.php';
require_once __DIR__ . '/Overlays/PragmaAndHelperOverlaysTest.php';
require_once __DIR__ . '/Overlays/DynamicMemberOverlaysTest.php';
require_once __DIR__ . '/Overlays/ClassResolutionOverlaysTest.php';
require_once __DIR__ . '/Overlays/LaravelMetadataAndFrameworkOverlaysTest.php';

$testFiles = [
    __DIR__ . '/CommandsTest.php',
    __DIR__ . '/Overlays/PragmaAndHelperOverlaysTest.php',
    __DIR__ . '/Overlays/DynamicMemberOverlaysTest.php',
    __DIR__ . '/Overlays/ClassResolutionOverlaysTest.php',
    __DIR__ . '/Overlays/LaravelMetadataAndFrameworkOverlaysTest.php',
];
$definedTests = [];

foreach ($testFiles as $testFile) {
    preg_match_all('/^function\s+(test[A-Za-z0-9_]+)\s*\(/m', (string) file_get_contents($testFile), $matches);
    $definedTests = array_merge($definedTests, $matches[1]);
}

preg_match_all('/\b(test[A-Za-z0-9_]+)\s*\(/', (string) file_get_contents(__FILE__), $matches);
$missingTests = array_values(array_diff(array_unique($definedTests), array_unique($matches[1])));
sort($missingTests);

if ($missingTests !== []) {
    fail('tests/run.php does not execute: ' . implode(', ', $missingTests));
}

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

file_put_contents($project . '/app/Example.php', <<<'PHP'
<?php

namespace App;

final class Example
{
}
PHP);

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
testHelpDocumentsOverlayOptOutFlags($binary);
testBaselinePathTranslation($project, $root);
testOutputPathTranslation($project, $root);
testModelOverlayFilesAvoidPhpExtension($root);
testBaselinePathTranslationUsesPhpStanPragmaOverlays($project, $root);
testRuntimeConfigGeneration($project, $root);
testNativeMagoBinaryIsPreferred($project, $root);
testMagoProxyMaterializesNativeBinaryBeforeUse($project, $root);
testPhpStanMigration($project, $root, $binary);
testPhpStanMigrationSkipsMissingDiscoveryIncludes($project, $binary);
testPhpStanMigrationPreservesScopedIgnoreErrors($project, $binary);
testPhpStanMigrationReadsLocalIncludes($project, $binary);
testExcludedSymbolStubGeneration($project, $root);
testRaceSafeCacheDirectoryOperations($project, $root);
testProjectLockSerializesCacheCommands($project, $binary);
testProjectClassDiscoveryUsesConfiguredSourcePaths($project, $root);
testAnalysisIgnoresStaleRuntimeBaseline($project, $root);
testAnalyzeFailsWhenConfiguredSourcesAreEmpty($project, $binary);
testCompareCommandRunsPlainMagoThenLaramago($project, $binary);
testSeparateMagoOptionValuesAreNotTreatedAsAnalysisTargets($project, $root);
testCodesCommandDoesNotRequireConfiguredSources($project, $binary);
testStdinInputDoesNotRequireConfiguredSources($project, $root);
testStagedInputDoesNotRequireConfiguredSources($project, $root);
testPhpStanLevelAnalyzeRunsEndToEnd($project, $binary);
testAnalyzeReportsMissingTypeHints($project, $binary);
testPhpStanPragmaOverlayGeneration($project, $root);
testLaravelPaginatorReturnDocblockOverlayGeneration($root);
testLaravelDateHelperOverlayGeneration($project, $root);
testReflectionMethodCasingOverlayGeneration($project, $root);
testInternalFunctionCompatibilityOverlayGeneration($project, $root);
testLaravelCommandReturnOverlayGeneration($project, $root);
testLaravelHttpClientWrapperReturnTypeOverlayGeneration($project, $root);
testLaravelCollectionMacroOverlayGeneration($project, $root);
testLaravelCollectionItemObjectOverlayGeneration($project, $root);
testLaravelCollectionArrowCallbackOverlayGeneration($project, $root);
testLaravelForeachObjectRowOverlayGeneration($project, $root);
testDynamicMemberSelectorStringOverlayGeneration($project, $root);
testEloquentModelArrayAccessAssignmentOverlayGeneration($project, $root);
testLaravelNumericFallbackAssignmentOverlayGeneration($project, $root);
testLaravelExcelEventOverlayGeneration($project, $root);
testLaravelValidationRuleCallbackOverlayGeneration($project, $root);
testLaravelQueryBuilderClosureOverlayGeneration($project, $root);
testLaravelObserverModelOverlayGeneration($project, $root);
testLaravelRequestPropertyReadOverlayGeneration($project, $root);
testLaravelRequestInputArrayVariableOverlayGeneration($project, $root);
testLaravelEloquentModelTraitOverlayGeneration($project, $root);
testLaravelJsonResourceDynamicMemberOverlayGeneration($project, $root);
testLaravelFormRequestDynamicPropertyOverlayGeneration($project, $root);
testAllowDynamicPropertiesOverlayGeneration($project, $root);
testImplicitArrayAccumulatorOverlayGeneration($project, $root);
testCaseInsensitiveOverlaySkipsSingleAliasFiles($project, $root);
testLaravelRequestClassInstantiationOverlayGeneration($project, $root);
testReflectionReturnTypeAndClassExistsOverlayGeneration($project, $root);
testCaseInsensitiveOverlayRespectsExcludes($project, $root);
testTraitSelfCallOverlayGeneration($project, $root);
testLaravelMetadataInferenceHelpers($root);
testModelDocblockIncludesLaravelMagic($root);
testLaravelFrameworkOverlayGeneration($project, $root);

cleanup($project);
echo "OK\n";
