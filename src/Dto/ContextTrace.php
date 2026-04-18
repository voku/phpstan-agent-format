<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Dto;

final readonly class ContextTrace
{
    /**
     * @param list<TraceHop> $hops
     */
    public function __construct(public array $hops)
    {
    }

    /**
     * @return array{hops:list<array{kind:string,location:array{file:string,line:int},summary:string,symbol:?string,ruleIdentifier:?string}>}
     */
    public function toArray(): array
    {
        return [
            'hops' => array_map(static fn (TraceHop $hop): array => $hop->toArray(), $this->hops),
        ];
    }
}
