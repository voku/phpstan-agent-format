<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Integration;

use PHPStan\Command\AnalysisResult;
use PHPStan\Command\Output;
use Voku\PhpstanAgentFormat\Formatter\AgentErrorFormatter;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class FormatterIntegrationTest
{
    public static function run(): void
    {
        $fixtureFile = dirname(__DIR__) . '/Fixture/Sample.php';

        $error = new class ($fixtureFile) {
            public function __construct(private readonly string $file)
            {
            }

            public function getFile(): string
            {
                return $this->file;
            }

            public function getLine(): int
            {
                return 8;
            }

            public function getMessage(): string
            {
                return 'Parameter #1 $name of function strlen expects string, string|null given. password = abc123';
            }

            public function getIdentifier(): string
            {
                return 'argument.type';
            }
        };

        $analysis = new AnalysisResult([$error], []);
        $output = new Output();
        $formatter = new AgentErrorFormatter([
            'agentFormat' => [
                'outputMode' => 'json',
                'redactPatterns' => ['(?i)password\\s*=\\s*.+'],
            ],
        ]);

        $exitCode = $formatter->formatErrors($analysis, $output);
        TestCase::assertSame(1, $exitCode, 'Formatter should return non-zero when issues exist.');

        $decoded = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);
        TestCase::assertSame('phpstan-agent-format', $decoded['tool'], 'Tool name should be stable.');
        TestCase::assertSame(1, $decoded['summary']['totalIssues'], 'Expected one issue in summary.');
        TestCase::assertTrue(str_contains($output->buffer, '[REDACTED]'), 'Snippet secrets should be redacted.');
        TestCase::assertTrue(str_contains($output->buffer, 'contextTrace'), 'Output should contain context traces.');
    }
}
