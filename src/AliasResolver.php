<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

class AliasResolver
{
    public function __construct(private readonly Grammar $grammar) {}

    /**
     * @return array{column: string, alias: string}
     */
    public function forDirect(Expression|string $column, string $function, string $prefix = 'total'): array
    {
        if ($column instanceof Expression) {
            $col = (string) $column->getValue($this->grammar);
            $snaked = strtolower((string) preg_replace('/[^[:alnum:]_]/u', '_', $col));

            return ['column' => $col, 'alias' => $prefix.'_'.$function.'_'.$snaked];
        }

        $segments = preg_split('/\s+as\s+/i', $column, 2);

        if (count($segments) === 2) {
            return ['column' => trim($segments[0]), 'alias' => trim($segments[1])];
        }

        $col = trim($column);
        $snaked = strtolower((string) preg_replace('/[^[:alnum:]_]/u', '_', $col));

        if ($function === 'count' && $col === '*') {
            return ['column' => $col, 'alias' => $prefix.'_count'];
        }

        return ['column' => $col, 'alias' => $prefix.'_'.$function.'_'.$snaked];
    }

    public function forRelation(string $relation, Expression|string $column, ?string $function): string
    {
        $segments = explode(' ', $relation);

        if (count($segments) === 3 && strtolower($segments[1]) === 'as') {
            return $segments[2];
        }

        $columnValue = $column instanceof Expression
            ? $column->getValue($this->grammar)
            : $column;

        $columnValue = strtolower((string) $columnValue);

        $raw = sprintf('%s %s %s', $relation, $function, $columnValue);
        $sanitized = trim((string) preg_replace(['/[^[:alnum:][:space:]_]+/u', '/\s+/'], ['_', ' '], $raw), '_');

        return str($sanitized)->snake()->value();
    }
}
