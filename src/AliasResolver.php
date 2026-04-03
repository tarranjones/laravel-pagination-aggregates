<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

class AliasResolver
{
    public function __construct(private readonly Grammar $grammar) {}

    public static function explicitAlias(string $name): ?string
    {
        $segments = explode(' ', $name);

        return count($segments) === 3 && strtolower($segments[1]) === 'as' ? $segments[2] : null;
    }

    /**
     * Returns the alias if $name is the bare `'as {alias}'` form (base aggregate key), or null otherwise.
     */
    public static function baseAlias(string $name): ?string
    {
        $parts = explode(' ', trim($name), 3);

        return count($parts) === 2 && strtolower($parts[0]) === 'as' ? $parts[1] : null;
    }

    public static function stripAlias(string $name): string
    {
        return (string) preg_replace('/\s+as\s+\S+$/i', '', $name);
    }

    public function forRelation(string $relation, Expression|string $column, string $function): string
    {
        $explicit = self::explicitAlias($relation);

        if ($explicit !== null) {
            return $explicit;
        }

        $columnValue = $column instanceof Expression
            ? $column->getValue($this->grammar)
            : $column;

        $columnValue = strtolower((string) $columnValue);

        return $this->sanitizeToSnake(sprintf('%s %s %s', $relation, $function, $columnValue));
    }

    public function forColumn(Expression|string $column, string $function): string
    {
        if (is_string($column)) {
            $explicit = self::explicitAlias($column);
            if ($explicit !== null) {
                return $explicit;
            }
        }

        $columnValue = $column instanceof Expression
            ? $column->getValue($this->grammar)
            : $column;

        $columnValue = strtolower((string) $columnValue);

        return $this->sanitizeToSnake(sprintf('%s %s', $function, $columnValue));
    }

    private function sanitizeToSnake(string $raw): string
    {
        $sanitized = (string) preg_replace(
            ['/[^[:alnum:][:space:]_]+/u', '/\s+/'],
            ['_', ' '],
            $raw
        );

        return str($sanitized)->trim('_')->snake()->value();
    }
}
