<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Unit;

use Voku\PhpstanAgentFormat\Dto\IssueCluster;
use Voku\PhpstanAgentFormat\Dto\PresentationResult;
use Voku\PhpstanAgentFormat\Dto\TokenStats;
use Voku\PhpstanAgentFormat\Serializer\CompactTextAgentSerializer;
use Voku\PhpstanAgentFormat\Serializer\JsonAgentSerializer;
use Voku\PhpstanAgentFormat\Serializer\MarkdownAgentSerializer;
use Voku\PhpstanAgentFormat\Serializer\NdjsonAgentSerializer;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class SerializerTest
{
    public static function run(): void
    {
        $cluster = new IssueCluster('c1', 'same-rule-same-symbol', 'rule', 'root', 'repair', 1.0, ['/tmp/a.php'], [], 2);
        $presentation = new PresentationResult('phpstan-agent-format', '0.1.0', '2.1.x', 2, 2, [$cluster], new TokenStats(5, 100, false));

        $json1 = (new JsonAgentSerializer())->serialize($presentation);
        $json2 = (new JsonAgentSerializer())->serialize($presentation);
        TestCase::assertSame($json1, $json2, 'JSON serializer output should be deterministic.');

        $ndjson = (new NdjsonAgentSerializer())->serialize($presentation);
        TestCase::assertTrue(str_contains($ndjson, '"cluster"'), 'NDJSON should include cluster line.');

        $markdown = (new MarkdownAgentSerializer())->serialize($presentation);
        TestCase::assertTrue(str_contains($markdown, '# PHPStan Agent Repair Envelope'), 'Markdown header should exist.');

        $compact = (new CompactTextAgentSerializer())->serialize($presentation);
        TestCase::assertTrue(str_contains($compact, 'phpstan-agent-format'), 'Compact serializer should provide brief summary.');
    }
}
