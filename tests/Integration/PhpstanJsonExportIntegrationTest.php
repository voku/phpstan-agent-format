<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Integration;

use Voku\PhpstanAgentFormat\Formatter\AgentErrorFormatter;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class PhpstanJsonExportIntegrationTest
{
    public static function run(): void
    {
        $root = dirname(__DIR__, 2);
        $outputLines = [];
        $exitCode = 0;

        exec(sprintf(
            '%s %s analyse --configuration %s --error-format=json --no-progress 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/vendor/bin/phpstan'),
            escapeshellarg($root . '/tests/Config/phpstan-agent-fixtures.neon'),
        ), $outputLines, $exitCode);

        TestCase::assertSame(1, $exitCode, 'The PHPStan JSON export should still report failing fixtures.');

        $formatter = new AgentErrorFormatter([
            'agentFormat' => [
                'outputMode' => 'json',
            ],
        ]);

        /** @var array{
         *   tool: string,
         *   schema: array{version:string},
         *   summary: array{totalIssues:int},
         *   clusters: list<array{representativeIssues:list<array{message:string}>}>
         * } $decoded */
        $decoded = json_decode($formatter->formatPhpstanJsonExport(implode("\n", $outputLines)), true, 512, JSON_THROW_ON_ERROR);

        TestCase::assertSame('phpstan-agent-format', $decoded['tool'], 'JSON export ingestion should emit the standard tool name.');
        TestCase::assertSame('2.0.0', $decoded['schema']['version'], 'JSON export ingestion should emit the v2 schema descriptor.');
        TestCase::assertSame(14, $decoded['summary']['totalIssues'], 'JSON export ingestion should preserve all fixture issues.');

        $messages = [];
        foreach ($decoded['clusters'] as $cluster) {
            foreach ($cluster['representativeIssues'] as $issue) {
                $messages[] = $issue['message'];
            }
        }

        TestCase::assertTrue(in_array('Function genericReturnFixture() should return array<int, string> but returns array<string, int>.', $messages, true), 'JSON export ingestion should preserve representative issue messages.');
    }
}
