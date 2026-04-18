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

        $missingPropertyType = self::issue('c', 20, 'Property Foo::$bar has no type specified.', 'missingType.property', 'missingType.property');
        $missingMethodType = self::issue('d', 21, 'Method Foo::run() has parameter $value with no type specified.', 'missingType.parameter', 'missingType.parameter');

        $identifierClusters = $clusterer->cluster([$missingPropertyType, $missingMethodType]);
        TestCase::assertSame(1, count($identifierClusters), 'Issues in the same PHPStan identifier family should cluster together.');
        TestCase::assertSame('missing-type-declaration', $identifierClusters[0]->kind, 'Identifier families should drive stable cluster kinds.');

        $nonObjectMethod = self::issue('e', 22, 'Cannot call method trim() on string.', 'method.nonObject', 'method.nonObject');
        $nonObjectClusters = $clusterer->cluster([$nonObjectMethod]);
        TestCase::assertSame('undefined-member-from-inferred-type', $nonObjectClusters[0]->kind, 'Non-object member access should reuse the inferred-member cluster kind.');

        $offsetAccess = self::issue('f', 24, "Offset 'foo' does not exist on string.", 'offsetAccess.notFound', 'offsetAccess.notFound');
        $offsetClusters = $clusterer->cluster([$offsetAccess]);
        TestCase::assertSame('invalid-offset-access', $offsetClusters[0]->kind, 'Offset-access errors should get a dedicated cluster kind.');

        $genericMismatch = new AgentIssue(
            id: 'g',
            message: 'Function genericReturnFixture() should return array<int, string> but returns array<string, int>.',
            ruleIdentifier: 'return.type',
            location: new FileLocation('/tmp/a.php', 30),
            symbolContext: new SymbolContext(null, null, null, 'genericReturnFixture', null, 'array<int, string>', 'array<string, int>', 'return.type'),
            snippet: new CodeSnippet(30, 30, ['return [\'x\' => 1];']),
            contextTrace: new ContextTrace([]),
            fixHint: new FixHint('generic drift', 'align templates'),
        );
        $genericClusters = $clusterer->cluster([$genericMismatch]);
        TestCase::assertSame('generic-template-drift', $genericClusters[0]->kind, 'Generic argument drift should get a dedicated cluster kind.');
    }

    private static function issue(
        string $id,
        int $line,
        string $message = 'Call to an undefined method Foo::bar()',
        ?string $ruleIdentifier = 'method.undefined',
        ?string $typeOrigin = 'method.undefined',
    ): AgentIssue
    {
        return new AgentIssue(
            id: $id,
            message: $message,
            ruleIdentifier: $ruleIdentifier,
            location: new FileLocation('/tmp/a.php', $line),
            symbolContext: new SymbolContext('Foo', 'Foo::bar', null, null, null, null, 'Foo', $typeOrigin),
            snippet: new CodeSnippet($line, $line, ['foo();']),
            contextTrace: new ContextTrace([]),
            fixHint: new FixHint('wrong inferred type', 'add guard or fix upstream type'),
        );
    }
}
