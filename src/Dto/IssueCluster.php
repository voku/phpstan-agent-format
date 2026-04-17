<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Dto;

final readonly class IssueCluster
{
    /**
     * @param list<string> $affectedFiles
     * @param list<AgentIssue> $representativeIssues
     */
    public function __construct(
        public string $clusterId,
        public string $kind,
        public ?string $ruleIdentifier,
        public string $rootCauseSummary,
        public string $repairStrategySummary,
        public float $confidence,
        public array $affectedFiles,
        public array $representativeIssues,
        public int $suppressedDuplicateCount,
    ) {
    }

    public function toArray(): array
    {
        return [
            'clusterId' => $this->clusterId,
            'kind' => $this->kind,
            'ruleIdentifier' => $this->ruleIdentifier,
            'rootCauseSummary' => $this->rootCauseSummary,
            'repairStrategySummary' => $this->repairStrategySummary,
            'confidence' => $this->confidence,
            'affectedFiles' => $this->affectedFiles,
            'representativeIssues' => array_map(static fn (AgentIssue $issue): array => $issue->toArray(), $this->representativeIssues),
            'suppressedDuplicateCount' => $this->suppressedDuplicateCount,
        ];
    }

    /**
     * @param list<AgentIssue> $representativeIssues
     */
    public function withRepresentativeIssues(array $representativeIssues, int $suppressedDuplicateCount): self
    {
        return new self(
            clusterId: $this->clusterId,
            kind: $this->kind,
            ruleIdentifier: $this->ruleIdentifier,
            rootCauseSummary: $this->rootCauseSummary,
            repairStrategySummary: $this->repairStrategySummary,
            confidence: $this->confidence,
            affectedFiles: $this->affectedFiles,
            representativeIssues: $representativeIssues,
            suppressedDuplicateCount: $suppressedDuplicateCount,
        );
    }
}
