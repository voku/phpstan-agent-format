<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Dto;

final readonly class TraceHop
{
    public function __construct(
        public FileLocation $location,
        public string $summary,
        public ?string $symbol,
        public ?string $ruleIdentifier,
    ) {
    }

    /**
     * @return array{location:array{file:string,line:int},summary:string,symbol:?string,ruleIdentifier:?string}
     */
    public function toArray(): array
    {
        return [
            'location' => $this->location->toArray(),
            'summary' => $this->summary,
            'symbol' => $this->symbol,
            'ruleIdentifier' => $this->ruleIdentifier,
        ];
    }
}
