<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Integration;

use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class PhpstanCliIntegrationTest
{
    public static function run(): void
    {
        $root = dirname(__DIR__, 2);
        $configPath = sys_get_temp_dir() . '/phpstan-agent-format-' . sha1((string) microtime(true)) . '.neon';

        $config = implode("\n", [
            'includes:',
            '    - ' . $root . '/extension.neon',
            '',
            'parameters:',
            '    level: max',
            '    paths:',
            '        - ' . $root . '/tests/Fixture/Sample.php',
            '        - ' . $root . '/tests/Fixture/MissingPropertyType.php',
            '        - ' . $root . '/tests/Fixture/MissingParameterType.php',
            '    agentFormat:',
            '        outputMode: json',
            '        redactPatterns:',
            "            - '(?i)password\\s*=\\s*.+'",
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

            TestCase::assertSame(1, $exitCode, 'PHPStan CLI should return a failing exit code when fixture issues exist.');

            $output = implode("\n", $outputLines);
            /** @var array{
             *   summary: array{totalIssues: int},
             *   clusters: list<array{
             *     representativeIssues: list<array{
             *       message: string,
             *       symbolContext: array{
             *         className: ?string,
             *         methodName: ?string,
             *         propertyName: ?string,
             *         functionName: ?string,
             *         parameterName: ?string,
             *         expectedType: ?string,
             *         inferredType: ?string
             *       },
             *       snippet: array{lines: list<string>}
             *     }>
             *   }>
             * } $decoded */
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

            TestCase::assertSame(3, $decoded['summary']['totalIssues'], 'CLI integration should analyze all fixture issues.');

            $issuesByMessage = [];
            foreach ($decoded['clusters'] as $cluster) {
                foreach ($cluster['representativeIssues'] as $issue) {
                    $issuesByMessage[$issue['message']] = $issue;
                }
            }

            $nullableIssue = $issuesByMessage['Parameter #1 $string of function strlen expects string, string|null given.'] ?? null;
            TestCase::assertTrue(is_array($nullableIssue), 'Nullable fixture issue should be present.');
            TestCase::assertSame('strlen', $nullableIssue['symbolContext']['functionName'], 'Function name should come from real PHPStan output.');
            TestCase::assertSame('string', $nullableIssue['symbolContext']['expectedType'], 'Expected type should be extracted from real PHPStan output.');
            TestCase::assertSame('string|null', $nullableIssue['symbolContext']['inferredType'], 'Given type should remain available from real PHPStan output.');
            TestCase::assertTrue(str_contains(implode("\n", $nullableIssue['snippet']['lines']), '[REDACTED]'), 'Configured redaction should apply when running PHPStan through the CLI.');

            $propertyIssue = $issuesByMessage['Property MissingPropertyType::$name has no type specified.'] ?? null;
            TestCase::assertTrue(is_array($propertyIssue), 'Missing-property fixture issue should be present.');
            TestCase::assertSame('MissingPropertyType', $propertyIssue['symbolContext']['className'], 'Property class name should be captured from real PHPStan output.');
            TestCase::assertSame('name', $propertyIssue['symbolContext']['propertyName'], 'Property name should be captured without losing the member name.');

            $parameterIssue = $issuesByMessage['Method MissingParameterType::run() has parameter $value with no type specified.'] ?? null;
            TestCase::assertTrue(is_array($parameterIssue), 'Missing-parameter fixture issue should be present.');
            TestCase::assertSame('MissingParameterType::run', $parameterIssue['symbolContext']['methodName'], 'Method name should be captured from real PHPStan output.');
            TestCase::assertSame('value', $parameterIssue['symbolContext']['parameterName'], 'Parameter name should be captured from real PHPStan output.');
        } finally {
            if (is_file($configPath)) {
                unlink($configPath);
            }
        }
    }
}
