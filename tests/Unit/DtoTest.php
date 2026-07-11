<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Unit;

use Voku\PhpstanAgentFormat\Dto\AgentIssue;
use Voku\PhpstanAgentFormat\Dto\CodeSnippet;
use Voku\PhpstanAgentFormat\Dto\ContextTrace;
use Voku\PhpstanAgentFormat\Dto\FileLocation;
use Voku\PhpstanAgentFormat\Dto\RelatedDefinition;
use Voku\PhpstanAgentFormat\Dto\FixHint;
use Voku\PhpstanAgentFormat\Dto\SymbolContext;
use Voku\PhpstanAgentFormat\Dto\TraceHop;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class DtoTest
{
    public static function run(): void
    {
        $location = new FileLocation('/tmp/a.php', 12);
        $issue = new AgentIssue(
            id: 'i1',
            message: 'Undefined method Foo::bar()',
            ruleIdentifier: 'method.undefined',
            location: $location,
            symbolContext: new SymbolContext('Foo', 'Foo::bar', null, null, null, null, 'Foo', 'source'),
            snippet: new CodeSnippet(10, 12, ['line1', 'line2', 'line3']),
            contextTrace: new ContextTrace([new TraceHop('primary', $location, 'summary', 'Foo::bar', 'method.undefined')]),
            fixHint: new FixHint('root', 'repair'),
        );

        $array = $issue->toArray();
        /** @var array<string, mixed> $array */
        TestCase::assertSame('i1', $array['id'], 'Issue id should be stable.');
        TestCase::assertHasKey('contextTrace', $array, 'Issue must include context trace.');
        TestCase::assertSame('root', $array['rootCauseSummary'], 'Issue root cause should be preserved.');
        /** @var array{hops:list<array{kind:string}>} $contextTrace */
        $contextTrace = $array['contextTrace'];
        TestCase::assertSame('primary', $contextTrace['hops'][0]['kind'], 'Trace hops should expose stable hop kinds.');
        /** @var array<string, mixed> $symbolContext */
        $symbolContext = $array['symbolContext'];
        TestCase::assertHasKey('expectedType', $symbolContext, 'Issue should expose structured symbol repair hints.');

        $legacyRelatedDefinition = new RelatedDefinition('/tmp/a.php', 5, 'Foo::bar', 'method', ['public function bar(): void {'], ['Attr']);
        TestCase::assertSame(null, $legacyRelatedDefinition->endLine, 'Related definition constructor should remain compatible with callers that omit endLine.');

        $rangedRelatedDefinition = new RelatedDefinition('/tmp/a.php', 5, 'Foo::bar', 'method', ['public function bar(): void {'], ['Attr'], 8);
        TestCase::assertSame(8, $rangedRelatedDefinition->toArray()['endLine'], 'Related definition serialization should include optional endLine when provided.');
    }
}
