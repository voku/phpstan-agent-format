<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Dto;

final readonly class TokenStats
{
    public function __construct(
        public int $estimatedTokens,
        public int $tokenBudget,
        public bool $wasReduced,
    ) {
    }

    /**
     * @return array{estimatedTokens:int,tokenBudget:int,wasReduced:bool}
     */
    public function toArray(): array
    {
        return [
            'estimatedTokens' => $this->estimatedTokens,
            'tokenBudget' => $this->tokenBudget,
            'wasReduced' => $this->wasReduced,
        ];
    }
}
