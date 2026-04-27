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

            public function getFilePath(): string
            {
                return $this->file;
            }

            public function getLine(): int
            {
                return 8;
            }

            public function getNodeLine(): int
            {
                return 9;
            }

            public function getNodeType(): string
            {
                return 'PhpParser\\Node\\Expr\\FuncCall';
            }

            public function getMessage(): string
            {
                return 'Parameter #1 $name of function strlen expects string, string|null given. password = abc123';
            }

            public function getIdentifier(): string
            {
                return 'argument.type';
            }

            public function getTip(): string
            {
                return 'Because the type is coming from a PHPDoc, you can turn off this check by setting <fg=cyan>treatPhpDocTypesAsCertain: false</> in your <fg=cyan>%configurationFile%</>.';
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

        /** @var array{tool: string, version: string, summary: array{totalIssues: int}, clusters: list<array{representativeIssues: list<array{symbolContext: array{parameterName: ?string, expectedType: ?string, inferredType: ?string, typeOrigin: ?string}, secondaryLocations: list<array{file: string, line: int}>, contextTrace: array{hops: list<array{kind:string,summary: string}>}}>}>} $decoded */
        $decoded = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);
        TestCase::assertSame('phpstan-agent-format', $decoded['tool'], 'Tool name should be stable.');
        TestCase::assertSame('2.0.0', $decoded['version'], 'Envelope version should stay stable.');
        TestCase::assertSame(1, $decoded['summary']['totalIssues'], 'Expected one issue in summary.');
        TestCase::assertTrue(str_contains($output->buffer, '[REDACTED]'), 'Snippet secrets should be redacted.');
        TestCase::assertTrue(str_contains($output->buffer, 'contextTrace'), 'Output should contain context traces.');
        $issue = $decoded['clusters'][0]['representativeIssues'][0];
        TestCase::assertSame('name', $issue['symbolContext']['parameterName'], 'Parameter names should be surfaced when present.');
        TestCase::assertSame('string', $issue['symbolContext']['expectedType'], 'Expected types should be surfaced when present.');
        TestCase::assertSame('string|null', $issue['symbolContext']['inferredType'], 'Given type should be captured as inferred type.');
        TestCase::assertSame('phpdoc', $issue['symbolContext']['typeOrigin'], 'PHPDoc type origin should be surfaced.');
        TestCase::assertSame($fixtureFile . ':8', $decoded['clusters'][0]['affectedFiles'][0], 'Affected files should point agents at the exact source line.');
        TestCase::assertSame(1, count($issue['secondaryLocations']), 'Node-level origin should be surfaced as a secondary location.');

        $hasRichTraceHop = false;
        foreach ($issue['contextTrace']['hops'] as $hop) {
            if ($hop['kind'] === 'ast-node' || $hop['kind'] === 'type-origin' || str_contains($hop['summary'], 'PHPDoc') || str_contains($hop['summary'], 'AST node')) {
                $hasRichTraceHop = true;
                break;
            }
        }

        TestCase::assertTrue($hasRichTraceHop, 'Context trace should include richer PHPStan-derived hops.');
    }
}
