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
        $genericParameterTypeError = new class ($fixtureFile) {
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
                return 'Parameter #1 $items of function genericParameterFixture expects array<int, string>, array<string, int> given.';
            }

            public function getIdentifier(): string
            {
                return 'argument.type';
            }
        };
        $digitGenericParameterTypeError = new class ($fixtureFile) {
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
                return 'Parameter #1 $items of function genericParameterFixture expects Model1<int, string>, Model2<string, int> given.';
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
        $nonObjectPropertyError = new class ($fixtureFile) {
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
                return 'Cannot access property $length on string.';
            }

            public function getIdentifier(): string
            {
                return 'property.nonObject';
            }
        };
        $undefinedPropertyError = new class ($fixtureFile) {
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
                return 'Access to an undefined property Demo::$missingProperty.';
            }

            public function getIdentifier(): string
            {
                return 'property.notFound';
            }
        };
        $offsetAccessError = new class ($fixtureFile) {
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
                return "Offset 'foo' does not exist on string.";
            }

            public function getIdentifier(): string
            {
                return 'offsetAccess.notFound';
            }
        };
        $returnedValueTipError = new class ($fixtureFile) {
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
                return 'Parameter #1 $string of function strlen expects string, string|null given.';
            }

            public function getIdentifier(): string
            {
                return 'argument.type';
            }

            public function getTip(): string
            {
                return 'See: remembering and forgetting returned values.';
            }
        };
        $nonFileSpecificError = new class () {
            public function getMessage(): string
            {
                return 'Global configuration is invalid.';
            }

            public function getIdentifier(): string
            {
                return 'config.invalid';
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
        TestCase::assertSame('primary', $issue->contextTrace->hops[0]->kind, 'Context hops should expose stable kinds.');

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

        $genericParameterIssues = $normalizer->normalize(new AnalysisResult([$genericParameterTypeError], []));
        TestCase::assertSame('items', $genericParameterIssues[0]->symbolContext->parameterName, 'Generic parameter messages should preserve the argument name.');
        TestCase::assertSame('genericParameterFixture', $genericParameterIssues[0]->symbolContext->functionName, 'Generic parameter messages should preserve the function name.');
        TestCase::assertSame('array<int, string>', $genericParameterIssues[0]->symbolContext->expectedType, 'Generic parameter messages should preserve expected types with nested commas.');
        TestCase::assertSame('array<string, int>', $genericParameterIssues[0]->symbolContext->inferredType, 'Generic parameter messages should preserve inferred types with nested commas.');
        TestCase::assertSame(
            'Generic or template arguments drifted from the declared contract.',
            $genericParameterIssues[0]->fixHint->rootCauseSummary,
            'Generic parameter messages should get the dedicated generic mismatch fix hint.'
        );

        $digitGenericParameterIssues = $normalizer->normalize(new AnalysisResult([$digitGenericParameterTypeError], []));
        TestCase::assertSame('Model1<int, string>', $digitGenericParameterIssues[0]->symbolContext->expectedType, 'Generic detection should preserve expected types containing digits.');
        TestCase::assertSame('Model2<string, int>', $digitGenericParameterIssues[0]->symbolContext->inferredType, 'Generic detection should preserve inferred types containing digits.');
        TestCase::assertSame(
            'Generic or template arguments drifted from the declared contract.',
            $digitGenericParameterIssues[0]->fixHint->rootCauseSummary,
            'Generic mismatch fix hints should still apply when type identifiers contain digits.'
        );

        $nonObjectIssues = $normalizer->normalize(new AnalysisResult([$nonObjectMethodError], []));
        TestCase::assertSame('trim', $nonObjectIssues[0]->symbolContext->methodName, 'Non-object method access should expose the accessed member name.');
        TestCase::assertSame('string', $nonObjectIssues[0]->symbolContext->inferredType, 'Non-object method access should expose the receiver type.');

        $arrayShapeIssues = $normalizer->normalize(new AnalysisResult([$arrayShapeReturnError], []));
        TestCase::assertSame('arrayShapeFixture', $arrayShapeIssues[0]->symbolContext->functionName, 'Return-type messages should preserve function names.');
        TestCase::assertSame('array{foo: int}', $arrayShapeIssues[0]->symbolContext->expectedType, 'Return-type messages should expose expected return types.');
        TestCase::assertSame("array{foo: 'x'}", $arrayShapeIssues[0]->symbolContext->inferredType, 'Return-type messages should expose actual return types.');

        $nonObjectPropertyIssues = $normalizer->normalize(new AnalysisResult([$nonObjectPropertyError], []));
        TestCase::assertSame('length', $nonObjectPropertyIssues[0]->symbolContext->propertyName, 'Non-object property access should expose the accessed property name.');
        TestCase::assertSame('string', $nonObjectPropertyIssues[0]->symbolContext->inferredType, 'Non-object property access should expose the receiver type.');

        $undefinedPropertyIssues = $normalizer->normalize(new AnalysisResult([$undefinedPropertyError], []));
        TestCase::assertSame('Demo', $undefinedPropertyIssues[0]->symbolContext->className, 'Undefined property messages should preserve the declaring class.');
        TestCase::assertSame('missingProperty', $undefinedPropertyIssues[0]->symbolContext->propertyName, 'Undefined property messages should expose the missing property name.');

        $offsetAccessIssues = $normalizer->normalize(new AnalysisResult([$offsetAccessError], []));
        TestCase::assertSame('string', $offsetAccessIssues[0]->symbolContext->inferredType, 'Offset-access messages should expose the container type.');
        TestCase::assertSame('The inferred container type does not define the accessed offset.', $offsetAccessIssues[0]->fixHint->rootCauseSummary, 'Offset-access issues should provide a dedicated fix hint.');

        $metadataRichError = new class ($fixtureFile) {
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
                return 'Generic mismatch.';
            }

            public function getIdentifier(): string
            {
                return 'argument.type';
            }

            /**
             * @return array<string, mixed>
             */
            public function getMetadata(): array
            {
                return [
                    'symbol' => [
                        'className' => 'MetadataDemo',
                        'methodName' => 'repair',
                        'parameterName' => '$payload',
                    ],
                    'types' => [
                        'expectedType' => 'array<int, string>',
                        'inferredType' => 'array<string, int>',
                    ],
                    'origin' => [
                        'typeOrigin' => 'metadata',
                    ],
                ];
            }
        };

        $metadataIssues = $normalizer->normalize(new AnalysisResult([$metadataRichError], []));
        TestCase::assertSame('MetadataDemo::repair', $metadataIssues[0]->symbolContext->methodName, 'Metadata-backed method names should be preferred over message heuristics.');
        TestCase::assertSame('payload', $metadataIssues[0]->symbolContext->parameterName, 'Metadata-backed parameter names should be normalized.');
        TestCase::assertSame('array<int, string>', $metadataIssues[0]->symbolContext->expectedType, 'Metadata-backed expected types should be preserved.');
        TestCase::assertSame('array<string, int>', $metadataIssues[0]->symbolContext->inferredType, 'Metadata-backed inferred types should be preserved.');
        TestCase::assertSame('metadata', $metadataIssues[0]->symbolContext->typeOrigin, 'Metadata-backed type origins should be surfaced.');

        $metadataListError = new class ($fixtureFile) {
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
                return 'Generic mismatch in list metadata.';
            }

            public function getIdentifier(): string
            {
                return 'argument.type';
            }

            /**
             * @return array<string, mixed>
             */
            public function getMetadata(): array
            {
                return [
                    'candidates' => [
                        [
                            'parameterName' => '$items',
                            'expectedType' => 'array<int, string>',
                        ],
                        [
                            'inferredType' => 'array<string, int>',
                        ],
                    ],
                ];
            }
        };

        $metadataListIssues = $normalizer->normalize(new AnalysisResult([$metadataListError], []));
        TestCase::assertSame('items', $metadataListIssues[0]->symbolContext->parameterName, 'Metadata normalization should preserve nested list entries.');
        TestCase::assertSame('array<int, string>', $metadataListIssues[0]->symbolContext->expectedType, 'List-based metadata should preserve expected types.');
        TestCase::assertSame('array<string, int>', $metadataListIssues[0]->symbolContext->inferredType, 'List-based metadata should preserve inferred types.');

        $returnedValueIssues = $normalizer->normalize(new AnalysisResult([$returnedValueTipError], []));
        TestCase::assertSame('returned-value', $returnedValueIssues[0]->symbolContext->typeOrigin, 'Returned-value hints should be surfaced as a dedicated type origin.');

        $nonFileSpecificIssues = $normalizer->normalize(new AnalysisResult([], ['General PHPStan error.', $nonFileSpecificError]));
        TestCase::assertSame(2, count($nonFileSpecificIssues), 'Non-file-specific errors should still be normalized.');
        TestCase::assertSame('unknown.php', $nonFileSpecificIssues[0]->location->file, 'Non-file-specific errors should use the synthetic location.');
        $nonFileSpecificMessages = array_map(static fn ($issue): string => $issue->message, $nonFileSpecificIssues);
        TestCase::assertTrue(in_array('Global configuration is invalid.', $nonFileSpecificMessages, true), 'Object-shaped non-file-specific errors should preserve their message.');
    }
}
