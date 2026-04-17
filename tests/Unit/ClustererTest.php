<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Unit;

use Voku\PhpstanAgentFormat\Cluster\IssueClusterer;
use Voku\PhpstanAgentFormat\Config\AgentFormatConfig;
use Voku\PhpstanAgentFormat\Dto\AgentIssue;
use Voku\PhpstanAgentFormat\Dto\CodeSnippet;
use Voku\PhpstanAgentFormat\Dto\ContextTrace;
use Voku\PhpstanAgentFormat\Dto\FileLocation;
use Voku\PhpstanAgentFormat\Dto\FixHint;
use Voku\PhpstanAgentFormat\Dto\SymbolContext;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class ClustererTest
{
    public static function run(): void
    {
        $config = AgentFormatConfig::fromParameters(['maxClusters' => 30, 'maxIssuesPerCluster' => 1]);
        $clusterer = new IssueClusterer($config);

        $issueA = self::issue('a', 12);
        $issueB = self::issue('b', 13);

        $clusters = $clusterer->cluster([$issueA, $issueB]);
        TestCase::assertSame(1, count($clusters), 'Related issues should cluster together.');
        TestCase::assertSame(1, count($clusters[0]->representativeIssues), 'Representative issue limit should apply.');
        TestCase::assertSame(1, $clusters[0]->suppressedDuplicateCount, 'Duplicates should be counted as suppressed.');
    }

    private static function issue(string $id, int $line): AgentIssue
    {
        return new AgentIssue(
            id: $id,
            message: 'Call to an undefined method Foo::bar()',
            ruleIdentifier: 'method.undefined',
            location: new FileLocation('/tmp/a.php', $line),
            symbolContext: new SymbolContext('Foo', 'Foo::bar', null, null, 'Foo', 'method.undefined'),
            snippet: new CodeSnippet($line, $line, ['foo();']),
            contextTrace: new ContextTrace([]),
            fixHint: new FixHint('wrong inferred type', 'add guard or fix upstream type'),
        );
    }
}
