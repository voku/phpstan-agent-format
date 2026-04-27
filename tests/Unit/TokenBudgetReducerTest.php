<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Unit;

use Voku\PhpstanAgentFormat\Budget\TokenBudgetReducer;
use Voku\PhpstanAgentFormat\Config\AgentFormatConfig;
use Voku\PhpstanAgentFormat\Dto\AgentIssue;
use Voku\PhpstanAgentFormat\Dto\CodeSnippet;
use Voku\PhpstanAgentFormat\Dto\ContextTrace;
use Voku\PhpstanAgentFormat\Dto\FileLocation;
use Voku\PhpstanAgentFormat\Dto\FixHint;
use Voku\PhpstanAgentFormat\Dto\IssueCluster;
use Voku\PhpstanAgentFormat\Dto\PresentationResult;
use Voku\PhpstanAgentFormat\Dto\SchemaInfo;
use Voku\PhpstanAgentFormat\Dto\SymbolContext;
use Voku\PhpstanAgentFormat\Dto\TokenStats;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class TokenBudgetReducerTest
{
    public static function run(): void
    {
        $issue = new AgentIssue(
            id: 'id1',
            message: 'Nullable string given.',
            ruleIdentifier: 'nullable.mismatch',
            location: new FileLocation('/tmp/a.php', 20),
            symbolContext: new SymbolContext(null, null, null, null, null, null, null, null),
            snippet: new CodeSnippet(18, 20, ['a', 'b', 'c', 'd']),
            contextTrace: new ContextTrace([]),
            fixHint: new FixHint('nullable reaches strict type', 'guard for null'),
            secondaryLocations: [new FileLocation('/tmp/b.php', 3)],
        );

        $cluster = new IssueCluster('c1', 'nullable-propagation', 'nullable.mismatch', 'root', 'fix', 0.9, ['/tmp/a.php:20'], [$issue], 0);
        $presentation = new PresentationResult('tool', '2.0.0', new SchemaInfo('tool', '2.0.0'), '2.1.x', 1, 0, [$cluster], new TokenStats(0, 1, false));

        $reducer = new TokenBudgetReducer(AgentFormatConfig::fromParameters(['tokenBudget' => 1]));
        $reduced = $reducer->reduce($presentation);

        TestCase::assertTrue($reduced->tokenStats->wasReduced, 'Reducer should mark reduced presentation.');
        TestCase::assertSame('root', $reduced->clusters[0]->rootCauseSummary, 'Root cause summary must be preserved.');
        TestCase::assertSame('fix', $reduced->clusters[0]->repairStrategySummary, 'Repair strategy must be preserved.');
        TestCase::assertSame(0, count($reduced->clusters[0]->representativeIssues[0]->secondaryLocations), 'Secondary locations should be removed first.');
    }
}
