<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Serializer;

use Voku\PhpstanAgentFormat\Contract\AgentSerializerInterface;
use Voku\PhpstanAgentFormat\Dto\PresentationResult;

final class MarkdownAgentSerializer implements AgentSerializerInterface
{
    public function serialize(PresentationResult $presentation): string
    {
        $root = $presentation->toArray();

        $lines = [
            '# PHPStan Agent Repair Envelope',
            '',
            sprintf('- Total issues: %d', $root['summary']['totalIssues']),
            sprintf('- Clusters: %d', $root['summary']['clusters']),
            sprintf('- Suppressed duplicates: %d', $root['summary']['suppressedDuplicates']),
            '',
        ];

        foreach ($root['clusters'] as $cluster) {
            $lines[] = sprintf('## [%s] %s', $cluster['kind'], $cluster['clusterId']);
            $lines[] = sprintf('- Rule: `%s`', $cluster['ruleIdentifier'] ?? 'n/a');
            $lines[] = sprintf('- Root cause: %s', $cluster['rootCauseSummary']);
            $lines[] = sprintf('- Repair strategy: %s', $cluster['repairStrategySummary']);
            $lines[] = sprintf('- Suppressed duplicates: %d', $cluster['suppressedDuplicateCount']);

            foreach ($cluster['representativeIssues'] as $issue) {
                $lines[] = sprintf('  - `%s:%d` %s', $issue['location']['file'], $issue['location']['line'], $issue['message']);
            }

            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }
}
