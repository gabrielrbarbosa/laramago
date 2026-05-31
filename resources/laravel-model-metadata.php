<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

$projectRoot = $argv[1] ?? null;
$classesPath = $argv[2] ?? null;

if (! is_string($projectRoot) || ! is_string($classesPath)) {
    fwrite(STDERR, "Usage: laravel-model-metadata.php <project-root> <classes-json>\n");
    exit(1);
}

chdir($projectRoot);

require $projectRoot . '/vendor/autoload.php';

$app = require $projectRoot . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$classes = json_decode((string) file_get_contents($classesPath), true, 512, JSON_THROW_ON_ERROR);
$models = [];

foreach ($classes as $entry) {
    if (! is_array($entry) || ! isset($entry['class'], $entry['file'])) {
        continue;
    }

    $class = $entry['class'];
    $file = $entry['file'];

    if (! is_string($class) || ! is_string($file) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
        continue;
    }

    try {
        $model = new $class();
        $models[] = [
            'class' => $class,
            'shortClass' => (new ReflectionClass($class))->getShortName(),
            'file' => $file,
            'properties' => modelProperties($model),
            'accessors' => modelAccessors($class),
            'relations' => modelRelations($class, $model),
            'scopes' => modelScopes($class),
        ];
    } catch (Throwable) {
        continue;
    }
}

echo json_encode($models, JSON_THROW_ON_ERROR);

/**
 * @return list<array{name: string, type: string}>
 */
function modelProperties(Model $model): array
{
    $properties = [];
    $casts = $model->getCasts();

    try {
        $schema = $model->getConnection()->getSchemaBuilder();
        $table = $model->getTable();

        if (! $schema->hasTable($table)) {
            return [];
        }

        if (method_exists($schema, 'getColumns')) {
            foreach ($schema->getColumns($table) as $column) {
                if (! is_array($column) || ! isset($column['name'])) {
                    continue;
                }

                $name = (string) $column['name'];
                $properties[] = [
                    'name' => $name,
                    'type' => propertyType(
                        $casts[$name] ?? null,
                        (string) ($column['type_name'] ?? $column['type'] ?? ''),
                        (bool) ($column['nullable'] ?? false),
                    ),
                ];
            }

            return $properties;
        }

        foreach ($schema->getColumnListing($table) as $name) {
            $properties[] = [
                'name' => $name,
                'type' => propertyType($casts[$name] ?? null, $schema->getColumnType($table, $name), true),
            ];
        }
    } catch (Throwable) {
        return [];
    }

    return $properties;
}

function propertyType(mixed $cast, string $databaseType, bool $nullable): string
{
    $cast = is_string($cast) ? strtolower($cast) : null;
    $databaseType = strtolower($databaseType);

    $type = match (true) {
        in_array($cast, ['int', 'integer'], true) => 'int',
        in_array($cast, ['real', 'float', 'double', 'decimal'], true) => 'float',
        in_array($cast, ['bool', 'boolean'], true) => 'bool',
        in_array($cast, ['array', 'json'], true) => 'array',
        in_array($cast, ['collection'], true) => EloquentCollection::class,
        str_contains((string) $cast, 'date') => Carbon::class,
        str_contains($databaseType, 'int') => 'int',
        str_contains($databaseType, 'decimal') || str_contains($databaseType, 'double') || str_contains($databaseType, 'float') => 'float',
        str_contains($databaseType, 'bool') => 'bool',
        str_contains($databaseType, 'json') => 'array',
        str_contains($databaseType, 'date') || str_contains($databaseType, 'time') => Carbon::class,
        str_contains($databaseType, 'char') || str_contains($databaseType, 'text') || str_contains($databaseType, 'enum') => 'string',
        default => 'mixed',
    };

    if (! in_array($type, ['int', 'float', 'bool', 'array', 'string', 'mixed'], true)) {
        $type = '\\' . ltrim($type, '\\');
    }

    if ($nullable && $type !== 'mixed') {
        return $type . '|null';
    }

    return $type;
}

/**
 * @return list<array{name: string, type: string}>
 */
function modelAccessors(string $class): array
{
    $accessors = [];
    $reflection = new ReflectionClass($class);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || $method->getNumberOfParameters() > 0) {
            continue;
        }

        $name = $method->getName();

        if (preg_match('/^get([A-Z][A-Za-z0-9_]*)Attribute$/', $name, $matches) === 1) {
            $accessors[] = [
                'name' => snakeCase($matches[1]),
                'type' => reflectionType($method->getReturnType()),
            ];

            continue;
        }

        $returnType = $method->getReturnType();

        if ($returnType instanceof ReflectionNamedType && is_a($returnType->getName(), Attribute::class, true)) {
            $accessors[] = [
                'name' => snakeCase($name),
                'type' => attributeAccessorType((string) $method->getDocComment()),
            ];
        }
    }

    return uniqueNamedMetadata($accessors);
}

