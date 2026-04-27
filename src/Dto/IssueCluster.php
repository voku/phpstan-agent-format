<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Dto;

final readonly class IssueCluster
{
    /**
     * @param list<string> $affectedFiles File references formatted as path:line.
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

    /**
     * @return array{
     *     clusterId: string,
     *     kind: string,
     *     ruleIdentifier: ?string,
     *     rootCauseSummary: string,
     *     repairStrategySummary: string,
     *     confidence: float,
     *     affectedFiles: list<string>,
     *     representativeIssues: list<array{
     *         id: string,
     *         message: string,
     *         ruleIdentifier: ?string,
     *         location: array{file: string, line: int},
     *         symbolContext: array{className: ?string, methodName: ?string, propertyName: ?string, functionName: ?string, parameterName: ?string, expectedType: ?string, inferredType: ?string, typeOrigin: ?string},
     *         snippet: array{startLine: int, highlightLine: int, lines: list<string>},
     *         contextTrace: array{hops: list<array{kind:string,location: array{file: string, line: int}, summary: string, symbol: ?string, ruleIdentifier: ?string}>},
     *         rootCauseSummary: string,
     *         repairStrategySummary: string,
     *         secondaryLocations: list<array{file: string, line: int}>
     *     }>,
     *     suppressedDuplicateCount: int
     * }
     */
    public function toArray(): array
    {
        $representativeIssues = [];
        foreach ($this->representativeIssues as $issue) {
            $representativeIssues[] = $issue->toArray();
        }

        return [
            'clusterId' => $this->clusterId,
            'kind' => $this->kind,
            'ruleIdentifier' => $this->ruleIdentifier,
            'rootCauseSummary' => $this->rootCauseSummary,
            'repairStrategySummary' => $this->repairStrategySummary,
            'confidence' => $this->confidence,
            'affectedFiles' => $this->affectedFiles,
            'representativeIssues' => $representativeIssues,
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
