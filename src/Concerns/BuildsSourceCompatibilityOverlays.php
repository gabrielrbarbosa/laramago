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
                $translated = $this->translateLarastanPseudoTypes($translated);
                $translated = $this->translatePhpStanListTypes($translated);
                $translated = $this->translateLaravelCarbonImports($translated);
                $translated = $this->removeObjectAccessStringInterpolations($translated);
                $translated = $this->translateLaravelDateHelperCalls($translated);
                $translated = $this->rewriteCarbonInstanceStaticCalls($translated);
                $translated = $this->normalizeStringableInternalFunctionArguments($translated);
                $translated = $this->ignoreFalseReturningInternalFunctionPipelines($translated);
                $translated = $this->rewriteLaravelHttpClientWrapperReturnTypes($translated);
                $translated = $this->annotateLaravelHttpClientWrapperAssignments($translated, $projectRoot);
                $translated = $this->annotateLaravelCollectionMacroClosures($translated);
                $translated = $this->annotateLaravelCollectionStringCallbacks($translated);
                $translated = $this->loosenLaravelCollectionArrowCallbackParameterTypes($translated);
                $translated = $this->loosenLaravelCollectionClosureCallbackParameterTypes($translated);
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
                $translated = $this->annotateEloquentModelArrayAccessAssignments($translated);
                $translated = $this->annotateDynamicMemberSelectorStrings($translated);
                $translated = $this->annotateLaravelFormRequestDynamicProperties($translated, $relativePath, $projectRoot);
                $translated = $this->annotateAllowDynamicPropertiesClasses($translated);
                $translated = $this->ignoreNullCoalescePropertyAccess($translated);
                $translated = $this->ignoreLaravelInstanceBuilderMagicCalls($translated);
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

    private function translateLarastanPseudoTypes(string $source): string
    {
        return preg_replace('/\bmodel-property\s*<\s*[^>\r\n*]+\s*>/', 'string', $source) ?? $source;
    }

    private function translatePhpStanListTypes(string $source): string
    {
        $tokens = token_get_all($source);
        $translated = '';

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                $translated .= $token;

                continue;
            }

            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $translated .= $this->translatePhpStanListTypesInComment($token[1]);

                continue;
            }

            $translated .= $token[1];
        }

        return $translated;
    }

    private function translatePhpStanListTypesInComment(string $comment): string
    {
        $translated = '';
        $offset = 0;

        while (preg_match('/\b(non-empty-list|list)\s*</', $comment, $matches, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $type = $matches[1][0];
            $start = $matches[0][1];
            $open = $start + strlen($matches[0][0]) - 1;
            $close = $this->matchingGenericCloseOffset($comment, $open);

            if ($close === null) {
                break;
            }

            $inner = substr($comment, $open + 1, $close - $open - 1);
            $replacement = $type === 'non-empty-list'
                ? 'non-empty-array<int, ' . $this->translatePhpStanListTypesInComment($inner) . '>'
                : 'array<int, ' . $this->translatePhpStanListTypesInComment($inner) . '>';

            $translated .= substr($comment, $offset, $start - $offset) . $replacement;
            $offset = $close + 1;
        }

        return $translated . substr($comment, $offset);
    }

    private function matchingGenericCloseOffset(string $source, int $open): ?int
    {
        $depth = 0;
        $length = strlen($source);

        for ($index = $open; $index < $length; $index++) {
            $character = $source[$index];

            if ($character === '<') {
                $depth++;

                continue;
            }

            if ($character !== '>') {
                continue;
            }

            $depth--;

            if ($depth === 0) {
                return $index;
            }
        }

        return null;
    }

    private function removeObjectAccessStringInterpolations(string $source): string
    {
        $tokens = token_get_all($source);
        $translated = '';
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (! is_array($token) || $token[0] !== T_CURLY_OPEN) {
                $translated .= is_array($token) ? $token[1] : $token;

                continue;
            }

            $close = $this->stringInterpolationCloseTokenIndex($tokens, $index);

            if ($close === null || ! $this->stringInterpolationContainsObjectMethodCall($tokens, $index, $close)) {
                $translated .= is_array($token) ? $token[1] : $token;

                continue;
            }

            $index = $close;
        }

        return $translated;
    }

    private function stringInterpolationCloseTokenIndex(array $tokens, int $open): ?int
    {
        $depth = 1;
        $count = count($tokens);

        for ($index = $open + 1; $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token === '{') {
                $depth++;

                continue;
            }

            if ($token !== '}') {
                continue;
            }

            $depth--;

            if ($depth === 0) {
                return $index;
            }
        }

        return null;
    }

    private function stringInterpolationContainsObjectMethodCall(array $tokens, int $open, int $close): bool
    {
        for ($index = $open + 1; $index < $close; $index++) {
            $token = $tokens[$index];

            if (! is_array($token) || ! in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }

            $method = $this->nextMeaningfulTokenIndex($tokens, $index + 1);
            $openParenthesis = $method === null ? null : $this->nextMeaningfulTokenIndex($tokens, $method + 1);

            if ($method !== null && $openParenthesis !== null && is_array($tokens[$method]) && $tokens[$method][0] === T_STRING && $tokens[$openParenthesis] === '(') {
                return true;
            }
        }

        return false;
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

    private function rewriteCarbonInstanceStaticCalls(string $source): string
    {
        return str_replace(
            [
                '\\Illuminate\\Support\\Carbon::now()->parse(',
                '\\Illuminate\\Support\\Carbon::today()->parse(',
            ],
            [
                '\\Illuminate\\Support\\Carbon::parse(',
                '\\Illuminate\\Support\\Carbon::parse(',
            ],
            $source,
        );
    }

    private function normalizeStringableInternalFunctionArguments(string $source): string
    {
        if (! str_contains($source, 'strtotime(') && ! str_contains($source, 'json_decode(') && ! str_contains($source, 'uniqid(') && ! str_contains($source, 'exif_read_data(')) {
            return $source;
        }

        $translated = preg_replace(
            '/json_decode\(\s*([^,\r\n;]+->\s*getBody\s*\(\s*\))\s*([,)])/',
            'json_decode((string) $1$2',
            $source,
        ) ?? $source;

        $translated = preg_replace(
            '/uniqid\(\s*(?!\(string\)|[\'"])([^,\r\n;)]+)\s*,/',
            'uniqid((string) $1,',
            $translated,
        ) ?? $translated;
        $translated = preg_replace(
            '/uniqid\(\s*(mt_rand\(\)|rand\(\)|time\(\))\s*,/',
            'uniqid((string) $1,',
            $translated,
        ) ?? $translated;

        $translated = preg_replace(
            '/exif_read_data\(\s*(?!\(string\)|[\'"])([^,\r\n;)]+)\s*([,)])/',
            'exif_read_data((string) $1$2',
            $translated,
        ) ?? $translated;

        $translated = preg_replace_callback(
            '/strtotime\(\s*(?!\(string\))([^,\r\n;]+?)\s*(?=[,)])/',
            static function (array $matches): string {
                $argument = trim($matches[1]);

                if ($argument === '' || preg_match('/^[\'"]/', $argument) === 1 || is_numeric($argument)) {
                    return $matches[0];
                }

                return 'strtotime((string) ' . $argument;
            },
            $translated,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function ignoreFalseReturningInternalFunctionPipelines(string $source): string
    {
        if (! str_contains($source, 'strtotime(') && ! str_contains($source, 'preg_replace(') && ! str_contains($source, 'json_decode(')) {
            return $source;
        }

        $lines = preg_split('/(\R)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($lines)) {
            return $source;
        }

        $translated = '';

        for ($index = 0; $index < count($lines); $index += 2) {
            $line = (string) $lines[$index];
            $lineEnding = (string) ($lines[$index + 1] ?? '');

            if ($this->needsFalseReturningInternalFunctionPragma($line)) {
                preg_match('/^\s*/', $line, $matches);
                $translated .= ($matches[0] ?? '') . '// @mago-ignore analysis:possibly-false-argument analysis:invalid-argument analysis:nullable-return-statement analysis:invalid-return-statement analysis:falsable-return-statement' . $lineEnding;
            }

            $translated .= $line . $lineEnding;
        }

        return $translated;
    }

    private function needsFalseReturningInternalFunctionPragma(string $line): bool
    {
        if (str_contains($line, '@mago-ignore')) {
            return false;
        }

        if (preg_match('/^\s*(?:(?:return\s+)|(?:.+?=\s*))?date\s*\(\s*$/', $line) === 1) {
            return true;
        }

        if (str_contains($line, 'date(') && str_contains($line, 'strtotime(')) {
            return true;
        }

        if (str_contains($line, 'strtotime(') && str_contains($line, '(string)')) {
            return true;
        }

        if (str_contains($line, 'json_decode(') && str_contains($line, '->getBody(')) {
            return true;
        }

        return str_contains($line, 'preg_replace(') && (str_contains($line, 'iconv(') || str_contains($line, 'mb_convert_encoding('));
    }

    private function translateLaravelCarbonImports(string $source): string
    {
        return preg_replace('/^use\s+Carbon\\\\Carbon\s*;/m', 'use Illuminate\\Support\\Carbon;', $source) ?? $source;
    }

    private function rewriteLaravelHttpClientWrapperReturnTypes(string $source): string
    {
        if ((! str_contains($source, 'PromiseInterface') && ! str_contains($source, 'LazyPromise')) || ! str_contains($source, 'Http::')) {
            return $source;
        }

        $tokens = token_get_all($source);
        $offsets = [];
        $cursor = 0;

        foreach ($tokens as $index => $token) {
            $offsets[$index] = $cursor;
            $cursor += strlen(is_array($token) ? $token[1] : $token);
        }

        $replacements = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (! is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $nameIndex = $this->nextMeaningfulTokenIndex($tokens, $index + 1);

            if ($nameIndex === null || ! is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING) {
                continue;
            }

            $openBrace = null;

            for ($cursorIndex = $nameIndex + 1; $cursorIndex < $count; $cursorIndex++) {
                if ($tokens[$cursorIndex] === ';') {
                    break;
                }

                if ($tokens[$cursorIndex] === '{') {
                    $openBrace = $cursorIndex;
                    break;
                }
            }

            if ($openBrace === null) {
                continue;
            }

            $closeBrace = $this->matchingBraceTokenIndex($tokens, $openBrace);

            if ($closeBrace === null) {
                continue;
            }

            $headerStart = $offsets[$index];
            $header = substr($source, $headerStart, $offsets[$openBrace] - $headerStart);
            $body = substr($source, $offsets[$openBrace], $offsets[$closeBrace] - $offsets[$openBrace]);

            if (! $this->isSynchronousLaravelHttpClientWrapper($body)) {
                continue;
            }

            if (preg_match('/:\s*([?\\\\A-Za-z0-9_|& ]+)\s*$/', $header, $matches, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $newType = $this->withoutLaravelHttpClientPromiseTypes($matches[1][0]);

            if ($newType === null) {
                continue;
            }

            $replacements[] = [
                $headerStart + $matches[1][1],
                strlen($matches[1][0]),
                $newType,
            ];
        }

        foreach (array_reverse($replacements) as [$start, $length, $replacement]) {
            $source = substr_replace($source, $replacement, $start, $length);
        }

        return $source;
    }

    private function annotateLaravelHttpClientWrapperAssignments(string $source, string $projectRoot): string
    {
        if (! str_contains($source, 'Http::') || ! str_contains($source, '->')) {
            $wrapperMethods = $this->usedTraitLaravelHttpClientWrapperMethods($projectRoot, $source);
        } else {
            $wrapperMethods = $this->synchronousLaravelHttpClientWrapperMethods($source)
                + $this->usedTraitLaravelHttpClientWrapperMethods($projectRoot, $source);
        }

        if ($wrapperMethods === []) {
            return $source;
        }

        $methodPattern = implode('|', array_map(static fn (string $method): string => preg_quote($method, '/'), array_keys($wrapperMethods)));

        $translated = preg_replace_callback(
            '/^([ \t]*)(\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:\$this|self|static)\s*(?:->|::)\s*(?:' . $methodPattern . ')\s*\([^;\r\n]*\)\s*;)/m',
            static function (array $matches) use ($source): string {
                $variable = $matches[3];

                if (preg_match('/\$' . preg_quote($variable, '/') . '\s*->\s*(?:body|clientError|collect|cookies|created|failed|forbidden|header|headers|json|noContent|notFound|object|ok|redirect|serverError|status|successful|tooManyRequests|unprocessable|throw|throwIf|throwUnless|toException|transferStats|unauthorized)\s*\(/', $source) !== 1) {
                    return $matches[0];
                }

                $prefix = substr($source, max(0, (int) strpos($source, $matches[0]) - 160), 160);

                if (preg_match('/@var\s+\\\\?Illuminate\\\\Http\\\\Client\\\\Response\s+\$' . preg_quote($variable, '/') . '\b/', $prefix) === 1) {
                    return $matches[0];
                }

                return $matches[1] . '/** @var \Illuminate\Http\Client\Response $' . $variable . ' */' . PHP_EOL . $matches[0];
            },
            $source,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function usedTraitLaravelHttpClientWrapperMethods(string $projectRoot, string $source): array
    {
        if (preg_match_all('/^[ \t]+use\s+([^;]+);/m', $source, $matches) === 0) {
            return [];
        }

        $methods = [];

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

                $methods += $this->synchronousLaravelHttpClientWrapperMethods($traitSource);
            }
        }

        return $methods;
    }

    private function synchronousLaravelHttpClientWrapperMethods(string $source): array
    {
        $tokens = token_get_all($source);
        $offsets = [];
        $cursor = 0;

        foreach ($tokens as $index => $token) {
            $offsets[$index] = $cursor;
            $cursor += strlen(is_array($token) ? $token[1] : $token);
        }

        $methods = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (! is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $nameIndex = $this->nextMeaningfulTokenIndex($tokens, $index + 1);

            if ($nameIndex === null || ! is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING) {
                continue;
            }

            $openBrace = null;

            for ($cursorIndex = $nameIndex + 1; $cursorIndex < $count; $cursorIndex++) {
                if ($tokens[$cursorIndex] === ';') {
                    break;
                }

                if ($tokens[$cursorIndex] === '{') {
                    $openBrace = $cursorIndex;
                    break;
                }
            }

            if ($openBrace === null) {
                continue;
            }

            $closeBrace = $this->matchingBraceTokenIndex($tokens, $openBrace);

            if ($closeBrace === null) {
                continue;
            }

            $body = substr($source, $offsets[$openBrace], $offsets[$closeBrace] - $offsets[$openBrace]);

            if ($this->isSynchronousLaravelHttpClientWrapper($body)) {
                $methods[$tokens[$nameIndex][1]] = true;
            }
        }

        return $methods;
    }

    private function matchingBraceTokenIndex(array $tokens, int $openBrace): ?int
    {
        $depth = 0;
        $count = count($tokens);

        for ($index = $openBrace; $index < $count; $index++) {
            if ($tokens[$index] === '{') {
                $depth++;

                continue;
            }

            if ($tokens[$index] !== '}') {
                continue;
            }

            $depth--;

            if ($depth === 0) {
                return $index;
            }
        }

        return null;
    }

    private function isSynchronousLaravelHttpClientWrapper(string $body): bool
    {
        if (preg_match('/\bHttp::\s*(?:async|pool|batch)\s*\(/', $body) === 1 || preg_match('/->\s*async\s*\(/', $body) === 1) {
            return false;
        }

        return preg_match('/\bHttp::/', $body) === 1
            && preg_match('/(?:\bHttp::|->)\s*(?:get|post|put|patch|delete|head|send)\s*\(/', $body) === 1;
    }

    private function withoutLaravelHttpClientPromiseTypes(string $type): ?string
    {
        $kept = [];
        $removed = false;

        foreach (explode('|', $type) as $part) {
            $candidate = trim($part);
            $normalized = strtolower(ltrim(str_replace(' ', '', $candidate), '?\\'));

            if (in_array($normalized, [
                'guzzlehttp\\promise\\promiseinterface',
                'illuminate\\http\\client\\promises\\lazypromise',
                'lazypromise',
                'promiseinterface',
            ], true)) {
                $removed = true;
                continue;
            }

            $kept[] = $candidate;
        }

        if (! $removed || $kept === []) {
            return null;
        }

        return implode('|', $kept);
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

    private function annotateLaravelCollectionStringCallbacks(string $source): string
    {
        if (! str_contains($source, 'function') || ! str_contains($source, '->')) {
            return $source;
        }

        $methods = [
            'each',
            'filter',
            'map',
            'mapWithKeys',
            'reject',
            'transform',
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

                $variablePattern = preg_quote('$' . $variable, '/');

                if (str_contains($bodyPreview, '$' . $variable . '->')) {
                    return $matched;
                }

                if (preg_match('/(?:\\\\?Illuminate\\\\Support\\\\Str::(?:limit|lower|upper|headline|title|slug|snake|studly|camel|ucfirst)|\b(?:strlen|substr|trim|ltrim|rtrim|strtolower|strtoupper|ucfirst|str_starts_with|str_ends_with|str_contains|explode)\s*\()\s*' . $variablePattern . '\b/', $bodyPreview) !== 1) {
                    return $matched;
                }

                return $matches[1][0] . '$' . $variable . $matches[3][0] . PHP_EOL . '                /** @var string $' . $variable . ' */';
            },
            $source,
            -1,
            $count,
            PREG_OFFSET_CAPTURE,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function loosenLaravelCollectionArrowCallbackParameterTypes(string $source): string
    {
        if (! str_contains($source, 'fn') || ! str_contains($source, '->')) {
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
        ];
        $methodPattern = implode('|', array_map(static fn (string $method): string => preg_quote($method, '/'), $methods));

        $translated = preg_replace_callback(
            '/(->\s*(?:' . $methodPattern . ')\s*\(\s*(?:static\s+)?fn\s*\()([^)]*)(\)\s*(?::\s*[^=]+)?=>)/m',
            fn (array $matches): string => $matches[1] . $this->loosenLaravelCollectionCallbackParameters($matches[2]) . $matches[3],
            $source,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function loosenLaravelCollectionClosureCallbackParameterTypes(string $source): string
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
        ];
        $methodPattern = implode('|', array_map(static fn (string $method): string => preg_quote($method, '/'), $methods));

        $translated = preg_replace_callback(
            '/(->\s*(?:' . $methodPattern . ')\s*\(\s*(?:static\s+)?function\s*\()([^)]*)(\)\s*(?:use\s*\([^)]*\)\s*)?(?::\s*[^{]+)?\{)/m',
            fn (array $matches): string => $matches[1] . $this->loosenLaravelCollectionCallbackParameters($matches[2]) . $matches[3],
            $source,
        );

        return is_string($translated) ? $translated : $source;
    }

    private function loosenLaravelCollectionCallbackParameters(string $parameters): string
    {
        $parts = explode(',', $parameters);

        foreach ($parts as $index => $parameter) {
            $parts[$index] = preg_replace_callback(
                '/^(\s*)(\??\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*(?:\s*&\s*\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)?(?:\s*\|\s*\??\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*(?:\s*&\s*\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)?)*)\s+(&?\$[A-Za-z_][A-Za-z0-9_]*\b.*)$/',
                static function (array $matches): string {
                    $type = ltrim(strtolower(str_replace([' ', '?'], '', $matches[2])), '\\');

                    if (in_array($type, ['array', 'callable', 'iterable', 'mixed'], true)) {
                        return $matches[0];
                    }

                    return $matches[1] . $matches[3];
                },
                $parameter,
            ) ?? $parameter;
        }

        return implode(',', $parts);
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

    private function annotateDynamicMemberSelectorStrings(string $source): string
    {
        if (! str_contains($source, '->{$')) {
            return $source;
        }

        $translated = preg_replace_callback(
            '/((?:static\s+)?function\s*\(\s*)\$([A-Za-z_][A-Za-z0-9_]*)(\s*(?:,[^)]*)?\)\s*(?:use\s*\([^)]*\)\s*)?(?::\s*[^{]+)?\{)(?!\s*\/\*\*\s*@var\s+string\s+\$[A-Za-z_][A-Za-z0-9_]*)/ms',
            static function (array $matches) use ($source): string {
                $matched = $matches[0][0];
                $offset = $matches[0][1];
                $variable = $matches[2][0];
                $bodyPreview = substr($source, $offset + strlen($matched), 1200);
                $closureEnd = strpos($bodyPreview, '});');

                if ($closureEnd !== false) {
                    $bodyPreview = substr($bodyPreview, 0, $closureEnd);
                }

                if (! str_contains($bodyPreview, '{$' . $variable . '}')) {
                    return $matched;
                }

                return $matches[1][0] . '$' . $variable . $matches[3][0] . PHP_EOL . '                /** @var string $' . $variable . ' */';
            },
            $source,
            -1,
            $count,
            PREG_OFFSET_CAPTURE,
        );

        if (! is_string($translated)) {
            return $source;
        }

        $selectorVariables = [];

        if (preg_match_all('/->\s*\{\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*\}/', $translated, $matches) !== false) {
            $selectorVariables = array_fill_keys($matches[1] ?? [], true);
        }

        foreach (array_keys($selectorVariables) as $variable) {
            $translated = preg_replace_callback(
                '/^([ \t]*)(\$' . preg_quote($variable, '/') . '\s*=\s*[^;\r\n]+;)/m',
                static function (array $matches) use ($translated, $variable): string {
                    $prefix = substr($translated, max(0, (int) strpos($translated, $matches[0]) - 120), 120);

                    if (preg_match('/@var\s+string\s+\$' . preg_quote($variable, '/') . '\b/', $prefix) === 1) {
                        return $matches[0];
                    }

                    return $matches[1] . '/** @var string $' . $variable . ' */' . PHP_EOL . $matches[0];
                },
                $translated,
            ) ?? $translated;
        }

        return $translated;
    }

    private function annotateEloquentModelArrayAccessAssignments(string $source): string
    {
        if (! str_contains($source, '::') || ! str_contains($source, '->first(') || ! str_contains($source, '[')) {
            return $source;
        }

        $translated = preg_replace_callback(
            '/^([ \t]*)(\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*[\\\\A-Z][A-Za-z0-9_\\\\]*::(?:(?!;).)*?->\s*first\s*\([^;]*\)\s*;)/ms',
            static function (array $matches) use ($source): string {
                $variable = $matches[3];

                if (preg_match('/\$' . preg_quote($variable, '/') . '\s*\[[^\]]+\]/', $source) !== 1) {
                    return $matches[0];
                }

                $prefix = substr($source, max(0, (int) strpos($source, $matches[0]) - 120), 120);

                if (preg_match('/@var\s+[^$]*\$' . preg_quote($variable, '/') . '\b/', $prefix) === 1) {
                    return $matches[0];
                }

                return $matches[1] . '/** @var \Illuminate\Database\Eloquent\Model|null $' . $variable . ' */' . PHP_EOL . $matches[0];
            },
            $source,
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

    private function ignoreNullCoalescePropertyAccess(string $source): string
    {
        if (! str_contains($source, '??') || ! str_contains($source, '->')) {
            return $source;
        }

        $lines = preg_split('/(\R)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($lines)) {
            return $source;
        }

        $translated = '';

        for ($index = 0; $index < count($lines); $index += 2) {
            $line = (string) $lines[$index];
            $lineEnding = (string) ($lines[$index + 1] ?? '');

            if (str_contains($line, '??') && str_contains($line, '->') && ! str_contains($line, '@mago-ignore')) {
                preg_match('/^\s*/', $line, $matches);
                $translated .= ($matches[0] ?? '') . '// @mago-ignore analysis:invalid-property-access' . $lineEnding;
            }

            $translated .= $line . $lineEnding;
        }

        return $translated;
    }

    private function ignoreLaravelInstanceBuilderMagicCalls(string $source): string
    {
        if (! str_contains($source, '->first(') && ! str_contains($source, '->query(')) {
            return $source;
        }

        $lines = preg_split('/(\R)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($lines)) {
            return $source;
        }

        $translated = '';

        for ($index = 0; $index < count($lines); $index += 2) {
            $line = (string) $lines[$index];
            $lineEnding = (string) ($lines[$index + 1] ?? '');

            if (preg_match('/->\s*(?:first|query)\s*\(/', $line) === 1 && ! str_contains($line, '@mago-ignore')) {
                preg_match('/^\s*/', $line, $matches);
                $translated .= ($matches[0] ?? '') . '// @mago-ignore analysis:dynamic-static-method-call' . $lineEnding;
            }

            $translated .= $line . $lineEnding;
        }

        return $translated;
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
