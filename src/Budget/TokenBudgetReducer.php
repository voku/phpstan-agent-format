<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Budget;

use Voku\PhpstanAgentFormat\Config\AgentFormatConfig;
use Voku\PhpstanAgentFormat\Dto\CodeSnippet;
use Voku\PhpstanAgentFormat\Dto\IssueCluster;
use Voku\PhpstanAgentFormat\Dto\Notice;
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
        $notices = $presentation->notices;

        $estimated = $this->estimate($clusters, $notices);
        if ($estimated <= $this->config->tokenBudget) {
            return $presentation->withClusters($clusters, $presentation->suppressedDuplicates, new TokenStats($estimated, $this->config->tokenBudget, false));
        }

        // 1. remove optional extracted context before shrinking core repair summaries.
        $clusters = array_map(static fn (IssueCluster $cluster): IssueCluster => $cluster->withRepresentativeIssues(
            array_map(static fn ($issue) => $issue->withoutExtractedContext(), $cluster->representativeIssues),
            $cluster->suppressedDuplicateCount,
        ), $clusters);

        $estimated = $this->estimate($clusters, $notices);

        // 2. remove verbose secondary locations
        if ($estimated > $this->config->tokenBudget) {
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
        }

        $estimated = $this->estimate($clusters, $notices);

        // 3. shrink snippets
        if ($estimated > $this->config->tokenBudget) {
            $clusters = array_map(function (IssueCluster $cluster): IssueCluster {
                $issues = [];
                foreach ($cluster->representativeIssues as $issue) {
                    $line = $issue->snippet->lines[$issue->snippet->highlightLine - $issue->snippet->startLine] ?? ($issue->snippet->lines[0] ?? '');
                    $issues[] = $issue->withSnippet(new CodeSnippet($issue->location->line, $issue->location->line, [$line]));
                }

                return $cluster->withRepresentativeIssues($issues, $cluster->suppressedDuplicateCount);
            }, $clusters);
            $estimated = $this->estimate($clusters, $notices);
        }

        // 4. reduce representative issue count as needed.
        if ($estimated > $this->config->tokenBudget) {
            $clusters = array_map(function (IssueCluster $cluster): IssueCluster {
                if (count($cluster->representativeIssues) <= 1) {
                    return $cluster;
                }

                $trimmed = [$cluster->representativeIssues[0]];
                $suppressed = $cluster->suppressedDuplicateCount + count($cluster->representativeIssues) - 1;

                return $cluster->withRepresentativeIssues($trimmed, $suppressed);
            }, $clusters);
            $estimated = $this->estimate($clusters, $notices);
        }

        // 5. notices are advisory: drop their message text last, keeping id/title/subject/refs.
        if ($estimated > $this->config->tokenBudget && $notices !== []) {
            $notices = array_map(static fn (Notice $notice): Notice => $notice->withoutMessage(), $notices);
            $estimated = $this->estimate($clusters, $notices);
        }

        // 6. still over budget: drop trailing notices and report how many were suppressed.
        $suppressedNotices = 0;
        while ($estimated > $this->config->tokenBudget && $notices !== []) {
            array_pop($notices);
            $suppressedNotices++;
            $estimated = $this->estimate($clusters, $notices);
        }

        return $presentation
            ->withClusters($clusters, $this->suppressedDuplicates($clusters), new TokenStats($estimated, $this->config->tokenBudget, true))
            ->withNotices($notices, new TokenStats($estimated, $this->config->tokenBudget, true), $suppressedNotices);
    }

    /**
     * @param list<IssueCluster> $clusters
     * @param list<Notice> $notices
     */
    private function estimate(array $clusters, array $notices): int
    {
        $payload = [];
        foreach ($clusters as $cluster) {
            $payload[] = $cluster->toArray();
        }

        foreach ($notices as $notice) {
            $payload[] = $notice->toArray();
        }

        return (int) max(1, ceil(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) / 4));
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
