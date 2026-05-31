<?php

declare(strict_types=1);

function propertyType(?string $cast, ?string $databaseType, bool $nullable): string
{
    return '';
}

function relationType(string $relationClass, ?string $relatedClass): string
{
    return '';
}

/**
 * @return list<array{name: string, parameters: string}>
 */
function modelScopes(string $class): array
{
    return [];
}
