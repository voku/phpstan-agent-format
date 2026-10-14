<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Unit;

use InvalidArgumentException;
use Voku\PhpstanAgentFormat\Dto\AgentIssue;
use Voku\PhpstanAgentFormat\Dto\CodeSnippet;
use Voku\PhpstanAgentFormat\Dto\ContextTrace;
use Voku\PhpstanAgentFormat\Dto\FileLocation;
use Voku\PhpstanAgentFormat\Dto\RelatedDefinition;
use Voku\PhpstanAgentFormat\Dto\FixHint;
use Voku\PhpstanAgentFormat\Dto\Notice;
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
        TestCase::assertSame('i1', $array['id'], 'Issue id should be stable.');
        TestCase::assertHasKey('contextTrace', $array, 'Issue must include context trace.');
        TestCase::assertSame('root', $array['rootCauseSummary'], 'Issue root cause should be preserved.');
        TestCase::assertSame('primary', $array['contextTrace']['hops'][0]['kind'], 'Trace hops should expose stable hop kinds.');
        $symbolContext = $array['symbolContext'];
        TestCase::assertHasKey('expectedType', $symbolContext, 'Issue should expose structured symbol repair hints.');

        $legacyRelatedDefinition = new RelatedDefinition('/tmp/a.php', 5, 'Foo::bar', 'method', ['public function bar(): void {'], ['Attr']);
        TestCase::assertSame(null, $legacyRelatedDefinition->endLine, 'Related definition constructor should remain compatible with callers that omit endLine.');

        $rangedRelatedDefinition = new RelatedDefinition('/tmp/a.php', 5, 'Foo::bar', 'method', ['public function bar(): void {'], ['Attr'], 8);
        TestCase::assertSame(8, $rangedRelatedDefinition->toArray()['endLine'], 'Related definition serialization should include optional endLine when provided.');

        $redact = static fn (string $value): string => str_replace(['secret-a', 'secret-b'], '[redacted]', $value);
        $safeNotice = new Notice(
            id: 'sha256:abc123',
            title: 'secret-a title',
            message: 'secret-b message',
            subject: 'secret-a subject',
            refs: ['docs/secret-b.md'],
        );
        $mappedNotice = $safeNotice->mapStrings($redact);
        TestCase::assertSame('sha256:abc123', $mappedNotice->id, 'Opaque notice IDs must remain unchanged by redaction.');
        TestCase::assertSame('[redacted] title', $mappedNotice->title, 'Notice titles should still be redacted.');
        TestCase::assertSame('[redacted] message', $mappedNotice->message, 'Notice messages should still be redacted.');
        TestCase::assertSame('[redacted] subject', $mappedNotice->subject, 'Notice subjects should still be redacted.');
        TestCase::assertSame(['docs/[redacted].md'], $mappedNotice->refs, 'Notice refs should still be redacted.');
        TestCase::assertSame(
            $mappedNotice->toArray(),
            $safeNotice->mapStrings($redact)->toArray(),
            'Notice redaction must be deterministic across repeated runs.',
        );

        foreach (['secret-a', 'secret-b'] as $sensitiveId) {
            $rejected = false;
            try {
                (new Notice($sensitiveId, 'title', 'message'))->mapStrings($redact);
            } catch (InvalidArgumentException $exception) {
                $rejected = true;
                TestCase::assertSame(
                    'Notice ID matches configured redaction patterns; use a non-sensitive stable identifier.',
                    $exception->getMessage(),
                    'Sensitive notice IDs should fail with the stable contract message.',
                );
            }

            TestCase::assertTrue(
                $rejected,
                'Sensitive notice IDs that would redact to the same value must be rejected instead of colliding.',
            );
        }
    }
}
