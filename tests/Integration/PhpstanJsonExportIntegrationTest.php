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
        [$output, $exitCode] = TestCase::runPhpstan($root, $root . '/tests/Config/phpstan-agent-fixtures.neon', 'json');

        TestCase::assertSame(1, $exitCode, 'The PHPStan JSON export should still report failing fixtures.');

        $formatter = new AgentErrorFormatter([
            'agentFormat' => [
                'outputMode' => 'json',
            ],
        ]);

        /** @var array{
         *   tool: string,
         *   summary: array{totalIssues:int},
         *   clusters: list<array{representativeIssues:list<array{message:string}>}>
         * } $decoded */
        $decoded = json_decode($formatter->formatPhpstanJsonExport($output), true, 512, JSON_THROW_ON_ERROR);

        TestCase::assertSame('phpstan-agent-format', $decoded['tool'], 'JSON export ingestion should emit the standard tool name.');
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
