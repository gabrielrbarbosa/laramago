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

    private function neonListValue(string $source, string $key): array
    {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*:\s*\[([^\]]*)\]/m', $source, $inlineMatches) === 1) {
            preg_match_all('/[\'"]?([^\'",\s]+)[\'"]?/', $inlineMatches[1], $valueMatches);

            return array_values(array_filter(array_map('trim', $valueMatches[1]), static fn (string $value): bool => $value !== ''));
        }

        $lines = preg_split('/\R/', $source);

        if (! is_array($lines)) {
            return [];
        }

        $values = [];
        $inList = false;
        $indent = 0;

        foreach ($lines as $line) {
            if (! $inList && preg_match('/^(\s*)' . preg_quote($key, '/') . '\s*:\s*$/', $line, $matches) === 1) {
                $inList = true;
                $indent = strlen($matches[1]);
                continue;
            }

            if (! $inList) {
                continue;
            }

            if (trim($line) === '') {
                continue;
            }

            $lineIndent = strlen($line) - strlen(ltrim($line));

            if ($lineIndent <= $indent) {
                break;
            }

            if (preg_match('/^\s*-\s*[\'"]?([^\'"#]+)[\'"]?/', $line, $valueMatches) === 1) {
                $values[] = trim($valueMatches[1]);
            }
        }

        return $values;
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

    private function phpStanIgnoredAnalyzerCodes(string $source): array
    {
        preg_match_all('/\bidentifier\s*:\s*([A-Za-z0-9_.-]+)/', $source, $matches);

        $codes = [];

        foreach ($matches[1] as $identifier) {
            foreach ($this->phpStanIdentifierAnalyzerCodes($identifier) as $code) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
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
