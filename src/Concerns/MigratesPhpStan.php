<?php

declare(strict_types=1);

namespace Laramago\Concerns;

trait MigratesPhpStan
{
    private function phpStanConfigPath(string $projectRoot, array $arguments): ?string
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--phpstan-config=')) {
                $path = substr($argument, strlen('--phpstan-config='));
                $absolutePath = str_starts_with($path, '/') ? $path : $projectRoot . '/' . $path;

                return is_file($absolutePath) ? $absolutePath : null;
            }
        }

        foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan-ci.neon', 'phpstan-parallel.neon'] as $candidate) {
            $path = $projectRoot . '/' . $candidate;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function phpStanConfigSource(string $configPath): ?string
    {
        return $this->phpStanConfigSourceRecursive($configPath, []);
    }

    /**
     * @param array<string, true> $seen
     */
    private function phpStanConfigSourceRecursive(string $configPath, array $seen): ?string
    {
        $realPath = realpath($configPath);

        if (! is_string($realPath) || isset($seen[$realPath])) {
            return null;
        }

        $source = file_get_contents($realPath);

        if (! is_string($source)) {
            return null;
        }

        $seen[$realPath] = true;
        $sources = [$source];

        foreach ($this->phpStanLocalIncludePaths($source, dirname($realPath)) as $includePath) {
            $includedSource = $this->phpStanConfigSourceRecursive($includePath, $seen);

            if ($includedSource !== null) {
                $sources[] = $includedSource;
            }
        }

        return implode(PHP_EOL, $sources);
    }

    /**
     * @return list<string>
     */
    private function phpStanLocalIncludePaths(string $source, string $baseDirectory): array
    {
        $paths = [];

        foreach ($this->neonListValue($source, 'includes') as $include) {
            $include = trim(str_replace('\\', '/', $include));

            if ($include === '' || str_starts_with($include, '%') || str_starts_with($include, 'vendor/')) {
                continue;
            }

            $absolutePath = str_starts_with($include, '/') ? $include : $baseDirectory . '/' . $include;

            if (is_file($absolutePath)) {
                $paths[] = $absolutePath;
            }
        }

        return array_values(array_unique($paths));
    }

    private function neonListValue(string $source, string $key): array
    {
        $lines = preg_split('/\R/', $source);

        if (! is_array($lines)) {
            return [];
        }

        $values = [];

        for ($index = 0; $index < count($lines); $index++) {
            $line = $lines[$index];

            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*:\s*\[([^\]]*)\]/', $line, $inlineMatches) === 1) {
                preg_match_all('/[\'"]?([^\'",\s]+)[\'"]?/', $inlineMatches[1], $valueMatches);
                $values = array_merge($values, array_map('trim', $valueMatches[1]));
                continue;
            }

            if (preg_match('/^(\s*)' . preg_quote($key, '/') . '\s*:\s*$/', $line, $matches) !== 1) {
                continue;
            }

            $indent = strlen($matches[1]);

            for ($listIndex = $index + 1; $listIndex < count($lines); $listIndex++) {
                $listLine = $lines[$listIndex];

                if (trim($listLine) === '') {
                    continue;
                }

                $lineIndent = strlen($listLine) - strlen(ltrim($listLine));

                if ($lineIndent <= $indent) {
                    $index = $listIndex - 1;
                    break;
                }

                if (preg_match('/^\s*-\s*[\'"]?([^\'"#]+)[\'"]?/', $listLine, $valueMatches) === 1) {
                    $values[] = trim($valueMatches[1]);
                }

                $index = $listIndex;
            }
        }

        return array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));
    }

    private function neonScalarValue(string $source, string $key): ?string
    {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*:\s*[\'"]?([^\'"#\s]+)[\'"]?/m', $source, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private function phpStanLevelFlag(?string $level): string
    {
        if ($level === null) {
            return '';
        }

        $level = strtolower(trim($level));

        if ($level === 'max') {
            return ' --phpstan-level=max';
        }

        if (preg_match('/^(?:[0-9]|10)$/', $level) === 1) {
            return " --phpstan-level={$level}";
        }

        return '';
    }

    private function phpStanExcludePaths(string $source): array
    {
        $flatExcludes = $this->neonListValue($source, 'excludePaths');

        if ($flatExcludes !== []) {
            return $flatExcludes;
        }

        return $this->neonNestedListValues($source, 'excludePaths', ['analyse', 'analyseAndScan']);
    }

    private function phpStanDiscoveryIncludes(string $source): array
    {
        $includes = [];

        foreach (['scanDirectories', 'scanFiles', 'bootstrapFiles', 'stubFiles'] as $key) {
            $includes = array_merge($includes, $this->neonListValue($source, $key));
        }

        return $this->normalizePhpStanIncludePaths($includes);
    }

    private function neonNestedListValues(string $source, string $key, array $nestedKeys): array
    {
        $lines = preg_split('/\R/', $source);

        if (! is_array($lines)) {
            return [];
        }

        $values = [];
        $inParent = false;
        $parentIndent = 0;
        $inNestedList = false;
        $nestedIndent = 0;

        foreach ($lines as $line) {
            if (! $inParent && preg_match('/^(\s*)' . preg_quote($key, '/') . '\s*:\s*$/', $line, $matches) === 1) {
                $inParent = true;
                $parentIndent = strlen($matches[1]);
                continue;
            }

            if (! $inParent) {
                continue;
            }

            if (trim($line) === '') {
                continue;
            }

            $lineIndent = strlen($line) - strlen(ltrim($line));

            if ($lineIndent <= $parentIndent) {
                break;
            }

            if (preg_match('/^(\s*)(' . implode('|', array_map(static fn (string $nestedKey): string => preg_quote($nestedKey, '/'), $nestedKeys)) . ')\s*:\s*$/', $line, $nestedMatches) === 1) {
                $inNestedList = true;
                $nestedIndent = strlen($nestedMatches[1]);
                continue;
            }

            if (! $inNestedList) {
                continue;
            }

            if ($lineIndent <= $nestedIndent) {
                $inNestedList = false;
                continue;
            }

            if (preg_match('/^\s*-\s*[\'"]?([^\'"#]+)[\'"]?/', $line, $valueMatches) === 1) {
                $values[] = trim($valueMatches[1]);
            }
        }

        return array_values(array_unique($values));
    }

    private function normalizePhpStanPaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            $path = trim($path);

            if ($path === '' || str_starts_with($path, '%')) {
                continue;
            }

            $path = preg_replace('#^\./#', '', $path) ?? $path;
            $path = rtrim($path, '/');

            if ($path === 'vendor' || str_starts_with($path, 'vendor/') || $path === 'storage' || str_starts_with($path, 'storage/') || $path === 'database' || str_starts_with($path, 'database/')) {
                continue;
            }

            if (str_ends_with($path, '/*')) {
                $path = substr($path, 0, -2) . '/**';
            }

            $normalized[] = $path;
        }

        return array_values(array_unique($normalized));
    }

    private function normalizePhpStanIncludePaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            $path = trim(str_replace('\\', '/', $path));

            if ($path === '' || str_starts_with($path, '%')) {
                continue;
            }

            $path = preg_replace('#^\./#', '', $path) ?? $path;
            $path = rtrim($path, '/');

            if ($path === 'vendor' || str_starts_with($path, 'vendor/')) {
                continue;
            }

            if (str_ends_with($path, '/*')) {
                $path = substr($path, 0, -2) . '/**';
            }

            $normalized[] = $path;
        }

        return array_values(array_unique($normalized));
    }

    private function phpStanIgnoredAnalyzerCodes(string $source): array
    {
        $ignores = $this->phpStanIgnoredAnalyzerIgnores($source);

        $codes = [];

        foreach ($ignores as $ignore) {
            if (is_string($ignore)) {
                $codes[$ignore] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * @return list<string|array{code: string, in: string}>
     */
    private function phpStanIgnoredAnalyzerIgnores(string $source): array
    {
        $globalCodes = [];
        $scopedIgnores = [];
        $entries = $this->phpStanIgnoreErrorEntries($source);

        if ($entries === []) {
            preg_match_all('/\bidentifier\s*:\s*[\'"]?([A-Za-z0-9_.-]+)[\'"]?/', $source, $matches);

            $entries = array_map(static fn (string $identifier): array => ['identifier: ' . $identifier], $matches[1]);
        }

        foreach ($entries as $entry) {
            $identifier = $this->phpStanIgnoreErrorIdentifier($entry);

            if ($identifier === null) {
                continue;
            }

            $paths = $this->normalizePhpStanIgnorePaths($this->phpStanIgnoreErrorPaths($entry));

            foreach ($this->phpStanIdentifierAnalyzerCodes($identifier) as $code) {
                if ($paths === []) {
                    $globalCodes[$code] = true;
                    continue;
                }

                foreach ($paths as $path) {
                    $scopedIgnores[] = [
                        'code' => $code,
                        'in' => $path,
                    ];
                }
            }
        }

        return $this->uniqueAnalyzerIgnores(array_merge(array_keys($globalCodes), $scopedIgnores));
    }

    /**
     * @return list<list<string>>
     */
    private function phpStanIgnoreErrorEntries(string $source): array
    {
        $lines = preg_split('/\R/', $source);

        if (! is_array($lines)) {
            return [];
        }

        $entries = [];
        $current = [];
        $inIgnoreErrors = false;
        $ignoreErrorsIndent = 0;
        $entryIndent = null;

        foreach ($lines as $line) {
            if (! $inIgnoreErrors && preg_match('/^(\s*)ignoreErrors\s*:\s*$/', $line, $matches) === 1) {
                $inIgnoreErrors = true;
                $ignoreErrorsIndent = strlen($matches[1]);
                continue;
            }

            if (! $inIgnoreErrors) {
                continue;
            }

            if (trim($line) === '') {
                continue;
            }

            $lineIndent = strlen($line) - strlen(ltrim($line));

            if ($lineIndent <= $ignoreErrorsIndent) {
                break;
            }

            if (
                preg_match('/^\s*-\s*(.*)$/', $line, $matches) === 1
                && ($entryIndent === null || $lineIndent <= $entryIndent)
            ) {
                if ($current !== []) {
                    $entries[] = $current;
                }

                $entryIndent = $lineIndent;
                $current = [$matches[1]];
                continue;
            }

            if ($current !== []) {
                $current[] = trim($line);
            }
        }

        if ($current !== []) {
            $entries[] = $current;
        }

        return $entries;
    }

    /**
     * @param list<string> $entry
     */
    private function phpStanIgnoreErrorIdentifier(array $entry): ?string
    {
        foreach ($entry as $line) {
            if (preg_match('/\bidentifier\s*:\s*[\'"]?([A-Za-z0-9_.-]+)[\'"]?/', $line, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * @param list<string> $entry
     *
     * @return list<string>
     */
    private function phpStanIgnoreErrorPaths(array $entry): array
    {
        $paths = [];
        $inPaths = false;

        foreach ($entry as $line) {
            $trimmed = trim($line);

            if (preg_match('/\bpath\s*:\s*[\'"]?([^\'"#\s]+)[\'"]?/', $trimmed, $matches) === 1) {
                $paths[] = $matches[1];
                $inPaths = false;
                continue;
            }

            if (preg_match('/\bpaths\s*:\s*\[([^\]]*)]/', $trimmed, $matches) === 1) {
                preg_match_all('/[\'"]?([^\'",\s]+)[\'"]?/', $matches[1], $valueMatches);
                $paths = array_merge($paths, $valueMatches[1]);
                $inPaths = false;
                continue;
            }

            if (preg_match('/\bpaths\s*:\s*$/', $trimmed) === 1) {
                $inPaths = true;
                continue;
            }

            if (! $inPaths) {
                continue;
            }

            if (preg_match('/^-\s*[\'"]?([^\'"#]+)[\'"]?/', $trimmed, $matches) === 1) {
                $paths[] = $matches[1];
                continue;
            }

            if (preg_match('/^[A-Za-z0-9_.-]+\s*:/', $trimmed) === 1) {
                $inPaths = false;
            }
        }

        return array_values(array_filter(array_map('trim', $paths), static fn (string $path): bool => $path !== ''));
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function normalizePhpStanIgnorePaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            $path = trim(str_replace('\\', '/', $path));

            if ($path === '' || str_starts_with($path, '%')) {
                continue;
            }

            $path = preg_replace('#^\./#', '', $path) ?? $path;
            $path = rtrim($path, '/');

            if (str_ends_with($path, '/*')) {
                $path = substr($path, 0, -2) . '/**';
            }

            $normalized[] = $path;
        }

        return array_values(array_unique($normalized));
    }

    private function phpStanIdentifierAnalyzerCodes(string $identifier): array
    {
        return match ($identifier) {
            'argument.templateType', 'argument.type' => [
                'invalid-argument',
                'possibly-invalid-argument',
                'null-argument',
                'possibly-null-argument',
                'possibly-false-argument',
                'less-specific-argument',
                'less-specific-nested-argument-type',
            ],
            'method.notFound' => [
                'non-existent-method',
                'possibly-non-existent-method',
                'mixed-method-access',
            ],
            'missingType.generics' => [
                'missing-template-parameter',
                'invalid-template-parameter',
            ],
            'missingType.iterableValue' => [],
            'offsetAccess.notFound' => [
                'invalid-array-access',
                'possibly-invalid-array-access',
                'null-array-index',
                'possibly-null-array-access',
                'possibly-null-array-index',
                'undefined-int-array-index',
                'undefined-string-array-index',
            ],
            'property.notFound' => [
                'non-existent-property',
                'invalid-property-access',
                'invalid-property-read',
                'mixed-property-access',
                'possibly-null-property-access',
            ],
            'return.type' => [
                'invalid-return-statement',
                'nullable-return-statement',
                'falsable-return-statement',
                'mixed-return-statement',
                'less-specific-return-statement',
                'less-specific-nested-return-statement',
            ],
            default => [],
        };
    }

    private function updateComposerScripts(string $projectRoot, string $analyzeScript, string $debugScript, string $baselineScript): bool
    {
        $composerPath = $projectRoot . '/composer.json';

        if (! is_file($composerPath)) {
            return false;
        }

        $source = file_get_contents($composerPath);

        if (! is_string($source)) {
            return false;
        }

        try {
            $composer = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (! is_array($composer)) {
            return false;
        }

        $scripts = $composer['scripts'] ?? [];

        if (! is_array($scripts)) {
            $scripts = [];
        }

        $scripts = $this->replacePhpStanComposerScriptCommands($scripts, $analyzeScript, $debugScript, $baselineScript);
        $scripts['phpstan'] = [$analyzeScript];
        $scripts['phpstan:ci'] = [$analyzeScript];
        $scripts['phpstan:ci:debug'] = [$debugScript];
        $scripts['laramago:baseline'] = [$baselineScript];
        $composer['scripts'] = $scripts;

        $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            return false;
        }

        return file_put_contents($composerPath, $encoded . PHP_EOL) !== false;
    }

    private function replacePhpStanComposerScriptCommands(array $scripts, string $analyzeScript, string $debugScript, string $baselineScript): array
    {
        foreach ($scripts as $name => $script) {
            $scriptName = is_string($name) ? $name : '';
            $replacement = $this->laramagoScriptForComposerScript($scriptName, $analyzeScript, $debugScript, $baselineScript);

            if (is_string($script)) {
                $scripts[$name] = $this->replacePhpStanComposerCommand($script, $replacement, $baselineScript);
                continue;
            }

            if (! is_array($script)) {
                continue;
            }

            foreach ($script as $index => $command) {
                if (is_string($command)) {
                    $script[$index] = $this->replacePhpStanComposerCommand($command, $replacement, $baselineScript);
                }
            }

            $scripts[$name] = $script;
        }

        return $scripts;
    }

    private function laramagoScriptForComposerScript(string $scriptName, string $analyzeScript, string $debugScript, string $baselineScript): string
    {
        $scriptName = strtolower($scriptName);

        if (str_contains($scriptName, 'baseline')) {
            return $baselineScript;
        }

        if (str_contains($scriptName, 'debug')) {
            return $debugScript;
        }

        return $analyzeScript;
    }

    private function replacePhpStanComposerCommand(string $command, string $replacement, string $baselineScript): string
    {
        $trimmed = ltrim($command);

        if ($trimmed === '' || str_starts_with($trimmed, '@')) {
            return $command;
        }

        if (! $this->isPhpStanAnalyzeCommand($command)) {
            return $command;
        }

        return str_contains($command, '--generate-baseline') ? $baselineScript : $replacement;
    }

    private function isPhpStanAnalyzeCommand(string $command): bool
    {
        return preg_match(
            '/(?:^|\s)(?:\.\/)?(?:vendor\/bin\/)?phpstan(?:\.phar)?(?:\s+(?:(?:--?[A-Za-z0-9_-]+)(?:=\S+)?|\S+))*\s+(?:analyse|analyze)(?:\s|$)/',
            $command,
        ) === 1;
    }
}
