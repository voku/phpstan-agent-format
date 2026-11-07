<?php

declare(strict_types=1);

function fixableIntegerCastFixture(int $value): int
{
    return (int) $value;
}

function nonFixableStringCastFixture(string $value): string
{
    return (string) $value;
}
