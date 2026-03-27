<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Resolvers;

use Closure;
use TarranJones\LaravelPaginationAggregates\AggregateInstruction;

readonly class InstructionGroup
{
    /**
     * @param  AggregateInstruction[]  $instructions
     */
    public function __construct(
        /** @var 'base'|'relation' */
        public string $type,
        public ?string $baseName,
        public ?Closure $constraints,
        public ?string $table,
        public ?string $fk,
        public ?string $localKey,
        public array $instructions,
        public bool $isHasOneOrMany,
        public mixed $relation,
    ) {}
}
