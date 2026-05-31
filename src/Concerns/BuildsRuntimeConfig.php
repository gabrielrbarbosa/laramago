<?php

declare(strict_types=1);

namespace Laramago\Concerns;

trait BuildsRuntimeConfig
{
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

    private function renderProjectConfig(string $phpVersion, array $sourcePaths, array $includes, array $excludes, array $analyzerIgnores = []): string
    {
        $paths = $this->tomlArray($sourcePaths);
        $includesValue = $this->tomlArray($includes);
        $excludesValue = $this->tomlArray($excludes);
        $ignoreBlock = $this->renderAnalyzerIgnoreBlock($analyzerIgnores);
        $analyzerBlock = $ignoreBlock === '' ? '' : PHP_EOL . PHP_EOL . '[analyzer]' . PHP_EOL . $ignoreBlock;

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
{$analyzerBlock}
TOML;
    }

    private function prepareRuntimeConfig(string $projectRoot, array $arguments = [], ?string $mago = null): string
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
            $this->analyzerIssueCodes($projectRoot, $mago),
            $values['analyzerIgnores'],
        ));

        return self::RUNTIME_CONFIG_FILE;
    }

    private function projectConfigValues(string $projectRoot): array
    {
        $values = [
            'phpVersion' => $this->detectPhpVersion($projectRoot),
            'paths' => ['app'],
            'includes' => ['vendor'],
            'excludes' => $this->defaultLaravelExcludes($projectRoot),
            'analyzerIgnores' => [],
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

        $values['analyzerIgnores'] = $this->tomlAnalyzerIgnoreValue($config);

        return $values;
    }

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

    private function renderRuntimeConfig(string $phpVersion, array $sourcePaths, array $includes, array $excludes, array $arguments = [], array $frameworkOverlayIgnoredCodes = [], array $projectAnalyzerIgnores = []): string
    {
        $paths = $this->tomlArray($sourcePaths);
        $includesValue = $this->tomlArray($includes);
        $excludesValue = $this->tomlArray($excludes);
        $ignoreBlock = $this->renderAnalyzerIgnoreBlock($this->uniqueAnalyzerIgnores(array_merge(
            $projectAnalyzerIgnores,
            $this->runtimeAnalyzerIgnores($arguments, $frameworkOverlayIgnoredCodes),
        )));
        $findUnusedDefinitions = in_array('--find-unused-definitions', $arguments, true) ? 'true' : 'false';

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

    private function runtimeAnalyzerIgnores(array $arguments, array $frameworkOverlayIgnoredCodes = []): array
    {
        $frameworkOverlayIgnores = array_map(
            static fn (string $code): array => [
                'code' => $code,
                'in' => self::FRAMEWORK_OVERLAY_DIR . '/',
            ],
            $frameworkOverlayIgnoredCodes,
        );

        $ignores = array_merge($this->phpStanCompatibilityIgnores($arguments), $frameworkOverlayIgnores, [
            'mixed-operand',
            'mixed-argument',
            'mixed-assignment',
            'mixed-method-access',
            'mixed-property-access',
            'mixed-array-access',
            'mixed-array-assignment',
            'mixed-return-statement',
            'mixed-property-type-coercion',
            'mixed-array-index',
            'invalid-iterator',
            'invalid-member-selector',
            'less-specific-return-statement',
            'less-specific-argument',
            'less-specific-nested-argument-type',
            'less-specific-nested-return-statement',
            'ambiguous-object-property-access',
            'ambiguous-object-method-access',
            'non-documented-property',
            'non-documented-method',
            'possibly-invalid-argument',
            'possibly-null-property-access',
            'possible-method-access-on-null',
            'possibly-null-argument',
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
                'code' => 'possibly-non-existent-property',
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

        return $this->uniqueAnalyzerIgnores($ignores);
    }

    private function uniqueAnalyzerIgnores(array $ignores): array
    {
        $seen = [];
        $unique = [];

        foreach ($ignores as $ignore) {
            $key = is_string($ignore) ? 'code:' . $ignore : 'path:' . $ignore['code'] . ':' . $ignore['in'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $ignore;
        }

        return $unique;
    }

    private function analyzerIssueCodes(string $projectRoot, ?string $mago): array
    {
        if ($mago === null) {
            return [];
        }

        $result = $this->capture([$mago, 'analyze', '--list-codes'], $projectRoot);

        if ($result['exitCode'] !== 0 || $result['stdout'] === '') {
            return [];
        }

        try {
            $codes = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($codes)) {
            return [];
        }

        $codes = array_values(array_unique(array_filter($codes, is_string(...))));
        sort($codes);

        return $codes;
    }

    private function phpStanCompatibilityIgnores(array $arguments): array
    {
        $level = $this->phpStanCompatibilityLevel($arguments);

        if ($level === null) {
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
            'missing-magic-method',
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

        if ($level <= 7) {
            return $ignores;
        }

        if ($level === 8) {
            return array_values(array_diff($ignores, $levelEightReportedCodes));
        }

        $levelNineReportedCodes = array_merge($levelEightReportedCodes, [
            'mixed-argument',
            'mixed-assignment',
            'mixed-array-access',
            'mixed-array-assignment',
            'mixed-array-index',
            'mixed-clone',
            'mixed-method-access',
            'mixed-operand',
            'mixed-property-access',
            'mixed-property-type-coercion',
            'mixed-return-statement',
        ]);

        return array_values(array_diff($ignores, $levelNineReportedCodes));
    }

    private function usesPhpStanCompatibilityProfile(array $arguments): bool
    {
        return $this->phpStanCompatibilityLevel($arguments) !== null;
    }

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

    private function tomlAnalyzerIgnoreValue(string $config): array
    {
        $lines = preg_split('/\R/', $config);

        if (! is_array($lines)) {
            return [];
        }

        $body = '';
        $collecting = false;
        $inAnalyzerSection = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*\[([A-Za-z0-9_.-]+)]\s*$/', $line, $sectionMatches) === 1) {
                if ($collecting) {
                    break;
                }

                $inAnalyzerSection = $sectionMatches[1] === 'analyzer';
                continue;
            }

            if (! $inAnalyzerSection) {
                continue;
            }

            if (! $collecting && preg_match('/^\s*ignore\s*=\s*\[(.*)$/', $line, $matches) === 1) {
                $collecting = true;
                $line = $matches[1];
            }

            if (! $collecting) {
                continue;
            }

            $closingPosition = strpos($line, ']');

            if ($closingPosition !== false) {
                $body .= substr($line, 0, $closingPosition) . PHP_EOL;
                break;
            }

            $body .= $line . PHP_EOL;
        }

        if (trim($body) === '') {
            return [];
        }

        $ignores = [];
        $entries = preg_split('/\R/', $body);

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (preg_match('/code\s*=\s*"([^"]+)".*in\s*=\s*"([^"]+)"/', $entry, $matches) === 1) {
                $ignores[] = [
                    'code' => $matches[1],
                    'in' => $matches[2],
                ];

                continue;
            }

            preg_match_all('/"([^"]+)"/', $entry, $matches);

            foreach ($matches[1] as $code) {
                $ignores[] = $code;
            }
        }

        return $this->uniqueAnalyzerIgnores($ignores);
    }

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
            ...$this->magoNativeBinaryCandidates(dirname(__DIR__, 2)),
            dirname(__DIR__, 2) . '/vendor/bin/mago',
            dirname(__DIR__, 3) . '/bin/mago',
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

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
}
