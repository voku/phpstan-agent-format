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
use Voku\PhpstanAgentFormat\Dto\SymbolContext;
use Voku\PhpstanAgentFormat\Dto\TokenStats;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class TokenBudgetReducerTest
{
    public static function run(): void
    {
        self::assertLargeBudgetKeepsFullContext();
        self::assertReducerRemovesSecondaryLocationsBeforeOtherContext();
        self::assertReducerShrinksSnippetsBeforeDroppingRepresentatives();
        self::assertReducerDropsExtraRepresentativeIssuesWhenNeeded();
    }

    private static function assertLargeBudgetKeepsFullContext(): void
    {
        $presentation = self::createPresentation([self::createIssue(
            id: 'id1',
            snippetLines: ['a', 'b', 'c', 'd'],
            secondaryLocationLines: [3],
        )]);

        $reduced = (new TokenBudgetReducer(AgentFormatConfig::fromParameters(['tokenBudget' => 1000])))->reduce($presentation);

        TestCase::assertTrue($reduced->tokenStats->wasReduced === false, 'Large token budgets should keep the original payload intact.');
        TestCase::assertSame(1, count($reduced->clusters[0]->representativeIssues[0]->secondaryLocations), 'Large token budgets should keep secondary locations.');
        TestCase::assertSame(['a', 'b', 'c', 'd'], $reduced->clusters[0]->representativeIssues[0]->snippet->lines, 'Large token budgets should keep full snippets.');
    }

    private static function assertReducerRemovesSecondaryLocationsBeforeOtherContext(): void
    {
        $presentation = self::createPresentation([self::createIssue(
            id: 'id1',
            snippetLines: ['a', 'b', 'c', 'd'],
            secondaryLocationLines: [3],
        )]);

        $reduced = (new TokenBudgetReducer(AgentFormatConfig::fromParameters(['tokenBudget' => 1])))->reduce($presentation);

        TestCase::assertTrue($reduced->tokenStats->wasReduced, 'Reducer should mark reduced presentation.');
        TestCase::assertSame('root', $reduced->clusters[0]->rootCauseSummary, 'Root cause summary must be preserved.');
        TestCase::assertSame('fix', $reduced->clusters[0]->repairStrategySummary, 'Repair strategy must be preserved.');
        TestCase::assertSame(0, count($reduced->clusters[0]->representativeIssues[0]->secondaryLocations), 'Secondary locations should be removed first.');
    }

    private static function assertReducerShrinksSnippetsBeforeDroppingRepresentatives(): void
    {
        $presentation = self::createPresentation([self::createIssue(
            id: 'id2',
            snippetLines: ['first', 'second', 'third', 'fourth'],
            secondaryLocationLines: [],
        )]);

        $reduced = (new TokenBudgetReducer(AgentFormatConfig::fromParameters(['tokenBudget' => 1])))->reduce($presentation);

        TestCase::assertSame(['third'], $reduced->clusters[0]->representativeIssues[0]->snippet->lines, 'Tiny token budgets should collapse snippets to the highlighted line.');
        TestCase::assertSame(20, $reduced->clusters[0]->representativeIssues[0]->snippet->startLine, 'Collapsed snippets should start at the issue location line.');
        TestCase::assertSame(20, $reduced->clusters[0]->representativeIssues[0]->snippet->highlightLine, 'Collapsed snippets should highlight the issue location line.');
    }

    private static function assertReducerDropsExtraRepresentativeIssuesWhenNeeded(): void
    {
        $presentation = self::createPresentation([
            self::createIssue(id: 'id3', snippetLines: ['a'], secondaryLocationLines: []),
            self::createIssue(id: 'id4', snippetLines: ['b'], secondaryLocationLines: []),
        ]);

        $reduced = (new TokenBudgetReducer(AgentFormatConfig::fromParameters(['tokenBudget' => 1])))->reduce($presentation);

        TestCase::assertSame(1, count($reduced->clusters[0]->representativeIssues), 'Reducer should drop extra representative issues only as a last resort.');
        TestCase::assertSame('id3', $reduced->clusters[0]->representativeIssues[0]->id, 'Reducer should keep the first representative issue when trimming.');
        TestCase::assertSame(1, $reduced->clusters[0]->suppressedDuplicateCount, 'Reducer should convert trimmed representatives into suppressed duplicates.');
        TestCase::assertSame(1, $reduced->suppressedDuplicates, 'Presentation totals should stay aligned with trimmed representative issues.');
    }

    /**
     * @param list<AgentIssue> $issues
     */
    private static function createPresentation(array $issues): PresentationResult
    {
        $cluster = new IssueCluster('c1', 'nullable-propagation', 'nullable.mismatch', 'root', 'fix', 0.9, ['/tmp/a.php:20'], $issues, 0);

        return new PresentationResult('tool', '2.1.x', count($issues), 0, [$cluster], new TokenStats(0, 1, false));
    }

    /**
     * @param list<string> $snippetLines
     * @param list<int> $secondaryLocationLines
     */
    private static function createIssue(string $id, array $snippetLines, array $secondaryLocationLines): AgentIssue
    {
        $secondaryLocations = [];
        foreach ($secondaryLocationLines as $line) {
            $secondaryLocations[] = new FileLocation('/tmp/b.php', $line);
        }

        return new AgentIssue(
            id: $id,
            message: 'Nullable string given.',
            ruleIdentifier: 'nullable.mismatch',
            location: new FileLocation('/tmp/a.php', 20),
            symbolContext: new SymbolContext(null, null, null, null, null, null, null, null),
            snippet: new CodeSnippet(18, 20, $snippetLines),
            contextTrace: new ContextTrace([]),
            fixHint: new FixHint('nullable reaches strict type', 'guard for null'),
            secondaryLocations: $secondaryLocations,
        );
    }
}
