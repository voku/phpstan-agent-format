<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Integration;

use HelgeSverre\Toon\Toon;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class PhpstanCliConfigIntegrationTest
{
    public static function run(): void
    {
        self::assertCleanRunWithEmptyConfigBlock();
        self::assertPartialConfigTriggersReduction();
    }

    private static function assertCleanRunWithEmptyConfigBlock(): void
    {
        $root = dirname(__DIR__, 2);
        $configPath = sys_get_temp_dir() . '/phpstan-agent-format-clean-' . sha1((string) microtime(true)) . '.neon';

        $config = implode("\n", [
            'includes:',
            '    - ' . $root . '/extension.neon',
            '',
            'parameters:',
            '    level: max',
            '    paths:',
            '        - ' . $root . '/tests/Fixture/CleanRun.php',
            '    agentFormat: {}',
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

            TestCase::assertSame(0, $exitCode, 'Clean fixtures should keep the CLI exit code at zero.');

            /** @var array{
             *   tool: string,
             *   version: string,
             *   schema: array{version:string},
             *   summary: array{
             *     totalIssues: int,
             *     clusters: int,
             *     suppressedDuplicates: int,
             *     tokenStats: array{estimatedTokens: int, tokenBudget: int, wasReduced: bool}
             *   },
             *   clusters: list<array<mixed>>
             * } $decoded */
            $decoded = Toon::decode(implode("\n", $outputLines));

            TestCase::assertSame('phpstan-agent-format', $decoded['tool'], 'Clean runs should still emit the standard tool envelope.');
            TestCase::assertSame('2.0.0', $decoded['version'], 'Clean runs should emit the v2 envelope version.');
            TestCase::assertSame('2.0.0', $decoded['schema']['version'], 'Clean runs should surface the schema version.');
            TestCase::assertSame(0, $decoded['summary']['totalIssues'], 'Clean runs should report zero issues.');
            TestCase::assertSame(0, $decoded['summary']['clusters'], 'Clean runs should report zero clusters.');
            TestCase::assertSame(0, count($decoded['clusters']), 'Clean runs should emit an empty cluster list.');
            TestCase::assertSame(12000, $decoded['summary']['tokenStats']['tokenBudget'], 'Empty config blocks should fall back to the default token budget.');
            TestCase::assertTrue($decoded['summary']['tokenStats']['wasReduced'] === false, 'Clean runs should not be marked as reduced.');
        } finally {
            if (is_file($configPath)) {
                unlink($configPath);
            }
        }
    }

    private static function assertPartialConfigTriggersReduction(): void
    {
        $root = dirname(__DIR__, 2);
        $configPath = sys_get_temp_dir() . '/phpstan-agent-format-reduced-' . sha1((string) microtime(true)) . '.neon';

        $config = implode("\n", [
            'includes:',
            '    - ' . $root . '/extension.neon',
            '',
            'parameters:',
            '    level: max',
            '    paths:',
            '        - ' . $root . '/tests/Fixture/DuplicateUndefinedMethod.php',
            '    agentFormat:',
            '        tokenBudget: 1',
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

            TestCase::assertSame(1, $exitCode, 'Reduced-output fixtures should still fail PHPStan analysis.');

            /** @var array{
             *   summary: array{
             *     totalIssues: int,
             *     suppressedDuplicates: int,
             *     tokenStats: array{estimatedTokens: int, tokenBudget: int, wasReduced: bool}
             *   },
             *   clusters: list<array{
             *     representativeIssues: list<array<mixed>>,
             *     suppressedDuplicateCount: int
             *   }>
             * } $decoded */
            $decoded = Toon::decode(implode("\n", $outputLines));

            TestCase::assertSame(2, $decoded['summary']['totalIssues'], 'Duplicate fixture should still count both raw issues.');
            TestCase::assertSame(1, $decoded['summary']['suppressedDuplicates'], 'Token reduction should suppress the extra representative issue.');
            TestCase::assertSame(1, $decoded['summary']['tokenStats']['tokenBudget'], 'Partial config should preserve explicitly provided values.');
            TestCase::assertTrue($decoded['summary']['tokenStats']['wasReduced'], 'Tiny token budgets should trigger deterministic reduction.');
            TestCase::assertSame(1, count($decoded['clusters']), 'Duplicate fixture should collapse to a single cluster.');
            TestCase::assertSame(1, count($decoded['clusters'][0]['representativeIssues']), 'Reduced output should keep only one representative issue.');
            TestCase::assertSame(1, $decoded['clusters'][0]['suppressedDuplicateCount'], 'Reduced output should record the suppressed duplicate.');
        } finally {
            if (is_file($configPath)) {
                unlink($configPath);
            }
        }
    }
}
