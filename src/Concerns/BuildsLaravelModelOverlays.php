<?php

declare(strict_types=1);

namespace Laramago\Concerns;

trait BuildsLaravelModelOverlays
{
    private function laravelModelSubstitutions(string $projectRoot, array $arguments): array
    {
        if (in_array('--no-laravel-model-overlays', $arguments, true)) {
            return [];
        }

        if (! is_file($projectRoot . '/bootstrap/app.php')) {
            return [];
        }

        $classes = $this->discoverProjectClasses($projectRoot);

        if ($classes === []) {
            return [];
        }

        $this->ensureDirectory($projectRoot . '/' . self::MODEL_OVERLAY_DIR);
        $classesPath = $projectRoot . '/' . self::CACHE_DIR . '/model-classes.json';
        file_put_contents($classesPath, json_encode($classes, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $metadata = $this->capture([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/resources/laravel-model-metadata.php',
            $projectRoot,
            $classesPath,
        ], $projectRoot);

        if ($metadata['exitCode'] !== 0) {
            $this->error('Laramago could not inspect Laravel model metadata; continuing without model overlays.');

            if ($metadata['stderr'] !== '') {
                $this->error(trim($metadata['stderr']));
            }

            return [];
        }

        try {
            $models = json_decode($metadata['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->error('Laramago received invalid Laravel model metadata: ' . $exception->getMessage());

            return [];
        }

        if (! is_array($models)) {
            return [];
        }

        $substitutions = [];
        $pathMap = [];

        foreach ($models as $model) {
            if (! is_array($model)) {
                continue;
            }

            $file = $model['file'] ?? null;
            $class = $model['shortClass'] ?? null;
            $properties = $model['properties'] ?? null;
            $accessors = $model['accessors'] ?? [];
            $relations = $model['relations'] ?? null;
            $scopes = $model['scopes'] ?? [];
            $usesSanctumApiTokens = $model['usesSanctumApiTokens'] ?? false;

            if (! is_string($file) || ! is_string($class) || ! is_array($properties) || ! is_array($accessors) || ! is_array($relations) || ! is_array($scopes) || ! is_bool($usesSanctumApiTokens)) {
                continue;
            }

            $sourcePath = $projectRoot . '/' . $file;

            if (! is_file($sourcePath)) {
                continue;
            }

            $source = file_get_contents($sourcePath);

            if (! is_string($source)) {
                continue;
            }

            $overlayRelativePath = self::MODEL_OVERLAY_DIR . '/' . sha1($file) . '.php';
            $overlay = $this->applySourceCompatibilityOverlayTransforms(
                $this->insertModelDocblock($source, $class, $properties, $accessors, $relations, $scopes, $usesSanctumApiTokens),
                $projectRoot,
                $arguments,
                $file,
            );
            file_put_contents($projectRoot . '/' . $overlayRelativePath, $overlay);

            $pathMap[] = [
                'original' => $file,
                'overlay' => $overlayRelativePath,
            ];

            $substitutions[] = '--substitute';
            $substitutions[] = $sourcePath . '=' . $projectRoot . '/' . $overlayRelativePath;
        }

        file_put_contents($projectRoot . '/' . self::MODEL_OVERLAY_MAP, json_encode($pathMap, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $substitutions;
    }

    private function discoverProjectClasses(string $projectRoot): array
    {
        $paths = $this->projectConfigValues($projectRoot)['paths'];

        if (is_dir($projectRoot . '/app/Models')) {
            array_unshift($paths, 'app/Models');
        }

        $classes = [];
        $seenFiles = [];

        foreach (array_values(array_unique($paths)) as $path) {
            foreach ($this->discoverClasses($projectRoot, $path) as $class) {
                if (isset($seenFiles[$class['file']])) {
                    continue;
                }

                $seenFiles[$class['file']] = true;
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private function discoverClasses(string $projectRoot, string $path): array
    {
        $classes = [];
        $path = $this->normalizeProjectPath($this->baseDirectoryFromGlob($path));

        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '../')) {
            return [];
        }

        $absolutePath = $projectRoot . '/' . $path;

        if (is_file($absolutePath)) {
            $files = [$absolutePath];
            $baseDirectory = dirname($absolutePath);
            $relativeBase = dirname($path);
        } elseif (is_dir($absolutePath)) {
            $files = $this->phpFiles($absolutePath);
            $baseDirectory = $absolutePath;
            $relativeBase = $path;
        } else {
            return [];
        }

        foreach ($files as $file) {
            $source = file_get_contents($file);

            if (! is_string($source)) {
                continue;
            }

            if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespaceMatches) !== 1) {
                continue;
            }

            if (preg_match('/^(?:(?:abstract|final|readonly)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $source, $classMatches) !== 1) {
                continue;
            }

            $relativePath = is_file($absolutePath)
                ? $path
                : $relativeBase . substr($file, strlen($baseDirectory));
            $classes[] = [
                'file' => str_replace(DIRECTORY_SEPARATOR, '/', $relativePath),
                'class' => trim($namespaceMatches[1]) . '\\' . $classMatches[1],
            ];
        }

        return $classes;
    }

    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function prepareExcludedSymbolStubs(string $projectRoot, array $excludes): ?string
    {
        $symbolDirectory = $projectRoot . '/' . self::EXCLUDED_SYMBOL_DIR;

        if (is_dir($symbolDirectory)) {
            $this->removeDirectory($symbolDirectory);
        }

        $written = 0;

        foreach ($excludes as $exclude) {
            $directory = $projectRoot . '/' . $this->baseDirectoryFromGlob($exclude);

            if (! is_dir($directory)) {
                continue;
            }

            foreach ($this->phpFiles($directory) as $file) {
                $stub = $this->classlikeSymbolStub($file);

                if ($stub === null) {
                    continue;
                }

                $this->ensureDirectory($symbolDirectory);
                file_put_contents($symbolDirectory . '/' . sha1($file) . '.php', $stub);
                $written++;
            }
        }

        return $written > 0 ? self::EXCLUDED_SYMBOL_DIR : null;
    }

    private function prepareLaravelSymbolStubs(string $projectRoot): ?string
    {
        $symbolDirectory = $projectRoot . '/' . self::LARAVEL_SYMBOL_DIR;

        if (is_dir($symbolDirectory)) {
            $this->removeDirectory($symbolDirectory);
        }

        $aliases = [
            'App' => 'Illuminate\\Support\\Facades\\App',
            'Arr' => 'Illuminate\\Support\\Arr',
            'Artisan' => 'Illuminate\\Support\\Facades\\Artisan',
            'Auth' => 'Illuminate\\Support\\Facades\\Auth',
            'Blade' => 'Illuminate\\Support\\Facades\\Blade',
            'Cache' => 'Illuminate\\Support\\Facades\\Cache',
            'Config' => 'Illuminate\\Support\\Facades\\Config',
            'Cookie' => 'Illuminate\\Support\\Facades\\Cookie',
            'Crypt' => 'Illuminate\\Support\\Facades\\Crypt',
            'Date' => 'Illuminate\\Support\\Facades\\Date',
            'DB' => 'Illuminate\\Support\\Facades\\DB',
            'Event' => 'Illuminate\\Support\\Facades\\Event',
            'File' => 'Illuminate\\Support\\Facades\\File',
            'Gate' => 'Illuminate\\Support\\Facades\\Gate',
            'Hash' => 'Illuminate\\Support\\Facades\\Hash',
            'Http' => 'Illuminate\\Support\\Facades\\Http',
            'Lang' => 'Illuminate\\Support\\Facades\\Lang',
            'Log' => 'Illuminate\\Support\\Facades\\Log',
            'Mail' => 'Illuminate\\Support\\Facades\\Mail',
            'Notification' => 'Illuminate\\Support\\Facades\\Notification',
            'Password' => 'Illuminate\\Support\\Facades\\Password',
            'Process' => 'Illuminate\\Support\\Facades\\Process',
            'Queue' => 'Illuminate\\Support\\Facades\\Queue',
            'RateLimiter' => 'Illuminate\\Support\\Facades\\RateLimiter',
            'Redirect' => 'Illuminate\\Support\\Facades\\Redirect',
            'Request' => 'Illuminate\\Support\\Facades\\Request',
            'Response' => 'Illuminate\\Support\\Facades\\Response',
            'Route' => 'Illuminate\\Support\\Facades\\Route',
            'Schedule' => 'Illuminate\\Support\\Facades\\Schedule',
            'Schema' => 'Illuminate\\Support\\Facades\\Schema',
            'Session' => 'Illuminate\\Support\\Facades\\Session',
            'Storage' => 'Illuminate\\Support\\Facades\\Storage',
            'Str' => 'Illuminate\\Support\\Str',
            'URL' => 'Illuminate\\Support\\Facades\\URL',
            'Validator' => 'Illuminate\\Support\\Facades\\Validator',
            'View' => 'Illuminate\\Support\\Facades\\View',
        ];

        $written = 0;

        foreach ($aliases as $alias => $target) {
            $path = $this->laravelClassPath($projectRoot, $target);

            if ($path === null || ! is_file($path)) {
                continue;
            }

            $this->ensureDirectory($symbolDirectory);
            file_put_contents($symbolDirectory . '/' . $alias . '.php', <<<PHP
<?php

class {$alias} extends \\{$target}
{
}
PHP);
            $written++;
        }

        return $written > 0 ? self::LARAVEL_SYMBOL_DIR : null;
    }

    private function laravelClassPath(string $projectRoot, string $class): ?string
    {
        if (! str_starts_with($class, 'Illuminate\\')) {
            return null;
        }

        return $projectRoot . '/vendor/laravel/framework/src/Illuminate/' . str_replace('\\', '/', substr($class, strlen('Illuminate\\'))) . '.php';
    }

    private function baseDirectoryFromGlob(string $path): string
    {
        $globPosition = strcspn($path, '*?[');
        $base = substr($path, 0, $globPosition);

        return rtrim($base === '' ? $path : $base, '/');
    }

    private function classlikeSymbolStub(string $file): ?string
    {
        $source = file_get_contents($file);

        if (! is_string($source)) {
            return null;
        }

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespaceMatches) !== 1) {
            return null;
        }

        if (preg_match('/^((?:(?:abstract|final|readonly)\s+)*(class|interface|trait|enum)\s+[A-Za-z_][A-Za-z0-9_]*(?:\s+[^{;]+)?)/m', $source, $classMatches) !== 1) {
            return null;
        }

        $declaration = trim($classMatches[1]);
        $kind = $classMatches[2];
        $namespace = trim($namespaceMatches[1]);
        $imports = $this->namespaceImportStatements($source);
        $methods = $this->publicMethodStubs($source, $kind);
        $importBlock = $imports === [] ? '' : PHP_EOL . implode(PHP_EOL, $imports) . PHP_EOL;

        return <<<PHP
<?php

namespace {$namespace};
{$importBlock}
{$declaration}
{
{$methods}
}
PHP;
    }

    private function namespaceImportStatements(string $source): array
    {
        if (preg_match_all('/^use\s+(?:function\s+|const\s+)?[^;]+;/m', $source, $matches) < 1) {
            return [];
        }

        return array_values(array_unique(array_map(static fn (string $import): string => trim($import), $matches[0])));
    }

    private function publicMethodStubs(string $source, string $kind): string
    {
        $tokens = token_get_all($source);
        $methods = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            if (! is_array($tokens[$index]) || $tokens[$index][0] !== T_PUBLIC) {
                continue;
            }

            $cursor = $this->nextMeaningfulTokenIndex($tokens, $index + 1);

            if ($cursor === null) {
                continue;
            }

            if (is_array($tokens[$cursor]) && in_array($tokens[$cursor][0], [T_STATIC, T_ABSTRACT, T_FINAL], true)) {
                $cursor = $this->nextMeaningfulTokenIndex($tokens, $cursor + 1);
            }

            if ($cursor === null || ! is_array($tokens[$cursor]) || $tokens[$cursor][0] !== T_FUNCTION) {
                continue;
            }

            $signature = '';

            for ($cursor = $index; $cursor < $count; $cursor++) {
                $token = $tokens[$cursor];
                $text = is_array($token) ? $token[1] : $token;

                if ($text === '{' || $text === ';') {
                    break;
                }

                $signature .= $text;
            }

            $signature = trim(preg_replace('/\s+/', ' ', $signature) ?? $signature);

            if ($signature === '') {
                continue;
            }

            $methods[] = '    ' . $signature . ($kind === 'interface' ? ';' : ' {}');
        }

        return $methods === [] ? '' : implode(PHP_EOL, $methods) . PHP_EOL;
    }

    private function nextMeaningfulTokenIndex(array $tokens, int $start): ?int
    {
        $count = count($tokens);

        for ($index = $start; $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $index;
        }

        return null;
    }

    private function previousMeaningfulTokenIndex(array $tokens, int $start): ?int
    {
        for ($index = $start; $index >= 0; $index--) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $index;
        }

        return null;
    }

    private function insertModelDocblock(string $source, string $shortClass, array $properties, array $accessors, array $relations, array $scopes, bool $usesSanctumApiTokens = false): string
    {
        $lines = [
            ' * @laramago-generated',
        ];

        $propertyTypes = [];

        foreach ($properties as $property) {
            if (! is_array($property) || ! isset($property['name'], $property['type'])) {
                continue;
            }

            if (! is_string($property['name']) || ! is_string($property['type'])) {
                continue;
            }

            $propertyTypes[$property['name']] = $property['type'];
        }

        foreach ($accessors as $accessor) {
            if (! is_array($accessor) || ! isset($accessor['name'], $accessor['type'])) {
                continue;
            }

            if (! is_string($accessor['name']) || ! is_string($accessor['type'])) {
                continue;
            }

            if (isset($propertyTypes[$accessor['name']])) {
                $propertyTypes[$accessor['name']] = $this->mergeModelDocblockTypes($propertyTypes[$accessor['name']], $accessor['type']);

                continue;
            }

            $lines[] = ' * @property-read ' . $accessor['type'] . ' $' . $accessor['name'];
        }

        foreach ($propertyTypes as $name => $type) {
            $lines[] = ' * @property ' . $type . ' $' . $name;
        }

        foreach ($relations as $relation) {
            if (! is_array($relation) || ! isset($relation['name'], $relation['type'])) {
                continue;
            }

            if (! is_string($relation['name']) || ! is_string($relation['type']) || isset($propertyTypes[$relation['name']])) {
                continue;
            }

            $lines[] = ' * @property-read ' . $relation['type'] . ' $' . $relation['name'];
        }

        foreach ($this->eloquentModelMagicMethods() as $method) {
            $lines[] = $method;
        }

        if ($usesSanctumApiTokens) {
            $lines[] = ' * @method \\Laravel\\Sanctum\\NewAccessToken createToken(string $name, array $abilities = ["*"], ?\\DateTimeInterface $expiresAt = null)';
        }

        foreach ($scopes as $scope) {
            if (! is_array($scope) || ! isset($scope['name'], $scope['parameters'])) {
                continue;
            }

            $lines[] = ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> ' . $scope['name'] . '(' . $scope['parameters'] . ')';
        }

        foreach ($this->caseInsensitiveMethodAliasLines($source) as $method) {
            $lines[] = $method;
        }

        $docblock = '/**' . PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL . ' */' . PHP_EOL;
        return $this->insertModelStaticDelegationMethods($this->insertClassDocblockLines($source, $shortClass, $lines, $docblock));
    }

    private function mergeModelDocblockTypes(string $left, string $right): string
    {
        if ($left === $right || $right === '') {
            return $left;
        }

        if ($left === '' || $right === 'mixed' || $left === 'mixed') {
            return 'mixed';
        }

        $types = [];

        foreach (explode('|', $left . '|' . $right) as $type) {
            $type = trim($type);

            if ($type === '') {
                continue;
            }

            $types[$type] = true;
        }

        return implode('|', array_keys($types));
    }

    private function insertModelStaticDelegationMethods(string $source): string
    {
        $defined = [];

        if (preg_match_all('/^\s*public\s+static\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches) !== false) {
            foreach ($matches[1] ?? [] as $method) {
                $defined[strtolower($method)] = true;
            }
        }

        $methods = [
            'create' => <<<'PHP'

    public static function create(array $attributes = []): static
    {
        return new static;
    }
PHP,
            'where' => <<<'PHP'

    /**
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public static function where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }
PHP,
            'orwhere' => <<<'PHP'

    /**
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public static function orWhere(mixed $column, mixed $operator = null, mixed $value = null): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }
PHP,
            'select' => <<<'PHP'

    /**
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public static function select(mixed ...$columns): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }
PHP,
            'selectraw' => <<<'PHP'

    /**
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public static function selectraw(string $expression, array $bindings = []): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }
PHP,
            'withoutglobalscopes' => <<<'PHP'

    /**
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public static function withoutglobalscopes(?array $scopes = null): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }
PHP,
            'find' => <<<'PHP'

    /**
     * @return static|\Illuminate\Database\Eloquent\Collection<int, static>|null
     */
    public static function find(mixed $id, array|string $columns = ['*']): mixed
    {
        return null;
    }
PHP,
            'lockforupdate' => <<<'PHP'

    /**
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public static function lockForUpdate(): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }
PHP,
            'findorfail' => <<<'PHP'

    /**
     * @return static|\Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function findOrFail(mixed $id, array|string $columns = ['*']): mixed
    {
        return new static;
    }
PHP,
        ];

        $insertions = [];

        foreach ($methods as $method => $declaration) {
            if (! isset($defined[$method])) {
                $insertions[] = $declaration;
            }
        }

        if ($insertions === []) {
            return $source;
        }

        return $this->insertBeforeFinalClassBrace($source, implode(PHP_EOL, $insertions) . PHP_EOL);
    }

    private function insertCaseInsensitiveMethodAliases(string $source, ?array $candidates = null, int $minimumAliases = 1): string
    {
        $lines = $this->caseInsensitiveMethodAliasLines($source, $candidates);

        if (count($lines) < $minimumAliases) {
            return $source;
        }

        if (preg_match('/^(?:(?:abstract|final|readonly)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $source, $matches) !== 1) {
            return $source;
        }

        return $this->insertClassDocblockLines($source, $matches[1], $lines);
    }

    private function insertTraitSelfCallMethods(string $source): string
    {
        if (preg_match('/^trait\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $source, $traitMatches) !== 1) {
            return $source;
        }

        if (preg_match_all('/^\s*(?:public|protected|private)\s+(?:static\s+)?function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $methodMatches) === false) {
            return $source;
        }

        $defined = [];

        foreach ($methodMatches[1] ?? [] as $method) {
            $defined[strtolower($method)] = true;
        }

        if (preg_match_all('/\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $callMatches) === 0) {
            return $source;
        }

        $lines = [];
        $seen = [];

        foreach ($callMatches[1] as $method) {
            $alias = strtolower($method);

            if (isset($defined[$alias]) || isset($seen[$alias]) || str_starts_with($alias, '__')) {
                continue;
            }

            $seen[$alias] = true;
            $lines[] = ' * @method mixed ' . $alias . '(mixed ...$arguments)';
        }

        if ($lines === []) {
            return $source;
        }

        return $this->insertTraitDocblockLines($source, $traitMatches[1], $lines);
    }

    private function insertTraitDocblockLines(string $source, string $shortTrait, array $lines): string
    {
        $pattern = '/^trait\s+' . preg_quote($shortTrait, '/') . '\b/m';

        if (preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $source;
        }

        $declarationOffset = $matches[0][1];
        $existingDocblock = $this->classDocblockBeforeOffset($source, $declarationOffset);

        if ($existingDocblock !== null) {
            $mergedDocblock = $this->mergeGeneratedDocblockLines($existingDocblock['docblock'], $lines);

            return substr($source, 0, $existingDocblock['offset']) . $mergedDocblock . substr($source, $declarationOffset);
        }

        $docblock = '/**' . PHP_EOL . ' * @laramago-generated' . PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL . ' */' . PHP_EOL;

        return substr($source, 0, $declarationOffset) . $docblock . substr($source, $declarationOffset);
    }

    private function caseInsensitiveMethodAliasCandidates(string $projectRoot): array
    {
        $candidates = [];
        $seenFiles = [];

        foreach ($this->projectConfigValues($projectRoot)['paths'] as $path) {
            foreach ($this->sourcePhpFiles($projectRoot, $path) as $file) {
                if (isset($seenFiles[$file])) {
                    continue;
                }

                $seenFiles[$file] = true;
                $source = file_get_contents($file);

                if (! is_string($source)) {
                    continue;
                }

                if (preg_match_all('/(?:->|::)\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches) === 0) {
                    continue;
                }

                foreach ($matches[1] as $method) {
                    $candidates[strtolower($method)] = true;
                }
            }
        }

        return $candidates;
    }

    private function caseInsensitiveMethodAliasLines(string $source, ?array $candidates = null): array
    {
        if (preg_match('/^(?:(?:abstract|final|readonly)\s+)*class\s+[A-Za-z_][A-Za-z0-9_]*\b/m', $source) !== 1) {
            return [];
        }

        if (preg_match_all('/^\s*public\s+(static\s+)?function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $lines = [];
        $seen = [];

        foreach ($matches as $match) {
            $method = $match[2];
            $alias = strtolower($method);

            if ($alias === $method || str_starts_with($method, '__')) {
                continue;
            }

            if ($candidates !== null && ! isset($candidates[$alias])) {
                continue;
            }

            $key = (($match[1] ?? '') !== '' ? 'static ' : '') . $alias;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $lines[] = ' * @method ' . (($match[1] ?? '') !== '' ? 'static ' : '') . 'mixed ' . $alias . '(mixed ...$arguments)';
        }

        return $lines;
    }

    private function insertClassDocblockLines(string $source, string $shortClass, array $lines, ?string $newDocblock = null): string
    {
        $pattern = '/^(?:(?:abstract|final|readonly)\s+)*class\s+' . preg_quote($shortClass, '/') . '\b/m';

        if (preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $source;
        }

        $declarationOffset = $matches[0][1];
        $docblockOffset = $this->classDocblockInsertionOffset($source, $declarationOffset);
        $existingDocblock = $this->classDocblockBeforeOffset($source, $docblockOffset);

        if ($existingDocblock !== null) {
            $mergedDocblock = $this->mergeGeneratedDocblockLines($existingDocblock['docblock'], $lines);

            return substr($source, 0, $existingDocblock['offset']) . $mergedDocblock . substr($source, $docblockOffset);
        }

        $newDocblock ??= '/**' . PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL . ' */' . PHP_EOL;

        return substr($source, 0, $docblockOffset) . $newDocblock . substr($source, $docblockOffset);
    }

    private function classDocblockInsertionOffset(string $source, int $declarationOffset): int
    {
        $prefix = substr($source, 0, $declarationOffset);

        if (preg_match('/(?:^|\R)([ \t]*(?:#\[[^\r\n]*\][ \t]*\R[ \t]*)+)$/', $prefix, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $declarationOffset;
        }

        return $matches[1][1];
    }

    private function eloquentModelMagicMethods(): array
    {
        return [
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> query()',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> newQuery()',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = "and")',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> orWhere(mixed $column, mixed $operator = null, mixed $value = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereIn(string $column, mixed $values, string $boolean = "and", bool $not = false)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereNotIn(string $column, mixed $values)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereNull(string|array $columns, string $boolean = "and", bool $not = false)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereNotNull(string|array $columns)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereDate(string $column, mixed $operator, mixed $value = null, string $boolean = "and")',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> join(mixed $table, mixed $first, ?string $operator = null, mixed $second = null, string $type = "inner", bool $where = false)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> leftJoin(mixed $table, mixed $first, ?string $operator = null, mixed $second = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> rightJoin(mixed $table, mixed $first, ?string $operator = null, mixed $second = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> crossJoin(mixed $table, mixed $first = null, ?string $operator = null, mixed $second = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> groupBy(mixed ...$groups)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> having(string $column, ?string $operator = null, mixed $value = null, string $boolean = "and")',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> orHaving(string $column, ?string $operator = null, mixed $value = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> with(array|string ...$relations)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> withCount(array|string $relations)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> select(mixed ...$columns)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> selectRaw(mixed $expression, array $bindings = [])',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> selectraw(mixed $expression, array $bindings = [])',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> orderBy(mixed $column, mixed $direction = "asc")',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> orderByRaw(string|\Illuminate\Contracts\Database\Query\Expression $sql, array $bindings = [])',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> latest(string|null $column = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> oldest(string|null $column = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> limit(int|string|null $value)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> take(int|string|null $value)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> offset(int|string|null $value)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> skip(int|string|null $value)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> lockForUpdate()',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereLike(string $column, mixed $value, bool $caseSensitive = false, string $boolean = "and", bool $not = false)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> wherelike(string $column, mixed $value, bool $caseSensitive = false, string $boolean = "and", bool $not = false)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereIntegerInRaw(string $column, mixed $values, string $boolean = "and", bool $not = false)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereIntegerNotInRaw(string $column, mixed $values, string $boolean = "and")',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereintegerinraw(string $column, mixed $values, string $boolean = "and", bool $not = false)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereintegernotinraw(string $column, mixed $values, string $boolean = "and")',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> withoutGlobalScope(mixed $scope)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> withoutGlobalScopes(array|null $scopes = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> withoutglobalscope(mixed $scope)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> withoutglobalscopes(array|null $scopes = null)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> onlyTrashed()',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> withTrashed(bool $withTrashed = true)',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> withoutTrashed()',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Builder<static> onlytrashed()',
            ' * @method static self create(array $attributes = null)',
            ' * @method static self forceCreate(array $attributes)',
            ' * @method static static|null first(array|string $columns = ["*"])',
            ' * @method static self firstOrFail(array|string $columns = ["*"])',
            ' * @method static self findorfail(mixed $id, array|string $columns = ["*"])',
            ' * @method static static|null firstWhere(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = "and")',
            ' * @method static self firstOrCreate(array $attributes = null, array $values = null)',
            ' * @method static self firstOrNew(array $attributes = null, array $values = null)',
            ' * @method static self updateOrCreate(array $attributes, array $values = null)',
            ' * @method static self sole(array|string $columns = ["*"])',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Collection all(array|string $columns = ["*"])',
            ' * @method static static|null find(mixed $id, array|string $columns = ["*"])',
            ' * @method static self findOrFail(mixed $id, array|string $columns = ["*"])',
            ' * @method static \\Illuminate\\Database\\Eloquent\\Collection get(array|string $columns = ["*"])',
            ' * @method static \\Illuminate\\Support\\Collection pluck(string $column, mixed $key = null)',
            ' * @method static \\Illuminate\\LazyCollection cursor()',
            ' * @method static bool chunk(int $count, callable $callback)',
            ' * @method static bool each(callable $callback, int $count = 1000)',
            ' * @method static mixed value(string $column)',
            ' * @method static mixed valueOrFail(string $column)',
            ' * @method static int count(string $columns = "*")',
            ' * @method static mixed sum(string $column)',
            ' * @method static mixed avg(string $column)',
            ' * @method static mixed min(string $column)',
            ' * @method static mixed max(string $column)',
            ' * @method static bool exists()',
            ' * @method static bool doesntExist()',
            ' * @method static int destroy(mixed $ids)',
            ' * @method static bool insert(array $values)',
            ' * @method static int upsert(array $values, array|string $uniqueBy, array|null $update = null)',
            ' * @method static void truncate()',
        ];
    }

    private function classDocblockBeforeOffset(string $source, int $offset): ?array
    {
        $prefix = substr($source, 0, $offset);

        if (preg_match('/(\/\*\*.*?\*\/)\s*$/s', $prefix, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return [
            'offset' => $matches[1][1],
            'docblock' => $matches[1][0],
        ];
    }

    private function mergeGeneratedDocblockLines(string $docblock, array $generatedLines): string
    {
        $merged = preg_replace('/\s*\*\/\s*$/', '', rtrim($docblock));

        if (! is_string($merged)) {
            return $docblock;
        }

        foreach ($generatedLines as $line) {
            if (str_contains($merged, trim($line))) {
                continue;
            }

            $merged .= PHP_EOL . $line;
        }

        return $merged . PHP_EOL . ' */' . PHP_EOL;
    }
}
