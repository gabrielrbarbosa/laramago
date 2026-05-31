<?php

declare(strict_types=1);

namespace Laramago\Concerns;

trait BuildsSourceCompatibilityOverlays
{
    private function phpStanPragmaSubstitutions(string $projectRoot, array $arguments, array $skippedOriginalPaths = []): array
    {
        if (in_array('--no-phpstan-pragma-overlays', $arguments, true)) {
            return [];
        }

        $overlayDirectory = $projectRoot . '/' . self::PHPSTAN_PRAGMA_OVERLAY_DIR;
        $caseInsensitiveAliasCandidates = $this->caseInsensitiveMethodAliasCandidates($projectRoot);

        if (is_dir($overlayDirectory)) {
            $this->removeDirectory($overlayDirectory);
        }

            $substitutions = [];
            $pathMap = [];
            $seenFiles = [];
            $config = $this->projectConfigValues($projectRoot);
            $excludes = $config['excludes'];
            $observerModels = $this->laravelObserverModelMap($projectRoot, $arguments);

            foreach ($this->sourceOverlayPaths($projectRoot, $arguments, $config['paths']) as $path) {
                foreach ($this->sourcePhpFiles($projectRoot, $path) as $file) {
                if (isset($seenFiles[$file]) || isset($skippedOriginalPaths[$file])) {
                    continue;
                }

                $relativePath = ltrim(substr($file, strlen($projectRoot)), '/');

                if ($this->isExcludedProjectPath($relativePath, $excludes)) {
                    continue;
                }

                $seenFiles[$file] = true;
                $source = file_get_contents($file);

                if (! is_string($source)) {
                    continue;
                }

                $translated = $this->translatePhpStanPragmas($source);
                $translated = $this->translateLaravelDateHelperCalls($translated);
                $translated = $this->annotateLaravelCollectionMacroClosures($translated);
                $translated = $this->annotateLaravelExcelEventClosures($translated);
                $translated = $this->annotateLaravelValidationRuleClosures($translated);
                $translated = $this->annotateLaravelQueryBuilderClosures($translated);
                $translated = $this->annotateLaravelJoinClauseClosures($translated);
                $translated = $this->annotateLaravelForeachObjectRows($translated);
                $translated = $this->annotateLaravelNumericFallbackAssignments($translated);
                $translated = $this->annotateLaravelRequestParameters($translated);
                $translated = $this->rewriteLaravelRequestPropertyReads($translated, $projectRoot);
                $translated = $this->castLaravelRequestForeachSources($translated);
                $translated = $this->annotateLaravelRequestInputArrayVariables($translated);
                $translated = $this->annotateLaravelObserverModelParameters($translated, $relativePath, $observerModels);
                $translated = $this->annotateLaravelJsonResourceDynamicMembers($translated, $relativePath);
                $translated = $this->annotateLaravelCollectionItemObjectClosures($translated);
                $translated = $this->annotateLaravelFormRequestDynamicProperties($translated, $relativePath, $projectRoot);
                $translated = $this->annotateAllowDynamicPropertiesClasses($translated);
                $minimumAliases = $translated === $source ? 2 : 1;
                $overlay = $this->insertTraitSelfCallMethods($this->insertCaseInsensitiveMethodAliases($translated, $caseInsensitiveAliasCandidates, $minimumAliases));

                if ($overlay === $source) {
                    continue;
                }

                $overlayRelativePath = self::PHPSTAN_PRAGMA_OVERLAY_DIR . '/' . sha1($relativePath) . '.php';
                $this->ensureDirectory($overlayDirectory);
                file_put_contents($projectRoot . '/' . $overlayRelativePath, $overlay);

                $pathMap[] = [
                    'original' => $relativePath,
                    'overlay' => $overlayRelativePath,
                ];

                $substitutions[] = '--substitute';
                $substitutions[] = $file . '=' . $projectRoot . '/' . $overlayRelativePath;
            }
        }

        file_put_contents($projectRoot . '/' . self::PHPSTAN_PRAGMA_OVERLAY_MAP, json_encode($pathMap, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $substitutions;
    }

    private function sourceOverlayPaths(string $projectRoot, array $arguments, array $configuredPaths): array
    {
        $paths = [];

        foreach ($configuredPaths as $path) {
            $paths[$this->normalizeProjectPath($path)] = true;
        }

        foreach ($this->stripLaramagoOptions($arguments) as $argument) {
            if ($argument === '' || str_starts_with($argument, '-')) {
                continue;
            }

            $path = $this->normalizeProjectPath($argument);

            if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '../')) {
                continue;
            }

            if (is_file($projectRoot . '/' . $path) || is_dir($projectRoot . '/' . $path)) {
                $paths[$path] = true;
            }
        }

        return array_values(array_filter(array_keys($paths), static fn (string $path): bool => $path !== ''));
    }

