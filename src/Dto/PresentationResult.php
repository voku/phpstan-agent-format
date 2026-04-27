<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Dto;

final readonly class PresentationResult
{
    /**
     * @param list<IssueCluster> $clusters
     */
    public function __construct(
        public string $tool,
        public string $version,
        public string $phpstanVersion,
        public int $totalIssues,
        public int $suppressedDuplicates,
        public array $clusters,
        public TokenStats $tokenStats,
    ) {
    }

    /**
     * @return array{
     *     tool: string,
     *     version: string,
     *     phpstanVersion: string,
     *     summary: array{
     *         totalIssues: int,
     *         clusters: int,
     *         suppressedDuplicates: int,
     *         tokenStats: array{estimatedTokens: int, tokenBudget: int, wasReduced: bool}
     *     },
     *     clusters: list<array{
     *         clusterId: string,
     *         kind: string,
     *         ruleIdentifier: ?string,
     *         rootCauseSummary: string,
     *         repairStrategySummary: string,
     *         confidence: float,
     *         affectedFiles: list<string>,
     *         representativeIssues: list<array{
     *             id: string,
     *             message: string,
     *             ruleIdentifier: ?string,
     *             location: array{file: string, line: int},
      *             symbolContext: array{className: ?string, methodName: ?string, propertyName: ?string, functionName: ?string, parameterName: ?string, expectedType: ?string, inferredType: ?string, typeOrigin: ?string},
     *             snippet: array{startLine: int, highlightLine: int, lines: list<string>},
     *             contextTrace: array{hops: list<array{location: array{file: string, line: int}, summary: string, symbol: ?string, ruleIdentifier: ?string}>},
     *             rootCauseSummary: string,
     *             repairStrategySummary: string,
     *             secondaryLocations: list<array{file: string, line: int}>
     *         }>,
     *         suppressedDuplicateCount: int
     *     }>
     * }
     */
    public function toArray(): array
    {
        $clusters = [];
        foreach ($this->clusters as $cluster) {
            $clusters[] = $cluster->toArray();
        }

        return [
            'tool' => $this->tool,
            'version' => $this->version,
            'phpstanVersion' => $this->phpstanVersion,
            'summary' => [
                'totalIssues' => $this->totalIssues,
                'clusters' => \count($this->clusters),
                'suppressedDuplicates' => $this->suppressedDuplicates,
                'tokenStats' => $this->tokenStats->toArray(),
            ],
            'clusters' => $clusters,
        ];
    }

    /**
     * @param list<IssueCluster> $clusters
     */
    public function withClusters(array $clusters, int $suppressedDuplicates, TokenStats $tokenStats): self
    {
        return new self(
            tool: $this->tool,
            version: $this->version,
            phpstanVersion: $this->phpstanVersion,
            totalIssues: $this->totalIssues,
            suppressedDuplicates: $suppressedDuplicates,
            clusters: $clusters,
            tokenStats: $tokenStats,
        );
    }
}
