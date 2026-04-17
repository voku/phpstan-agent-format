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
     * @return array{tool:string,version:string,phpstanVersion:string,summary:array{totalIssues:int,clusters:int,suppressedDuplicates:int,tokenStats:array{estimatedTokens:int,tokenBudget:int,wasReduced:bool}},clusters:list<array<string,mixed>>}
     */
    public function toArray(): array
    {
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
            'clusters' => array_map(static fn (IssueCluster $cluster): array => $cluster->toArray(), $this->clusters),
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