    private function isExcludedProjectPath(string $path, array $excludes): bool
    {
        $path = $this->normalizeProjectPath($path);

        foreach ($excludes as $exclude) {
            $exclude = $this->normalizeProjectPath($exclude);

            if ($exclude === '') {
                continue;
            }

            if (str_ends_with($exclude, '/**')) {
                $directory = rtrim(substr($exclude, 0, -3), '/');

                if ($path === $directory || str_starts_with($path, $directory . '/')) {
                    return true;
                }

                continue;
            }

            if ($path === $exclude || str_starts_with($path, rtrim($exclude, '/') . '/')) {
                return true;
            }

            if (fnmatch($exclude, $path, FNM_PATHNAME)) {
                return true;
            }
        }

        return false;
    }

    private function sourcePhpFiles(string $projectRoot, string $path): array
    {
        $path = $this->normalizeProjectPath($this->baseDirectoryFromGlob($path));

        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '../')) {
            return [];
        }

        $absolutePath = $projectRoot . '/' . $path;

        if (is_file($absolutePath) && str_ends_with($absolutePath, '.php')) {
            return [$absolutePath];
        }

        if (is_dir($absolutePath)) {
            return $this->phpFiles($absolutePath);
        }

        return [];
    }

    private function translatePhpStanPragmas(string $source): string
    {
        $lines = preg_split('/(\R)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($lines)) {
            return $source;
        }

        $translated = '';

        for ($index = 0; $index < count($lines); $index += 2) {
            $line = (string) $lines[$index];
            $lineEnding = (string) ($lines[$index + 1] ?? '');

            if (str_contains($line, '@phpstan-ignore-line')) {
                preg_match('/^\s*/', $line, $matches);
                $translated .= ($matches[0] ?? '') . '// @mago-ignore all' . $lineEnding . $line . $lineEnding;

                continue;
            }

            $line = preg_replace('/@phpstan-ignore-next-line\b[^\r\n*]*/', '@mago-ignore all', $line) ?? $line;
            $line = preg_replace('/@phpstan-ignore\b[^\r\n*]*/', '@mago-ignore all', $line) ?? $line;
            $translated .= $line . $lineEnding;
        }

        return $translated;
    }

    private function translateLaravelDateHelperCalls(string $source): string
    {
        $tokens = token_get_all($source);
        $translated = '';
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (! is_array($token) || $token[0] !== T_STRING || ! in_array(strtolower($token[1]), ['now', 'today', 'response'], true)) {
                $translated .= is_array($token) ? $token[1] : $token;

                continue;
            }

            $helper = strtolower($token[1]);
            $next = $this->nextMeaningfulTokenIndex($tokens, $index + 1);

            if ($next === null || $tokens[$next] !== '(') {
                $translated .= $token[1];

                continue;
            }

            $previous = $this->previousMeaningfulTokenIndex($tokens, $index - 1);
            $previousToken = $previous === null ? null : $tokens[$previous];

            if (is_array($previousToken) && in_array($previousToken[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                $translated .= $token[1];

                continue;
            }

            if ($previousToken === '\\') {
                $translated .= $token[1];

                continue;
            }

            if ($helper === 'response') {
                if (! $this->hasCallArguments($tokens, $next)) {
                    $translated .= $token[1];

                    continue;
                }

                $translated .= '\\Illuminate\\Support\\Facades\\Response::make';

                continue;
            }

            $translated .= '\\Illuminate\\Support\\Carbon::' . $helper;
        }

        return $translated;
    }

    private function hasCallArguments(array $tokens, int $openParenthesis): bool
    {
        $depth = 0;
        $count = count($tokens);

        for ($index = $openParenthesis; $index < $count; $index++) {
            $token = $tokens[$index];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '(') {
                $depth++;

                continue;
            }

            if ($text === ')') {
                $depth--;

                if ($depth === 0) {
                    return false;
                }

                continue;
            }

            if ($depth === 1 && ! (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                return true;
            }
        }

        return false;
    }

    private function annotateLaravelCollectionMacroClosures(string $source): string
    {
        $translated = preg_replace_callback(
            '/((?:\\\\?Illuminate\\\\Support\\\\)?Collection::macro\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*function\s*\([^)]*\)\s*(?::\s*[^{]+)?\{)(?!\s*\/\*\*\s*@var\s+[^*]*\$this)/m',
            static fn (array $matches): string => $matches[1] . PHP_EOL . '                    /** @var \Illuminate\Support\Collection $this */',
            $source,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function annotateLaravelCollectionItemObjectClosures(string $source): string
    {
        if (! str_contains($source, 'function') || ! str_contains($source, '->')) {
            return $source;
        }

        $methods = [
            'each',
            'every',
            'filter',
            'first',
            'flatMap',
            'groupBy',
            'keyBy',
            'map',
            'mapInto',
            'mapSpread',
            'mapToGroups',
            'mapWithKeys',
            'partition',
            'reject',
            'some',
            'sortBy',
            'sortByDesc',
            'tap',
            'transform',
            'unless',
            'when',
        ];
        $methodPattern = implode('|', array_map(static fn (string $method): string => preg_quote($method, '/'), $methods));

        $translated = preg_replace_callback(
            '/(->\s*(?:' . $methodPattern . ')\s*\(\s*(?:static\s+)?function\s*\(\s*)\$([A-Za-z_][A-Za-z0-9_]*)(\s*(?:,[^)]*)?\)\s*(?:use\s*\([^)]*\)\s*)?(?::\s*[^{]+)?\{)(?!\s*\/\*\*\s*@var\s+[^*]*\$[A-Za-z_][A-Za-z0-9_]*)/ms',
            static function (array $matches) use ($source): string {
                $matched = $matches[0][0];
                $offset = $matches[0][1];
                $variable = $matches[2][0];
                $bodyPreview = substr($source, $offset + strlen($matched), 1200);
                $closureEnd = strpos($bodyPreview, '});');

                if ($closureEnd !== false) {
                    $bodyPreview = substr($bodyPreview, 0, $closureEnd);
                }

                if (! str_contains($bodyPreview, '$' . $variable . '->')) {
                    return $matched;
                }

                return $matches[1][0] . '$' . $variable . $matches[3][0] . PHP_EOL . '                /** @var object $' . $variable . ' */';
            },
            $source,
            -1,
            $count,
            PREG_OFFSET_CAPTURE,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function annotateLaravelForeachObjectRows(string $source): string
    {
        if (! str_contains($source, 'foreach') || ! str_contains($source, '->')) {
            return $source;
        }

        $translated = preg_replace_callback(
            '/^([ \t]*foreach\s*\([^)]*\s+as\s+(?:\$[A-Za-z_][A-Za-z0-9_]*\s*=>\s*)?\$([A-Za-z_][A-Za-z0-9_]*)\s*\)\s*\{)(?!\s*\/\*\*\s*@var\s+[^*]*\$[A-Za-z_][A-Za-z0-9_]*)/m',
            static function (array $matches) use ($source): string {
                $matched = $matches[0][0];
                $offset = $matches[0][1];
                $variable = $matches[2][0];
                $bodyPreview = substr($source, $offset + strlen($matched), 1600);
                $loopEnd = strpos($bodyPreview, "\n" . $matches[1][0][0] . '}');

                if ($loopEnd !== false) {
                    $bodyPreview = substr($bodyPreview, 0, $loopEnd);
                }

                if (! str_contains($bodyPreview, '$' . $variable . '->')) {
                    return $matched;
                }

                return $matched . PHP_EOL . '                /** @var object $' . $variable . ' */';
            },
            $source,
            -1,
            $count,
            PREG_OFFSET_CAPTURE,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function annotateLaravelNumericFallbackAssignments(string $source): string
    {
        if (! str_contains($source, '?? 0') && ! str_contains($source, '?: 0')) {
            return $source;
        }

        $translated = preg_replace_callback(
            '/^([ \t]*)(\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*[^;\r\n]*(?:\?\?\s*0(?:\.0)?|\?:\s*0(?:\.0)?)\s*;)/m',
            static function (array $matches) use ($source): string {
                $variable = $matches[3];
                $prefix = substr($source, max(0, (int) strpos($source, $matches[0]) - 120), 120);

                if (preg_match('/@var\s+(?:int|float|int\|float|float\|int)[^$]*\$' . preg_quote($variable, '/') . '\b/', $prefix) === 1) {
                    return $matches[0];
                }

                return $matches[1] . '/** @var int|float $' . $variable . ' */' . PHP_EOL . $matches[0];
            },
            $source,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function annotateLaravelExcelEventClosures(string $source): string
    {
        if (! str_contains($source, '::class') || ! str_contains($source, 'function')) {
            return $source;
        }

        $events = [
            'BeforeExport' => '\\Maatwebsite\\Excel\\Events\\BeforeExport',
            'BeforeWriting' => '\\Maatwebsite\\Excel\\Events\\BeforeWriting',
            'BeforeSheet' => '\\Maatwebsite\\Excel\\Events\\BeforeSheet',
            'AfterSheet' => '\\Maatwebsite\\Excel\\Events\\AfterSheet',
        ];

        $translated = $source;

        foreach ($events as $event => $class) {
            $pattern = '/((?:\\\\?Maatwebsite\\\\Excel\\\\Events\\\\)?' . preg_quote($event, '/') . '::class\s*=>\s*(?:static\s+)?function\s*\(\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*\)\s*(?::\s*[^{]+)?\{)(?!\s*\/\*\*\s*@var\s+[^*]*\$[A-Za-z_][A-Za-z0-9_]*)/m';
            $translated = preg_replace_callback(
                $pattern,
                static fn (array $matches): string => $matches[1] . PHP_EOL . '                /** @var ' . $class . ' $' . $matches[2] . ' */',
                $translated,
            ) ?? $translated;
        }

        return $translated;
    }

    private function annotateLaravelValidationRuleClosures(string $source): string
    {
        if (! str_contains($source, '$fail') || ! str_contains($source, 'function')) {
            return $source;
        }

        $translated = preg_replace_callback(
            '/((?:static\s+)?function\s*\(\s*\$[A-Za-z_][A-Za-z0-9_]*\s*,\s*\$[A-Za-z_][A-Za-z0-9_]*\s*,\s*\$fail\s*\)(?:\s*use\s*\([^)]*\))?\s*(?::\s*[^{]+)?\{)(?!\s*\/\*\*\s*@var\s+callable\s+\$fail)/m',
            static fn (array $matches): string => $matches[1] . PHP_EOL . '                /** @var callable $fail */',
            $source,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function annotateLaravelQueryBuilderClosures(string $source): string
    {
        if (! str_contains($source, 'function')) {
            return $source;
        }

        $methods = [
            'where',
            'orWhere',
            'whereIn',
            'orWhereIn',
            'whereExists',
            'orWhereExists',
            'whereNotExists',
            'orWhereNotExists',
        ];
        $methodPattern = implode('|', array_map(static fn (string $method): string => preg_quote($method, '/'), $methods));

        $translated = preg_replace_callback(
            '/(->\s*(?:' . $methodPattern . ')\s*\((?:(?!->|[;{]).)*?function\s*\(\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*\)(?:\s*use\s*\([^)]*\))?\s*(?::\s*[^{]+)?\{)(?!\s*\/\*\*\s*@var\s+[^*]*\$[A-Za-z_][A-Za-z0-9_]*)/ms',
            static fn (array $matches): string => $matches[1] . PHP_EOL . '                /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $' . $matches[2] . ' */',
            $source,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function annotateLaravelJoinClauseClosures(string $source): string
    {
        if (! str_contains($source, 'function')) {
            return $source;
        }

        $methods = [
            'join',
            'joinSub',
            'leftJoin',
            'leftJoinSub',
            'rightJoin',
            'rightJoinSub',
            'crossJoin',
        ];
        $methodPattern = implode('|', array_map(static fn (string $method): string => preg_quote($method, '/'), $methods));

        $translated = preg_replace_callback(
            '/(->\s*(?:' . $methodPattern . ')\s*\((?:(?!->|[;{]).)*?function\s*\(\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*\)(?:\s*use\s*\([^)]*\))?\s*(?::\s*[^{]+)?\{)(?!\s*\/\*\*\s*@var\s+[^*]*\$[A-Za-z_][A-Za-z0-9_]*)/ms',
            static fn (array $matches): string => $matches[1] . PHP_EOL . '                /** @var \Illuminate\Database\Query\JoinClause $' . $matches[2] . ' */',
            $source,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function annotateLaravelObserverModelParameters(string $source, string $relativePath, array $observerModels): string
    {
        $models = $observerModels[$relativePath] ?? null;

        if ($models === null || $models === [] || ! str_contains($source, 'function')) {
            return $source;
        }

        $modelType = implode('|', array_map(static fn (string $model): string => '\\' . ltrim($model, '\\'), $models));
        $events = implode('|', [
            'retrieved',
            'creating',
            'created',
            'updating',
            'updated',
            'saving',
            'saved',
            'deleting',
            'deleted',
            'restoring',
            'restored',
            'forceDeleting',
            'forceDeleted',
            'replicating',
        ]);

        $translated = preg_replace_callback(
            '/(\bfunction\s+(?:' . $events . ')\s*\(\s*)(?:(?:\\\\?Illuminate\\\\Database\\\\Eloquent\\\\)?Model|mixed|object)\s+(\$[A-Za-z_][A-Za-z0-9_]*)/m',
            static fn (array $matches): string => $matches[1] . $modelType . ' ' . $matches[2],
            $source,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function annotateLaravelRequestParameters(string $source): string
    {
        if (! str_contains($source, 'mixed $request') || ! $this->usesLaravelRequestParameter($source)) {
            return $source;
        }

        $translated = preg_replace_callback(
            '/\bmixed\s+\$request\b/',
            static fn (): string => '\\Illuminate\\Http\\Request $request',
            $source,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function usesLaravelRequestParameter(string $source): bool
    {
        return preg_match('/\$request\s*->\s*(?:all|boolean|collect|date|enum|file|filled|get|has|header|input|integer|isMethod|merge|method|only|post|query|route|string|validate)\s*\(/', $source) === 1
            || preg_match('/\$request\s*->\s*(?:method)\b(?!\s*\()/', $source) === 1;
    }

    private function rewriteLaravelRequestPropertyReads(string $source, string $projectRoot): string
    {
        $lines = preg_split('/(\R)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($lines)) {
            return $source;
        }

        $translated = '';
        $requestProperties = $this->requestTypedProperties($projectRoot, $source);

        for ($index = 0; $index < count($lines); $index += 2) {
            $line = (string) $lines[$index];
            $lineEnding = (string) ($lines[$index + 1] ?? '');

            $line = preg_replace_callback(
                '/isset\(\s*\$request\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\)/',
                static fn (array $matches): string => '$request->input(\'' . $matches[1] . '\') !== null',
                $line,
            ) ?? $line;
            $line = preg_replace_callback(
                '/isset\(\s*\$this\s*->\s*request\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\)/',
                static fn (array $matches): string => '$this->request->input(\'' . $matches[1] . '\') !== null',
                $line,
            ) ?? $line;

            foreach (array_keys($requestProperties) as $property) {
                $line = preg_replace_callback(
                    '/isset\(\s*\$this\s*->\s*' . preg_quote($property, '/') . '\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\)/',
                    static fn (array $matches): string => '$this->' . $property . '->input(\'' . $matches[1] . '\') !== null',
                    $line,
                ) ?? $line;
            }

            $line = preg_replace_callback(
                '/^([ \t]*)\$request\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.+);\s*$/',
                static fn (array $matches): string => $matches[1] . '$request->merge([\'' . $matches[2] . '\' => ' . $matches[3] . ']);',
                $line,
            ) ?? $line;
            $line = preg_replace_callback(
                '/^([ \t]*)\$this\s*->\s*request\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.+);\s*$/',
                static fn (array $matches): string => $matches[1] . '$this->request->merge([\'' . $matches[2] . '\' => ' . $matches[3] . ']);',
                $line,
            ) ?? $line;

            foreach (array_keys($requestProperties) as $property) {
                $line = preg_replace_callback(
                    '/^([ \t]*)\$this\s*->\s*' . preg_quote($property, '/') . '\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.+);\s*$/',
                    static fn (array $matches): string => $matches[1] . '$this->' . $property . '->merge([\'' . $matches[2] . '\' => ' . $matches[3] . ']);',
                    $line,
                ) ?? $line;
            }

            $line = preg_replace_callback(
                '/\$request\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\b(?!\s*(?:\(|=(?!=|>)|\+=|-=|\*=|\/=|%=|\.=|\?\?=|\+\+|--))/',
                static fn (array $matches): string => '$request->input(\'' . $matches[1] . '\')',
                $line,
            ) ?? $line;
            $line = preg_replace_callback(
                '/\$this\s*->\s*request\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\b(?!\s*(?:\(|=(?!=|>)|\+=|-=|\*=|\/=|%=|\.=|\?\?=|\+\+|--))/',
                static fn (array $matches): string => '$this->request->input(\'' . $matches[1] . '\')',
                $line,
            ) ?? $line;

            foreach (array_keys($requestProperties) as $property) {
                $line = preg_replace_callback(
                    '/\$this\s*->\s*' . preg_quote($property, '/') . '\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\b(?!\s*(?:\(|=(?!=|>)|\+=|-=|\*=|\/=|%=|\.=|\?\?=|\+\+|--))/',
                    static fn (array $matches): string => '$this->' . $property . '->input(\'' . $matches[1] . '\')',
                    $line,
                ) ?? $line;
            }

            $translated .= $line . $lineEnding;
        }

        return $translated;
    }

    private function annotateLaravelRequestInputArrayVariables(string $source): string
    {
        if (! str_contains($source, '->input(') && ! str_contains($source, '->all(') && ! str_contains($source, '->validated(')) {
            return $source;
        }

        $translated = preg_replace_callback(
            '/^([ \t]*)(\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:\$this->(?:[A-Za-z_][A-Za-z0-9_]*)|\$[A-Za-z_][A-Za-z0-9_]*|request\(\))\s*->\s*(?:input|all|validated)\s*\([^;]*\);)/m',
            static function (array $matches) use ($source): string {
                $variable = $matches[3];

                if (preg_match('/foreach\s*\(\s*\$' . preg_quote($variable, '/') . '\s+as\b/', $source) !== 1
                    && preg_match('/\$' . preg_quote($variable, '/') . '\s*\[/', $source) !== 1) {
                    return $matches[0];
                }

                $prefix = substr($source, max(0, (int) strpos($source, $matches[0]) - 120), 120);

                if (preg_match('/@var\s+array[^$]*\$' . preg_quote($variable, '/') . '\b/', $prefix) === 1) {
                    return $matches[0];
                }

                return $matches[1] . '/** @var array<array-key, mixed> $' . $variable . ' */' . PHP_EOL . $matches[0];
            },
            $source,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function castLaravelRequestForeachSources(string $source): string
    {
        if (! str_contains($source, 'foreach') || (! str_contains($source, '->input(') && ! str_contains($source, '->all(') && ! str_contains($source, '->validated('))) {
            return $source;
        }

        $requestSource = '(?:\\$[A-Za-z_][A-Za-z0-9_]*|\\$this\\s*->\\s*[A-Za-z_][A-Za-z0-9_]*|request\\(\\))';
        $requestCall = $requestSource . '\\s*->\\s*(?:input|all|validated)\\s*\\([^)]*\\)';

        $translated = preg_replace_callback(
            '/foreach\s*\(\s*(?!\(array\)\s*)(' . $requestCall . ')\s+as\b/m',
            static fn (array $matches): string => 'foreach ((array) ' . $matches[1] . ' as',
            $source,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function requestTypedProperties(string $projectRoot, string $source): array
    {
        return $this->sourceRequestTypedProperties($source) + $this->usedTraitRequestTypedProperties($projectRoot, $source);
    }

    private function sourceRequestTypedProperties(string $source): array
    {
        $properties = [];

        $patterns = [
            '/(?:public|protected|private)\s+(?:readonly\s+)?\??([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s+\$([A-Za-z_][A-Za-z0-9_]*)/m',
            '/(?:public|protected|private)\s+(?:readonly\s+)?\??([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s+\&?\s*\$([A-Za-z_][A-Za-z0-9_]*)/m',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $type = strtolower(ltrim($match[1], '\\'));

                if ($type === 'request' || str_ends_with($type, '\\request')) {
                    $properties[$match[2]] = true;
                }
            }
        }

        return $properties;
    }

    private function usedTraitRequestTypedProperties(string $projectRoot, string $source): array
    {
        if (preg_match_all('/^[ \t]+use\s+([^;]+);/m', $source, $matches) === 0) {
            return [];
        }

        $properties = [];

        foreach ($matches[1] as $declaration) {
            foreach (explode(',', $declaration) as $trait) {
                $trait = trim(preg_replace('/\s+as\s+.*$/i', '', $trait) ?? $trait);

                if ($trait === '') {
                    continue;
                }

                $class = str_contains($trait, '\\')
                    ? ltrim($trait, '\\')
                    : ($this->importedClassName($source, $trait) ?? $this->namespacedClassName($source, $trait));

                if ($class === null) {
                    continue;
                }

                $path = $this->projectClassPath($projectRoot, $class);

                if ($path === null || ! is_file($path)) {
                    continue;
                }

                $traitSource = file_get_contents($path);

                if (! is_string($traitSource)) {
                    continue;
                }

                $properties += $this->sourceRequestTypedProperties($traitSource);
            }
        }

        return $properties;
    }

    private function annotateLaravelJsonResourceDynamicMembers(string $source, string $relativePath): string
    {
        $normalizedPath = str_replace('\\', '/', $relativePath);

        if (! str_contains($normalizedPath, '/Http/Resources/') && ! str_starts_with($normalizedPath, 'app/Http/Resources/')) {
            return $source;
        }

        if (preg_match('/^(?:(?:abstract|final|readonly)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\b/m', $source, $classMatches) !== 1) {
            return $source;
        }

        $parent = strtolower($classMatches[2]);

        $isJsonResource = str_ends_with($parent, 'jsonresource');
        $isResourceCollection = str_ends_with($parent, 'resourcecollection');

        if (! $isJsonResource && ! $isResourceCollection) {
            return $source;
        }

        $annotatedSource = $isResourceCollection ? $this->annotateLaravelResourceCollectionTransforms($source) : $source;
        $sourceChanged = $annotatedSource !== $source;
        $source = $annotatedSource;

        $declaredProperties = $this->declaredProperties($source);
        $properties = [];

        if (preg_match_all('/\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\b(?!\s*\()/', $source, $propertyMatches) > 0) {
            foreach ($propertyMatches[1] as $property) {
                if (isset($declaredProperties[$property]) || in_array($property, ['resource', 'with', 'additional', 'wrap', 'forceWrapping', 'preserveKeys'], true)) {
                    continue;
                }

                $properties[$property] = true;
            }
        }

        $methods = [];

        if (preg_match_all('/\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $methodMatches) > 0) {
            foreach ($methodMatches[1] as $method) {
                if (in_array($method, ['additional', 'jsonOptions', 'jsonSerialize', 'resolve', 'response', 'toArray', 'toAttributes', 'toJson', 'toPrettyJson', 'toResponse', 'with', 'withResponse'], true)) {
                    continue;
                }

                $methods[$method] = true;
            }
        }

        if ($properties === [] && $methods === [] && ! $sourceChanged) {
            return $source;
        }

        $lines = [
            ' * @mixin \Illuminate\Database\Eloquent\Model',
        ];

        foreach (array_keys($properties) as $property) {
            $lines[] = ' * @property mixed $' . $property;
        }

        foreach (array_keys($methods) as $method) {
            $lines[] = ' * @method mixed ' . $method . '(mixed ...$parameters)';
        }

        return $this->insertClassDocblockLines($source, $classMatches[1], $lines);
    }

    private function annotateLaravelResourceCollectionTransforms(string $source): string
    {
        $translated = preg_replace_callback(
            '/^([ \t]*)(\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\$this\s*->\s*resource\s*;)/m',
            function (array $matches) use ($source): string {
                $variable = $matches[3];

                if (preg_match('/\$' . preg_quote($variable, '/') . '\s*->\s*getCollection\s*\(/', $source) !== 1) {
                    return $matches[0];
                }

                return $matches[1] . '/** @var \Illuminate\Pagination\AbstractPaginator $' . $variable . ' */' . PHP_EOL . $matches[0];
            },
            $source,
        );

        if (! is_string($translated)) {
            return $source;
        }

        $translated = preg_replace_callback(
            '/(map\s*\(\s*function\s*\(\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*\)\s*(?::\s*[^{]+)?\{)(?!\s*\/\*\*\s*@var\s+[^*]*\$[A-Za-z_][A-Za-z0-9_]*)/m',
            function (array $matches) use ($translated): string {
                $variable = $matches[2];

                if (! str_contains($translated, '$' . $variable . '->')) {
                    return $matches[0];
                }

                return $matches[1] . PHP_EOL . '                /** @var \Illuminate\Database\Eloquent\Model $' . $variable . ' */';
            },
            $translated,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function annotateLaravelFormRequestDynamicProperties(string $source, string $relativePath, string $projectRoot): string
    {
        if (! str_contains(str_replace('\\', '/', $relativePath), '/Http/Requests/') && ! str_starts_with(str_replace('\\', '/', $relativePath), 'app/Http/Requests/')) {
            return $source;
        }

        if (preg_match('/^(?:(?:abstract|final|readonly)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\b/m', $source, $classMatches) !== 1) {
            return $source;
        }

        $parent = strtolower($classMatches[2]);

        if (! str_ends_with($parent, 'request')) {
            return $source;
        }

        if (preg_match_all('/\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\b(?!\s*\()/', $source, $matches) === 0) {
            return $source;
        }

        $declaredProperties = $this->declaredProperties($source) + $this->usedTraitDeclaredProperties($projectRoot, $source);
        $properties = [];

        foreach ($matches[1] as $property) {
            if (in_array($property, ['request', 'container', 'redirector'], true)) {
                continue;
            }

            if (isset($declaredProperties[$property])) {
                continue;
            }

            $properties[$property] = true;
        }

        if ($properties === []) {
            return $source;
        }

        $lines = [];

        foreach (array_keys($properties) as $property) {
            $lines[] = ' * @property mixed $' . $property;
        }

        $source = $this->insertClassDocblockLines($source, $classMatches[1], $lines);

        if (! str_contains($source, 'function __set(')) {
            $source = $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Laramago overlay for Laravel FormRequest dynamic input/state properties.
     */
    public function __set(string $key, mixed $value): void
    {
    }
PHP);
        }

        return $source;
    }

    private function annotateAllowDynamicPropertiesClasses(string $source): string
    {
        if (! str_contains($source, 'AllowDynamicProperties') || ! str_contains($source, 'class ')) {
            return $source;
        }

        if (preg_match('/#\[\s*\\\\?AllowDynamicProperties\s*\]\s*(?:(?:abstract|final|readonly)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $source, $classMatches) !== 1) {
            return $source;
        }

        if (preg_match_all('/\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\b(?!\s*\()/', $source, $matches) !== 0) {
            $declaredProperties = $this->declaredProperties($source);
            $properties = [];

            foreach ($matches[1] as $property) {
                if (isset($declaredProperties[$property])) {
                    continue;
                }

                $properties[$property] = true;
            }

            if ($properties !== []) {
                $source = $this->insertClassDocblockLines(
                    $source,
                    $classMatches[1],
                    array_map(static fn (string $property): string => ' * @property mixed $' . $property, array_keys($properties)),
                );
            }
        }

        $methods = [];

        if (! str_contains($source, 'function __get(')) {
            $methods[] = <<<'PHP'

    public function __get(string $key): mixed
    {
        return null;
    }
PHP;
        }

        if (! str_contains($source, 'function __set(')) {
            $methods[] = <<<'PHP'

    public function __set(string $key, mixed $value): void
    {
    }
PHP;
        }

        if ($methods === []) {
            return $source;
        }

        return $this->insertBeforeFinalClassBrace($source, implode(PHP_EOL, $methods));
    }

    private function usedTraitDeclaredProperties(string $projectRoot, string $source): array
    {
        if (preg_match_all('/^[ \t]+use\s+([^;]+);/m', $source, $matches) === 0) {
            return [];
        }

        $properties = [];

        foreach ($matches[1] as $declaration) {
            foreach (explode(',', $declaration) as $trait) {
                $trait = trim(preg_replace('/\s+as\s+.*$/i', '', $trait) ?? $trait);

                if ($trait === '') {
                    continue;
                }

                $class = str_contains($trait, '\\')
                    ? ltrim($trait, '\\')
                    : ($this->importedClassName($source, $trait) ?? $this->namespacedClassName($source, $trait));

                if ($class === null) {
                    continue;
                }

                $path = $this->projectClassPath($projectRoot, $class);

                if ($path === null || ! is_file($path)) {
                    continue;
                }

                $traitSource = file_get_contents($path);

                if (! is_string($traitSource)) {
                    continue;
                }

                $properties += $this->declaredProperties($traitSource);
            }
        }

        return $properties;
    }

    private function declaredProperties(string $source): array
    {
        if (preg_match_all('/^[ \t]*(?:public|protected|private)\s+(?:readonly\s+)?(?:static\s+)?(?:[^$;\r\n=]+\s+)?\$([A-Za-z_][A-Za-z0-9_]*)/m', $source, $matches) === 0) {
            return [];
        }

        $properties = [];

        foreach ($matches[1] as $property) {
            $properties[$property] = true;
        }

        return $properties;
    }

    private function namespacedClassName(string $source, string $shortName): ?string
    {
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]) . '\\' . $shortName;
    }

    private function projectClassPath(string $projectRoot, string $class): ?string
    {
        if (str_starts_with($class, 'App\\')) {
            return $projectRoot . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        }

        return null;
    }

    private function substitutionOriginalPaths(array $substitutions): array
    {
        $paths = [];

        foreach ($substitutions as $index => $argument) {
            if ($argument !== '--substitute') {
                continue;
            }

            $substitution = $substitutions[$index + 1] ?? null;

            if (! is_string($substitution)) {
                continue;
            }

            $position = strpos($substitution, '=');

            if ($position !== false) {
                $paths[substr($substitution, 0, $position)] = true;
            }
        }

        return $paths;
    }

    private function laravelObserverModelMap(string $projectRoot, array $arguments): array
    {
        $config = $this->projectConfigValues($projectRoot);
        $seenFiles = [];
        $observerModels = [];

        foreach ($this->sourceOverlayPaths($projectRoot, $arguments, $config['paths']) as $path) {
            foreach ($this->sourcePhpFiles($projectRoot, $path) as $file) {
                if (isset($seenFiles[$file])) {
                    continue;
                }

                $seenFiles[$file] = true;
                $relativePath = ltrim(substr($file, strlen($projectRoot)), '/');

                if ($this->isExcludedProjectPath($relativePath, $config['excludes'])) {
                    continue;
                }

                $source = file_get_contents($file);

                if (! is_string($source) || (! str_contains($source, 'observe(') && ! str_contains($source, 'ObservedBy'))) {
                    continue;
                }

                if (preg_match('/^namespace\s+([^;]+);/m', $source) !== 1 || preg_match('/^(?:(?:abstract|final|readonly)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $source) !== 1) {
                    continue;
                }

                $modelClass = $this->sourceClassName($source);

                if ($modelClass === null) {
                    continue;
                }

                foreach ($this->observerClassReferences($source) as $observerClass) {
                    $observerPath = $this->projectClassPath($projectRoot, $observerClass);

                    if ($observerPath === null || ! is_file($observerPath)) {
                        continue;
                    }

                    $observerRelativePath = ltrim(substr($observerPath, strlen($projectRoot)), '/');
                    $observerModels[$observerRelativePath][$modelClass] = true;
                }
            }
        }

        $map = [];

        foreach ($observerModels as $observerPath => $models) {
            $modelNames = array_keys($models);
            sort($modelNames);
            $map[$observerPath] = $modelNames;
        }

        return $map;
    }

    private function sourceClassName(string $source): ?string
    {
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespaceMatches) !== 1) {
            return null;
        }

        if (preg_match('/^(?:(?:abstract|final|readonly)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $source, $classMatches) !== 1) {
            return null;
        }

        return trim($namespaceMatches[1]) . '\\' . $classMatches[1];
    }

    private function observerClassReferences(string $source): array
    {
        $observers = [];

        $calls = [];

        if (preg_match_all('/\b(?:static|self|parent|[A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::observe\s*\((.*?)\)\s*;/s', $source, $observeCalls) > 0) {
            $calls = array_merge($calls, $observeCalls[1]);
        }

        if (preg_match_all('/#\[\s*(?:\\\\?Illuminate\\\\Database\\\\Eloquent\\\\Attributes\\\\)?ObservedBy\s*\((.*?)\)\s*\]/s', $source, $attributeCalls) > 0) {
            $calls = array_merge($calls, $attributeCalls[1]);
        }

        foreach ($calls as $call) {
            if (preg_match_all('/([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::class/', $call, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $reference) {
                $class = $this->classReferenceName($source, $reference);

                if ($class !== null) {
                    $observers[$class] = true;
                }
            }
        }

        $observerNames = array_keys($observers);
        sort($observerNames);

        return $observerNames;
    }
}
