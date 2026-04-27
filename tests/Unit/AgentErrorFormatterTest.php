<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Unit;

use HelgeSverre\Toon\Toon;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\Output;
use Voku\PhpstanAgentFormat\Formatter\AgentErrorFormatter;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class AgentErrorFormatterTest
{
    public static function run(): void
    {
        self::assertCleanRunsReturnZeroExitCode();
        self::assertJsonExportArrayPayloadSupportsAllOutputModes();
    }

    private static function assertCleanRunsReturnZeroExitCode(): void
    {
        $formatter = new AgentErrorFormatter([
            'agentFormat' => [
                'outputMode' => 'json',
            ],
        ]);
        $output = new Output();

        $exitCode = $formatter->formatErrors(new AnalysisResult([], []), $output);

        /** @var array{
         *   tool: string,
         *   phpstanVersion: string,
         *   summary: array{totalIssues: int, clusters: int, suppressedDuplicates: int},
         *   clusters: list<array<mixed>>
         * } $decoded
         */
        $decoded = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        TestCase::assertSame(0, $exitCode, 'Clean analysis results should return a zero exit code.');
        TestCase::assertSame('phpstan-agent-format', $decoded['tool'], 'Clean runs should still emit the standard tool name.');
        TestCase::assertSame(0, $decoded['summary']['totalIssues'], 'Clean runs should report zero issues.');
        TestCase::assertSame(0, $decoded['summary']['clusters'], 'Clean runs should report zero clusters.');
        TestCase::assertSame(0, count($decoded['clusters']), 'Clean runs should serialize an empty cluster list.');
    }

    private static function assertJsonExportArrayPayloadSupportsAllOutputModes(): void
    {
        $payload = [
            'files' => [
                '/tmp/a.php' => [
                    'messages' => [
                        [
                            'message' => 'Call to an undefined method Foo::bar().',
                            'line' => 12,
                            'identifier' => 'method.notFound',
                        ],
                    ],
                ],
            ],
            'errors' => [],
        ];

        $jsonFormatter = new AgentErrorFormatter(['agentFormat' => ['outputMode' => 'json']]);
        /** @var array{
         *   summary: array{totalIssues: int},
         *   clusters: list<array{
         *     ruleIdentifier: ?string,
         *     affectedFiles: list<string>,
         *     representativeIssues: list<array{
         *       message: string,
         *       location: array{file: string, line: int}
         *     }>
         *   }>
         * } $jsonDecoded
         */
        $jsonDecoded = json_decode($jsonFormatter->formatPhpstanJsonExport($payload), true, 512, JSON_THROW_ON_ERROR);
        TestCase::assertSame(1, $jsonDecoded['summary']['totalIssues'], 'JSON export array payloads should still normalize into one issue.');
        TestCase::assertSame('/tmp/a.php:12', $jsonDecoded['clusters'][0]['affectedFiles'][0], 'JSON export array payloads should preserve path:line affected files.');
        TestCase::assertSame('/tmp/a.php', $jsonDecoded['clusters'][0]['representativeIssues'][0]['location']['file'], 'Representative issue locations should preserve the file path.');
        TestCase::assertSame(12, $jsonDecoded['clusters'][0]['representativeIssues'][0]['location']['line'], 'Representative issue locations should preserve the issue line.');

        /** @var array{tool: string, summary: array{totalIssues: int}} $toonDecoded */
        $toonDecoded = Toon::decode((new AgentErrorFormatter(['agentFormat' => ['outputMode' => 'toon']]))->formatPhpstanJsonExport($payload));
        TestCase::assertSame('phpstan-agent-format', $toonDecoded['tool'], 'TOON mode should be selected from formatter output mode aliases.');
        TestCase::assertSame(1, $toonDecoded['summary']['totalIssues'], 'TOON mode should preserve the normalized issue count.');

        $ndjsonOutput = (new AgentErrorFormatter(['agentFormat' => ['outputMode' => 'ndjson']]))->formatPhpstanJsonExport($payload);
        $ndjsonLines = explode("\n", trim($ndjsonOutput));
        TestCase::assertSame(2, count($ndjsonLines), 'NDJSON mode should emit one summary line and one cluster line.');
        /** @var array{summary: array{totalIssues: int}} $ndjsonSummary */
        $ndjsonSummary = json_decode($ndjsonLines[0], true, 512, JSON_THROW_ON_ERROR);
        /** @var array{cluster: array{affectedFiles: list<string>}} $ndjsonCluster */
        $ndjsonCluster = json_decode($ndjsonLines[1], true, 512, JSON_THROW_ON_ERROR);
        TestCase::assertSame(1, $ndjsonSummary['summary']['totalIssues'], 'NDJSON summary lines should preserve total issues.');
        TestCase::assertSame(['/tmp/a.php:12'], $ndjsonCluster['cluster']['affectedFiles'], 'NDJSON cluster lines should wrap the affected files payload.');

        $markdownOutput = (new AgentErrorFormatter(['agentFormat' => ['outputMode' => 'markdown']]))->formatPhpstanJsonExport($payload);
        TestCase::assertTrue(str_contains($markdownOutput, '# PHPStan Agent Repair Envelope'), 'Markdown mode should emit the markdown envelope header.');
        TestCase::assertTrue(str_contains($markdownOutput, '- Rule: `method.notFound`'), 'Markdown mode should surface the rule identifier.');
        TestCase::assertTrue(str_contains($markdownOutput, '  - `/tmp/a.php:12` Call to an undefined method Foo::bar().'), 'Markdown mode should list issue locations using file:line references.');

        $compactOutput = (new AgentErrorFormatter(['agentFormat' => ['outputMode' => 'compact']]))->formatPhpstanJsonExport($payload);
        TestCase::assertTrue(str_contains($compactOutput, 'phpstan-agent-format totalIssues=1 clusters=1 suppressed=0'), 'Compact mode should emit the summary line.');
        TestCase::assertTrue(str_contains($compactOutput, 'rule=method.notFound'), 'Compact mode should include cluster rule identifiers.');
    }
}
