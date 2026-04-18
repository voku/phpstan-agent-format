<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Dto;

final readonly class TraceHop
{
    public function __construct(
        public string $kind,
        public FileLocation $location,
        public string $summary,
        public ?string $symbol,
        public ?string $ruleIdentifier,
    ) {
    }

    /**
     * @return array{kind:string,location:array{file:string,line:int},summary:string,symbol:?string,ruleIdentifier:?string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'location' => $this->location->toArray(),
            'summary' => $this->summary,
            'symbol' => $this->symbol,
            'ruleIdentifier' => $this->ruleIdentifier,
        ];
    }
}
