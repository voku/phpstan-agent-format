<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Integration;

use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class PhpstanCliBlindSpotMatrixTest
{
    public static function run(): void
    {
        $root = dirname(__DIR__, 2);
        $configPath = sys_get_temp_dir() . '/phpstan-agent-format-blind-spots-' . sha1((string) microtime(true)) . '.neon';

        $config = implode("\n", [
            'includes:',
            '    - ' . $root . '/extension.neon',
            '',
            'parameters:',
            '    level: max',
            '    paths:',
            '        - ' . $root . '/tests/Fixture/PropertyOnString.php',
            '        - ' . $root . '/tests/Fixture/UndefinedProperty.php',
            '        - ' . $root . '/tests/Fixture/UndefinedMethods.php',
            '        - ' . $root . '/tests/Fixture/GenericReturn.php',
            '        - ' . $root . '/tests/Fixture/IntersectionReturn.php',
            '        - ' . $root . '/tests/Fixture/OffsetAccess.php',
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

            TestCase::assertSame(1, $exitCode, 'Blind-spot fixture matrix should produce failing PHPStan output.');

            /** @var array{
             *   summary: array{totalIssues: int},
             *   clusters: list<array{
             *     kind: string,
             *     representativeIssues: list<array{
             *       message: string,
             *       symbolContext: array{
             *         className: ?string,
             *         methodName: ?string,
             *         propertyName: ?string,
             *         functionName: ?string,
             *         expectedType: ?string,
             *         inferredType: ?string
             *       },
             *       rootCauseSummary: string
             *     }>
             *   }>
             * } $decoded */
            $decoded = json_decode(implode("\n", $outputLines), true, 512, JSON_THROW_ON_ERROR);

            TestCase::assertSame(7, $decoded['summary']['totalIssues'], 'Blind-spot fixture matrix should contribute the expected issues.');

            $issuesByMessage = [];
            $kindsByMessage = [];
            foreach ($decoded['clusters'] as $cluster) {
                foreach ($cluster['representativeIssues'] as $issue) {
                    $issuesByMessage[$issue['message']] = $issue;
                    $kindsByMessage[$issue['message']] = $cluster['kind'];
                }
            }

            $propertyOnString = $issuesByMessage['Cannot access property $length on string.'] ?? null;
            TestCase::assertTrue(is_array($propertyOnString), 'Property-on-string fixture should be present.');
            /** @var array{message: string, symbolContext: array{className: ?string, methodName: ?string, propertyName: ?string, functionName: ?string, expectedType: ?string, inferredType: ?string}, rootCauseSummary: string} $propertyOnString */
            TestCase::assertSame('undefined-member-from-inferred-type', $kindsByMessage[$propertyOnString['message']], 'Non-object property access should reuse the inferred-member cluster kind.');
            TestCase::assertSame('length', $propertyOnString['symbolContext']['propertyName'], 'Property-on-string should expose the accessed property.');
            TestCase::assertSame('string', $propertyOnString['symbolContext']['inferredType'], 'Property-on-string should expose the receiver type.');

            $undefinedProperty = $issuesByMessage['Access to an undefined property UndefinedPropertyTarget::$missingProperty.'] ?? null;
            TestCase::assertTrue(is_array($undefinedProperty), 'Undefined-property fixture should be present.');
            /** @var array{message: string, symbolContext: array{className: ?string, methodName: ?string, propertyName: ?string, functionName: ?string, expectedType: ?string, inferredType: ?string}, rootCauseSummary: string} $undefinedProperty */
            TestCase::assertSame('UndefinedPropertyTarget', $undefinedProperty['symbolContext']['className'], 'Undefined-property errors should preserve the target class.');
            TestCase::assertSame('missingProperty', $undefinedProperty['symbolContext']['propertyName'], 'Undefined-property errors should preserve the missing property name.');

            $undefinedMethod = $issuesByMessage['Call to an undefined method UndefinedMethodsTarget::missingMethod().'] ?? null;
            TestCase::assertTrue(is_array($undefinedMethod), 'Undefined-method fixture should be present.');
            /** @var array{message: string, symbolContext: array{className: ?string, methodName: ?string, propertyName: ?string, functionName: ?string, expectedType: ?string, inferredType: ?string}, rootCauseSummary: string} $undefinedMethod */
            TestCase::assertSame('UndefinedMethodsTarget::missingMethod', $undefinedMethod['symbolContext']['methodName'], 'Undefined-method errors should preserve instance member names.');

            $undefinedStaticMethod = $issuesByMessage['Call to an undefined static method UndefinedMethodsTarget::missingStatic().'] ?? null;
            TestCase::assertTrue(is_array($undefinedStaticMethod), 'Undefined-static-method fixture should be present.');
            /** @var array{message: string, symbolContext: array{className: ?string, methodName: ?string, propertyName: ?string, functionName: ?string, expectedType: ?string, inferredType: ?string}, rootCauseSummary: string} $undefinedStaticMethod */
            TestCase::assertSame('UndefinedMethodsTarget::missingStatic', $undefinedStaticMethod['symbolContext']['methodName'], 'Undefined-static-method errors should preserve static member names.');

            $genericReturn = $issuesByMessage['Function genericReturnFixture() should return array<int, string> but returns array<string, int>.'] ?? null;
            TestCase::assertTrue(is_array($genericReturn), 'Generic-return fixture should be present.');
            /** @var array{message: string, symbolContext: array{className: ?string, methodName: ?string, propertyName: ?string, functionName: ?string, expectedType: ?string, inferredType: ?string}, rootCauseSummary: string} $genericReturn */
            TestCase::assertSame('generic-template-drift', $kindsByMessage[$genericReturn['message']], 'Generic return mismatches should use the dedicated generic drift cluster.');
            TestCase::assertSame('genericReturnFixture', $genericReturn['symbolContext']['functionName'], 'Generic return mismatches should preserve function names.');
            TestCase::assertSame('array<int, string>', $genericReturn['symbolContext']['expectedType'], 'Generic return mismatches should preserve expected types.');
            TestCase::assertSame('array<string, int>', $genericReturn['symbolContext']['inferredType'], 'Generic return mismatches should preserve inferred types.');

            $intersectionReturn = $issuesByMessage['Function intersectionReturnFixture() should return IntersectionReturnA&IntersectionReturnB but returns IntersectionReturnOnlyA.'] ?? null;
            TestCase::assertTrue(is_array($intersectionReturn), 'Intersection-return fixture should be present.');
            /** @var array{message: string, symbolContext: array{className: ?string, methodName: ?string, propertyName: ?string, functionName: ?string, expectedType: ?string, inferredType: ?string}, rootCauseSummary: string} $intersectionReturn */
            TestCase::assertSame('intersectionReturnFixture', $intersectionReturn['symbolContext']['functionName'], 'Intersection return mismatches should preserve function names.');
            TestCase::assertSame('IntersectionReturnA&IntersectionReturnB', $intersectionReturn['symbolContext']['expectedType'], 'Intersection return mismatches should preserve expected types.');
            TestCase::assertSame('IntersectionReturnOnlyA', $intersectionReturn['symbolContext']['inferredType'], 'Intersection return mismatches should preserve inferred types.');

            $offsetAccess = $issuesByMessage["Offset 'foo' does not exist on string."] ?? null;
            TestCase::assertTrue(is_array($offsetAccess), 'Offset-access fixture should be present.');
            /** @var array{message: string, symbolContext: array{className: ?string, methodName: ?string, propertyName: ?string, functionName: ?string, expectedType: ?string, inferredType: ?string}, rootCauseSummary: string} $offsetAccess */
            TestCase::assertSame('invalid-offset-access', $kindsByMessage[$offsetAccess['message']], 'Offset-access fixtures should use the dedicated cluster kind.');
            TestCase::assertSame('string', $offsetAccess['symbolContext']['inferredType'], 'Offset-access fixtures should preserve the container type.');
            TestCase::assertSame('The inferred container type does not define the accessed offset.', $offsetAccess['rootCauseSummary'], 'Offset-access fixtures should receive the dedicated repair hint.');
        } finally {
            if (is_file($configPath)) {
                unlink($configPath);
            }
        }
    }
}
