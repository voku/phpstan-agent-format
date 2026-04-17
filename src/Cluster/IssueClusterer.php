<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Cluster;

use Voku\PhpstanAgentFormat\Config\AgentFormatConfig;
use Voku\PhpstanAgentFormat\Dto\AgentIssue;
use Voku\PhpstanAgentFormat\Dto\IssueCluster;

final readonly class IssueClusterer
{
    public function __construct(private AgentFormatConfig $config)
    {
    }

    /**
     * @param list<AgentIssue> $issues
     * @return list<IssueCluster>
     */
    public function cluster(array $issues): array
    {
        $groups = [];

        foreach ($issues as $issue) {
            $kind = $this->detectKind($issue);
            $symbol = $issue->symbolContext->methodName ?? $issue->symbolContext->propertyName ?? $issue->symbolContext->className ?? '_none';
            $lineBucket = (int) floor($issue->location->line / 10);
            $key = implode('|', [
                $kind,
                $issue->ruleIdentifier ?? '_rule',
                $symbol,
                $issue->location->file,
                (string) $lineBucket,
                $issue->symbolContext->typeOrigin ?? '_origin',
            ]);

            $groups[$key][] = $issue;
        }

        ksort($groups);
        $clusters = [];

        foreach ($groups as $key => $group) {
            usort($group, static fn (AgentIssue $a, AgentIssue $b): int => $a->id <=> $b->id);
            $kind = $this->detectKind($group[0]);
            $rep = array_slice($group, 0, $this->config->maxIssuesPerCluster);
            $suppressed = max(0, count($group) - count($rep));
            $affectedFiles = array_values(array_unique(array_map(static fn (AgentIssue $issue): string => $issue->location->file, $group)));
            sort($affectedFiles);

            $clusters[] = new IssueCluster(
                clusterId: substr(sha1($key), 0, 12),
                kind: $kind,
                ruleIdentifier: $group[0]->ruleIdentifier,
                rootCauseSummary: $group[0]->fixHint->rootCauseSummary,
                repairStrategySummary: $group[0]->fixHint->repairStrategySummary,
                confidence: min(1.0, 0.55 + (count($group) * 0.05)),
                affectedFiles: $affectedFiles,
                representativeIssues: $rep,
                suppressedDuplicateCount: $suppressed,
            );
        }

        usort(
            $clusters,
            static fn (IssueCluster $a, IssueCluster $b): int => [$b->suppressedDuplicateCount, $a->clusterId] <=> [$a->suppressedDuplicateCount, $b->clusterId]
        );

        return array_slice($clusters, 0, $this->config->maxClusters);
    }

    private function detectKind(AgentIssue $issue): string
    {
        $haystack = strtolower(($issue->ruleIdentifier ?? '') . ' ' . $issue->message);

        return match (true) {
            str_contains($haystack, 'null') => 'nullable-propagation',
            str_contains($haystack, 'missing') && str_contains($haystack, 'type') => 'missing-type-declaration',
            str_contains($haystack, 'array{') || str_contains($haystack, 'array shape') => 'array-shape-drift',
            str_contains($haystack, 'undefined property') || str_contains($haystack, 'undefined method') => 'undefined-member-from-inferred-type',
            str_contains($haystack, 'ignore') || str_contains($haystack, 'baseline') => 'stale-ignore-noise',
            default => 'same-rule-same-symbol',
        };
    }
}
