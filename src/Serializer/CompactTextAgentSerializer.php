<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Serializer;

use Voku\PhpstanAgentFormat\Contract\AgentSerializerInterface;
use Voku\PhpstanAgentFormat\Dto\PresentationResult;

final class CompactTextAgentSerializer implements AgentSerializerInterface
{
    public function serialize(PresentationResult $presentation): string
    {
        $root = $presentation->toArray();
        $lines = [
            sprintf(
                'phpstan-agent-format totalIssues=%d clusters=%d suppressed=%d',
                $root['summary']['totalIssues'],
                $root['summary']['clusters'],
                $root['summary']['suppressedDuplicates']
            ),
        ];

        foreach ($root['clusters'] as $cluster) {
            $lines[] = sprintf(
                '[%s] rule=%s cause="%s" fix="%s" dup=%d',
                $cluster['clusterId'],
                $cluster['ruleIdentifier'] ?? 'n/a',
                $cluster['rootCauseSummary'],
                $cluster['repairStrategySummary'],
                $cluster['suppressedDuplicateCount']
            );
        }

        return implode("\n", $lines) . "\n";
    }
}