/**
 * @return list<array{name: string, type: string}>
 */
function modelRelations(string $class, Model $model): array
{
    $relations = [];
    $reflection = new ReflectionClass($class);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || $method->getNumberOfParameters() > 0) {
            continue;
        }

        $returnType = $method->getReturnType();

        if (! $returnType instanceof ReflectionNamedType || ! is_a($returnType->getName(), Relation::class, true)) {
            continue;
        }

        try {
            $relation = $method->invoke($model);

            if (! $relation instanceof Relation) {
                continue;
            }

            $relations[] = [
                'name' => $method->getName(),
                'type' => relationType($returnType->getName(), get_class($relation->getRelated())),
            ];
        } catch (Throwable) {
            continue;
        }
    }

    return $relations;
}

/**
 * @return list<array{name: string, parameters: string}>
 */
function modelScopes(string $class): array
{
    $scopes = [];
    $reflection = new ReflectionClass($class);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class) {
            continue;
        }

        $name = $method->getName();
        $scopeName = null;

        if (preg_match('/^scope([A-Z][A-Za-z0-9_]*)$/', $name, $matches) === 1) {
            $scopeName = lcfirst($matches[1]);
        } elseif ($method->getAttributes(Scope::class) !== []) {
            $scopeName = $name;
        }

        if ($scopeName === null) {
            continue;
        }

        $parameters = [];

        foreach (array_slice($method->getParameters(), 1) as $parameter) {
            $parameters[] = parameterSignature($parameter);
        }

        $scopes[] = [
            'name' => $scopeName,
            'parameters' => implode(', ', $parameters),
        ];
    }

    return uniqueNamedMetadata($scopes);
}

function relationType(string $relationClass, string $relatedClass): string
{
    $relatedClass = '\\' . ltrim($relatedClass, '\\');

    if (is_a($relationClass, BelongsTo::class, true) || is_a($relationClass, HasOne::class, true) || is_a($relationClass, MorphOne::class, true)) {
        return $relatedClass . '|null';
    }

    if (is_a($relationClass, HasMany::class, true) || is_a($relationClass, BelongsToMany::class, true) || is_a($relationClass, MorphMany::class, true)) {
        return '\\' . EloquentCollection::class . '<int, ' . $relatedClass . '>';
    }

    return 'mixed';
}

function reflectionType(?ReflectionType $type): string
{
    if ($type instanceof ReflectionNamedType) {
        $name = $type->getName();
        $name = match ($name) {
            'self', 'static', 'parent', 'int', 'float', 'bool', 'array', 'string', 'mixed', 'object', 'callable', 'iterable', 'void', 'never', 'null', 'false', 'true' => $name,
            default => '\\' . ltrim($name, '\\'),
        };

        if ($type->allowsNull() && ! in_array($name, ['mixed', 'null'], true)) {
            return $name . '|null';
        }

        return $name;
    }

    if ($type instanceof ReflectionUnionType) {
        return implode('|', array_map(reflectionType(...), $type->getTypes()));
    }

    if ($type instanceof ReflectionIntersectionType) {
        return implode('&', array_map(reflectionType(...), $type->getTypes()));
    }

    return 'mixed';
}

function attributeAccessorType(string $docComment): string
{
    if (preg_match('/@return\s+' . preg_quote(Attribute::class, '/') . '<([^,>\s]+)/', $docComment, $matches) === 1) {
        return normalizeDocblockType($matches[1]);
    }

    if (preg_match('/@return\s+\\\\?' . preg_quote(Attribute::class, '/') . '<([^,>\s]+)/', $docComment, $matches) === 1) {
        return normalizeDocblockType($matches[1]);
    }

    return 'mixed';
}

function normalizeDocblockType(string $type): string
{
    return match ($type) {
        'int', 'float', 'bool', 'array', 'string', 'mixed', 'object', 'callable', 'iterable', 'null', 'false', 'true' => $type,
        default => str_starts_with($type, '\\') ? $type : '\\' . $type,
    };
}

function parameterSignature(ReflectionParameter $parameter): string
{
    $signature = reflectionType($parameter->getType());

    if ($parameter->isPassedByReference()) {
        $signature .= ' &';
    }

    if ($parameter->isVariadic()) {
        $signature .= ' ...';
    }

    $signature .= ' $' . $parameter->getName();

    if ($parameter->isOptional() && ! $parameter->isVariadic()) {
        $signature .= ' = null';
    }

    return $signature;
}

function snakeCase(string $value): string
{
    $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $value);

    return strtolower(is_string($snake) ? $snake : $value);
}

/**
 * @param list<array{name: string}> $metadata
 * @return list<array{name: string}>
 */
function uniqueNamedMetadata(array $metadata): array
{
    $seen = [];
    $unique = [];

    foreach ($metadata as $entry) {
        if (isset($seen[$entry['name']])) {
            continue;
        }

        $seen[$entry['name']] = true;
        $unique[] = $entry;
    }

    return $unique;
}
