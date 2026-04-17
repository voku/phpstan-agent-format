<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Budget;

use Voku\PhpstanAgentFormat\Config\AgentFormatConfig;
use Voku\PhpstanAgentFormat\Dto\CodeSnippet;
use Voku\PhpstanAgentFormat\Dto\IssueCluster;
use Voku\PhpstanAgentFormat\Dto\PresentationResult;
use Voku\PhpstanAgentFormat\Dto\TokenStats;

final readonly class TokenBudgetReducer
{
    public function __construct(private AgentFormatConfig $config)
    {
    }

    public function reduce(PresentationResult $presentation): PresentationResult
    {
        $clusters = $presentation->clusters;

        $estimated = $this->estimate($clusters);
        if ($estimated <= $this->config->tokenBudget) {
            return $presentation->withClusters($clusters, $presentation->suppressedDuplicates, new TokenStats($estimated, $this->config->tokenBudget, false));
        }

        // 1. remove verbose prose
        $clusters = array_map(static fn (IssueCluster $cluster): IssueCluster => new IssueCluster(
            clusterId: $cluster->clusterId,
            kind: $cluster->kind,
            ruleIdentifier: $cluster->ruleIdentifier,
            rootCauseSummary: $cluster->rootCauseSummary,
            repairStrategySummary: $cluster->repairStrategySummary,
            confidence: $cluster->confidence,
            affectedFiles: $cluster->affectedFiles,
            representativeIssues: array_map(static fn ($issue) => $issue->withSecondaryLocations([]), $cluster->representativeIssues),
            suppressedDuplicateCount: $cluster->suppressedDuplicateCount,
        ), $clusters);

        $estimated = $this->estimate($clusters);

        // 2. shrink snippets
        if ($estimated > $this->config->tokenBudget) {
            $clusters = array_map(function (IssueCluster $cluster): IssueCluster {
                $issues = [];
                foreach ($cluster->representativeIssues as $issue) {
                    $line = $issue->snippet->lines[$issue->snippet->highlightLine - $issue->snippet->startLine] ?? ($issue->snippet->lines[0] ?? '');
                    $issues[] = $issue->withSnippet(new CodeSnippet($issue->location->line, $issue->location->line, [$line]));
                }

                return $cluster->withRepresentativeIssues($issues, $cluster->suppressedDuplicateCount);
            }, $clusters);
            $estimated = $this->estimate($clusters);
        }

        // 3/4. reduce representative issue count as needed.
        if ($estimated > $this->config->tokenBudget) {
            $clusters = array_map(function (IssueCluster $cluster): IssueCluster {
                if (count($cluster->representativeIssues) <= 1) {
                    return $cluster;
                }

                $trimmed = [$cluster->representativeIssues[0]];
                $suppressed = $cluster->suppressedDuplicateCount + count($cluster->representativeIssues) - 1;

                return $cluster->withRepresentativeIssues($trimmed, $suppressed);
            }, $clusters);
            $estimated = $this->estimate($clusters);
        }

        return $presentation->withClusters($clusters, $this->suppressedDuplicates($clusters), new TokenStats($estimated, $this->config->tokenBudget, true));
    }

    /**
     * @param list<IssueCluster> $clusters
     */
    private function estimate(array $clusters): int
    {
        $payload = [];
        foreach ($clusters as $cluster) {
            $payload[] = $cluster->toArray();
        }

        return (int) max(1, ceil(strlen((string) json_encode($payload, JSON_THROW_ON_ERROR)) / 4));
    }

    /**
     * @param list<IssueCluster> $clusters
     */
    private function suppressedDuplicates(array $clusters): int
    {
        $total = 0;
        foreach ($clusters as $cluster) {
            $total += $cluster->suppressedDuplicateCount;
        }

        return $total;
    }
}
