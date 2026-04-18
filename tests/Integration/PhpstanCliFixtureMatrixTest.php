<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Integration;

use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class PhpstanCliFixtureMatrixTest
{
    public static function run(): void
    {
        $root = dirname(__DIR__, 2);
        $configPath = sys_get_temp_dir() . '/phpstan-agent-format-matrix-' . sha1((string) microtime(true)) . '.neon';

        $config = implode("\n", [
            'includes:',
            '    - ' . $root . '/extension.neon',
            '',
            'parameters:',
            '    level: max',
            '    paths:',
            '        - ' . $root . '/tests/Fixture/ArrayShapeReturn.php',
            '        - ' . $root . '/tests/Fixture/GenericParameter.php',
            '        - ' . $root . '/tests/Fixture/MethodOnString.php',
            '        - ' . $root . '/tests/Fixture/NullCoalesceVariable.php',
            '    agentFormat:',
            '        outputMode: json',
            '',
        ]);

        file_put_contents($configPath, $config);

        try {
            $outputLines = [];
            $exitCode = 0;

            exec(sprintf(
                '%s %s analyse --configuration %s --error-format=agent --no-progress 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($root . '/vendor/bin/phpstan'),
                escapeshellarg($configPath),
            ), $outputLines, $exitCode);

            TestCase::assertSame(1, $exitCode, 'PHPStan CLI should fail when the fixture matrix contains issues.');

            $output = implode("\n", $outputLines);
            /** @var array{
             *   summary: array{totalIssues: int},
             *   clusters: list<array{
             *     kind: string,
             *     representativeIssues: list<array{
             *       message: string,
             *       symbolContext: array{
             *         methodName: ?string,
             *         functionName: ?string,
             *         expectedType: ?string,
             *         inferredType: ?string
             *       },
             *       rootCauseSummary: string
             *     }>
             *   }>
             * } $decoded */
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

            TestCase::assertSame(4, $decoded['summary']['totalIssues'], 'Fixture matrix should contribute four distinct issues.');

            $issuesByMessage = [];
            $kindsByMessage = [];
            foreach ($decoded['clusters'] as $cluster) {
                foreach ($cluster['representativeIssues'] as $issue) {
                    $issuesByMessage[$issue['message']] = $issue;
                    $kindsByMessage[$issue['message']] = $cluster['kind'];
                }
            }

            $arrayShapeIssue = $issuesByMessage["Function arrayShapeFixture() should return array{foo: int} but returns array{foo: 'x'}." ] ?? null;
            TestCase::assertTrue(is_array($arrayShapeIssue), 'Array-shape drift fixture should be present.');
            /** @var array{message: string, symbolContext: array{methodName: ?string, functionName: ?string, expectedType: ?string, inferredType: ?string}, rootCauseSummary: string} $arrayShapeIssue */
            TestCase::assertSame('array-shape-drift', $kindsByMessage[$arrayShapeIssue['message']], 'Array-shape fixtures should keep the dedicated cluster kind.');
            TestCase::assertSame('arrayShapeFixture', $arrayShapeIssue['symbolContext']['functionName'], 'Array-shape function names should be preserved.');
            TestCase::assertSame('array{foo: int}', $arrayShapeIssue['symbolContext']['expectedType'], 'Array-shape expected return types should be extracted.');
            TestCase::assertSame("array{foo: 'x'}", $arrayShapeIssue['symbolContext']['inferredType'], 'Array-shape actual return types should be extracted.');

            $genericParameterIssue = $issuesByMessage['Parameter #1 $items of function genericParameterFixture expects array<int, string>, array<string, int> given.'] ?? null;
            TestCase::assertTrue(is_array($genericParameterIssue), 'Generic-parameter fixture should be present.');
            /** @var array{message: string, symbolContext: array{methodName: ?string, functionName: ?string, expectedType: ?string, inferredType: ?string, parameterName?: ?string}, rootCauseSummary: string} $genericParameterIssue */
            TestCase::assertSame('generic-template-drift', $kindsByMessage[$genericParameterIssue['message']], 'Generic parameter mismatches should use the dedicated generic drift cluster.');
            TestCase::assertSame('genericParameterFixture', $genericParameterIssue['symbolContext']['functionName'], 'Generic parameter mismatches should preserve function names.');
            TestCase::assertSame('array<int, string>', $genericParameterIssue['symbolContext']['expectedType'], 'Generic parameter mismatches should preserve expected types with inner commas.');
            TestCase::assertSame('array<string, int>', $genericParameterIssue['symbolContext']['inferredType'], 'Generic parameter mismatches should preserve inferred types with inner commas.');

            $methodIssue = $issuesByMessage['Cannot call method trim() on string.'] ?? null;
            TestCase::assertTrue(is_array($methodIssue), 'Non-object method fixture should be present.');
            /** @var array{message: string, symbolContext: array{methodName: ?string, functionName: ?string, expectedType: ?string, inferredType: ?string}, rootCauseSummary: string} $methodIssue */
            TestCase::assertSame('undefined-member-from-inferred-type', $kindsByMessage[$methodIssue['message']], 'Non-object member access should use the inferred-member cluster kind.');
            TestCase::assertSame('trim', $methodIssue['symbolContext']['methodName'], 'The accessed member name should be extracted from non-object method errors.');
            TestCase::assertSame('string', $methodIssue['symbolContext']['inferredType'], 'The non-object receiver type should be extracted.');
            TestCase::assertSame('The inferred type does not define the accessed member.', $methodIssue['rootCauseSummary'], 'Non-object member access should reuse the inferred-member fix hint.');

            $nullCoalesceIssue = $issuesByMessage['Variable $value on left side of ?? always exists and is not nullable.'] ?? null;
            TestCase::assertTrue(is_array($nullCoalesceIssue), 'Null-coalesce fixture should be present.');
            /** @var array{message: string, symbolContext: array{methodName: ?string, functionName: ?string, expectedType: ?string, inferredType: ?string}, rootCauseSummary: string} $nullCoalesceIssue */
            TestCase::assertSame('nullable-propagation', $kindsByMessage[$nullCoalesceIssue['message']], 'Null-coalesce identifiers should remain in the nullable cluster family.');
        } finally {
            if (is_file($configPath)) {
                unlink($configPath);
            }
        }
    }
}
