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
        $nonObjectMethodError = new class ($fixtureFile) {
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
                return 'Cannot call method trim() on string.';
            }

            public function getIdentifier(): string
            {
                return 'method.nonObject';
            }
        };
        $arrayShapeReturnError = new class ($fixtureFile) {
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
                return "Function arrayShapeFixture() should return array{foo: int} but returns array{foo: 'x'}.";
            }

            public function getIdentifier(): string
            {
                return 'return.type';
            }
        };

        $normalizer = new IssueNormalizer(
            new ContextExtractor(new AgentFormatConfig('agentJson', 30, 3, 1, 1, false, true, 12000, [])),
            new ContextTraceBuilder(),
        );

        $issues = $normalizer->normalize(new AnalysisResult([$error], []));
        $issue = $issues[0];

        TestCase::assertSame($fixtureFile, $issue->location->file, 'File path should prefer PHPStan filePath when available.');
        TestCase::assertSame('string', $issue->symbolContext->expectedType, 'Expected type should be extracted from PHPStan messages.');
        TestCase::assertSame('string', $issue->symbolContext->parameterName, 'Parameter names should be extracted from PHPStan messages.');
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
        TestCase::assertSame('value', $parameterIssues[0]->symbolContext->parameterName, 'Method parameter names should be exposed for repair suggestions.');
        TestCase::assertSame('string', $parameterIssues[0]->symbolContext->expectedType, 'Method parameter messages should expose the expected type.');
        TestCase::assertSame('int', $parameterIssues[0]->symbolContext->inferredType, 'Parameter-focused PHPStan messages should also expose the given type.');

        $nonObjectIssues = $normalizer->normalize(new AnalysisResult([$nonObjectMethodError], []));
        TestCase::assertSame('trim', $nonObjectIssues[0]->symbolContext->methodName, 'Non-object method access should expose the accessed member name.');
        TestCase::assertSame('string', $nonObjectIssues[0]->symbolContext->inferredType, 'Non-object method access should expose the receiver type.');

        $arrayShapeIssues = $normalizer->normalize(new AnalysisResult([$arrayShapeReturnError], []));
        TestCase::assertSame('arrayShapeFixture', $arrayShapeIssues[0]->symbolContext->functionName, 'Return-type messages should preserve function names.');
        TestCase::assertSame('array{foo: int}', $arrayShapeIssues[0]->symbolContext->expectedType, 'Return-type messages should expose expected return types.');
        TestCase::assertSame("array{foo: 'x'}", $arrayShapeIssues[0]->symbolContext->inferredType, 'Return-type messages should expose actual return types.');
    }
}
