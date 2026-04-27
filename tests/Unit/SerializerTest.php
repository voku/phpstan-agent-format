<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Unit;

use HelgeSverre\Toon\Toon;
use Voku\PhpstanAgentFormat\Dto\AgentIssue;
use Voku\PhpstanAgentFormat\Dto\CodeSnippet;
use Voku\PhpstanAgentFormat\Dto\ContextTrace;
use Voku\PhpstanAgentFormat\Dto\FileLocation;
use Voku\PhpstanAgentFormat\Dto\FixHint;
use Voku\PhpstanAgentFormat\Dto\IssueCluster;
use Voku\PhpstanAgentFormat\Dto\PresentationResult;
use Voku\PhpstanAgentFormat\Dto\SymbolContext;
use Voku\PhpstanAgentFormat\Dto\TokenStats;
use Voku\PhpstanAgentFormat\Serializer\CompactTextAgentSerializer;
use Voku\PhpstanAgentFormat\Serializer\JsonAgentSerializer;
use Voku\PhpstanAgentFormat\Serializer\MarkdownAgentSerializer;
use Voku\PhpstanAgentFormat\Serializer\NdjsonAgentSerializer;
use Voku\PhpstanAgentFormat\Serializer\ToonAgentSerializer;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class SerializerTest
{
    public static function run(): void
    {
        $issue = new AgentIssue(
            id: 'i1',
            message: 'Call to an undefined method Foo::bar().',
            ruleIdentifier: 'method.notFound',
            location: new FileLocation('/tmp/a.php', 12),
            symbolContext: new SymbolContext('Foo', 'Foo::bar', null, null, null, null, null, null),
            snippet: new CodeSnippet(11, 12, ['line 11', 'line 12']),
            contextTrace: new ContextTrace([]),
            fixHint: new FixHint('root', 'repair'),
        );
        $cluster = new IssueCluster('c1', 'same-rule-same-symbol', null, 'root', 'repair', 1.0, ['/tmp/a.php:12'], [$issue], 2);
        $presentation = new PresentationResult('phpstan-agent-format', '2.1.x', 2, 2, [$cluster], new TokenStats(5, 100, false));

        $json1 = (new JsonAgentSerializer())->serialize($presentation);
        $json2 = (new JsonAgentSerializer())->serialize($presentation);
        TestCase::assertSame($json1, $json2, 'JSON serializer output should be deterministic.');
        TestCase::assertTrue(str_contains($json1, '/tmp/a.php:12'), 'JSON serializer should preserve affected file line references.');

        $ndjson = (new NdjsonAgentSerializer())->serialize($presentation);
        TestCase::assertTrue(str_contains($ndjson, '"cluster"'), 'NDJSON should include cluster line.');
        $ndjsonLines = explode("\n", trim($ndjson));
        TestCase::assertSame(2, count($ndjsonLines), 'NDJSON should emit one summary line and one cluster line.');

        $markdown = (new MarkdownAgentSerializer())->serialize($presentation);
        TestCase::assertTrue(str_contains($markdown, '# PHPStan Agent Repair Envelope'), 'Markdown header should exist.');
        TestCase::assertTrue(str_contains($markdown, '- Rule: `n/a`'), 'Markdown serializer should fall back to n/a for missing rule identifiers.');
        TestCase::assertTrue(str_contains($markdown, '  - `/tmp/a.php:12` Call to an undefined method Foo::bar().'), 'Markdown serializer should include file:line issue bullets.');

        $compact = (new CompactTextAgentSerializer())->serialize($presentation);
        TestCase::assertTrue(str_contains($compact, 'phpstan-agent-format'), 'Compact serializer should provide brief summary.');
        TestCase::assertTrue(str_contains($compact, 'rule=n/a'), 'Compact serializer should fall back to n/a for missing rule identifiers.');

        /** @var array{tool: string, summary: array{totalIssues: int}} $toon */
        $toon = Toon::decode((new ToonAgentSerializer())->serialize($presentation));
        TestCase::assertSame('phpstan-agent-format', $toon['tool'], 'TOON serializer should preserve the tool name.');
        TestCase::assertSame(2, $toon['summary']['totalIssues'], 'TOON serializer should preserve nested summary values.');
    }
}
