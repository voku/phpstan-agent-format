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
