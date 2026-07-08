<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Support;

use RuntimeException;

final class TestCase
{
    public static function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public static function phpstanCommand(string $root): string
    {
        $agentEnvironment = [
            'AUGMENT_AGENT',
            'AMP_CURRENT_THREAD_ID',
            'AI_AGENT',
            'CURSOR_TRACE_ID',
            'CURSOR_AGENT',
            'GEMINI_CLI',
            'CODEX_SANDBOX',
            'CODEX_THREAD_ID',
            'OPENCODE_CLIENT',
            'OPENCODE',
            'CLAUDECODE',
            'CLAUDE_CODE',
            'REPL_ID',
        ];

        $unset = implode(' ', array_map(static fn (string $name): string => '-u ' . escapeshellarg($name), $agentEnvironment));

        return sprintf(
            'env %s %s %s',
            $unset,
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/vendor/bin/phpstan'),
        );
    }

    /**
     * @return array{0: string, 1: int}
     */
    public static function runPhpstan(string $root, string $configPath, string $errorFormat): array
    {
        $outputLines = [];
        $exitCode = 0;

        exec(sprintf(
            '%s analyse --configuration %s --error-format=%s --no-progress 2>&1',
            self::phpstanCommand($root),
            escapeshellarg($configPath),
            escapeshellarg($errorFormat),
        ), $outputLines, $exitCode);

        return [implode("\n", $outputLines), $exitCode];
    }

    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    public static function assertSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }

    /**
     * @param array<string,mixed> $array
     */
    public static function assertHasKey(string $key, array $array, string $message): void
    {
        if (!array_key_exists($key, $array)) {
            throw new RuntimeException($message . " (missing key: {$key})");
        }
    }
}
