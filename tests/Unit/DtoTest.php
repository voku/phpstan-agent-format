<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Unit;

use Voku\PhpstanAgentFormat\Dto\AgentIssue;
use Voku\PhpstanAgentFormat\Dto\CodeSnippet;
use Voku\PhpstanAgentFormat\Dto\ContextTrace;
use Voku\PhpstanAgentFormat\Dto\FileLocation;
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
            contextTrace: new ContextTrace([new TraceHop($location, 'summary', 'Foo::bar', 'method.undefined')]),
            fixHint: new FixHint('root', 'repair'),
        );

        $array = $issue->toArray();
        /** @var array<string, mixed> $array */
        TestCase::assertSame('i1', $array['id'], 'Issue id should be stable.');
        TestCase::assertHasKey('contextTrace', $array, 'Issue must include context trace.');
        TestCase::assertSame('root', $array['rootCauseSummary'], 'Issue root cause should be preserved.');
        TestCase::assertHasKey('expectedType', $array['symbolContext'], 'Issue should expose structured symbol repair hints.');
    }
}
