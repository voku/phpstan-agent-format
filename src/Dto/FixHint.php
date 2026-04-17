<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Dto;

final readonly class FixHint
{
    public function __construct(
        public string $rootCauseSummary,
        public string $repairStrategySummary,
    ) {
    }

    /**
     * @return array{rootCauseSummary:string,repairStrategySummary:string}
     */
    public function toArray(): array
    {
        return [
            'rootCauseSummary' => $this->rootCauseSummary,
            'repairStrategySummary' => $this->repairStrategySummary,
        ];
    }
}
