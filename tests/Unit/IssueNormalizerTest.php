<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Unit;

use PHPStan\Command\AnalysisResult;
use Voku\PhpstanAgentFormat\Config\AgentFormatConfig;
use Voku\PhpstanAgentFormat\Context\ContextExtractor;
use Voku\PhpstanAgentFormat\Context\ContextTraceBuilder;
use Voku\PhpstanAgentFormat\Normalizer\IssueNormalizer;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class IssueNormalizerTest
{
    public static function run(): void
    {
        $fixtureFile = dirname(__DIR__) . '/Fixture/Sample.php';
        TestCase::assertTrue(is_file($fixtureFile), 'Fixture file should exist.');

        $error = new class ($fixtureFile) {
            public function __construct(private readonly string $file)
            {
            }

            public function getFile(): string
            {
                return 'Sample.php';
            }

            public function getFilePath(): string
            {
                return $this->file;
            }

            public function getLine(): int
            {
                return 9;
            }

            public function getNodeLine(): int
            {
                return 7;
            }

            public function getNodeType(): string
            {
                return 'PhpParser\\Node\\Expr\\FuncCall';
            }

            public function getMessage(): string
            {
                return 'Parameter #1 $string of function strlen expects string, string|null given.';
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
        $methodParameterTypeError = new class ($fixtureFile) {
            public function __construct(private readonly string $file)
            {
            }

            public function getFile(): string
            {
                return $this->file;
            }

            public function getLine(): int
            {
                return 9;
            }

            public function getMessage(): string
            {
                return 'Method Demo::run() expects parameter #1 $value to be string, int given.';
            }

            public function getIdentifier(): string
            {
                return 'argument.type';
            }
        };

        $normalizer = new IssueNormalizer(
            new ContextExtractor(new AgentFormatConfig('agentJson', 30, 3, 1, 1, false, true, 12000, [])),
            new ContextTraceBuilder(),
        );

        $issues = $normalizer->normalize(new AnalysisResult([$error], []));
        $issue = $issues[0];

        TestCase::assertSame($fixtureFile, $issue->location->file, 'File path should prefer PHPStan filePath when available.');
        TestCase::assertSame('string|null', $issue->symbolContext->inferredType, 'Given type should be extracted from PHPStan messages.');
        TestCase::assertSame('phpdoc', $issue->symbolContext->typeOrigin, 'PHPDoc type origin should be detected from PHPStan tips.');
        TestCase::assertSame(1, count($issue->secondaryLocations), 'Node origin should be promoted to a secondary location.');
        TestCase::assertSame(7, $issue->secondaryLocations[0]->line, 'Node line should be preserved for deeper tracing.');
        TestCase::assertTrue(count($issue->snippet->lines) > 0, 'Snippet extraction should use the actionable file path.');

        $summaries = array_map(static fn ($hop): string => $hop->summary, $issue->contextTrace->hops);
        $hasNodeContext = false;
        $hasPhpDocHint = false;
        foreach ($summaries as $summary) {
            $hasNodeContext = $hasNodeContext || str_contains($summary, 'AST node');
            $hasPhpDocHint = $hasPhpDocHint || str_contains($summary, 'PHPDoc');
        }

        TestCase::assertTrue($hasNodeContext, 'Context trace should include the PHPStan node context.');
        TestCase::assertTrue($hasPhpDocHint, 'Context trace should include the PHPStan type-origin hint.');

        $parameterIssues = $normalizer->normalize(new AnalysisResult([$methodParameterTypeError], []));
        TestCase::assertSame('int', $parameterIssues[0]->symbolContext->inferredType, 'Parameter-focused PHPStan messages should also expose the given type.');
    }
}
