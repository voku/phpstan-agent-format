<?php

declare(strict_types=1);

final class SourceContextFixture
{
    /**
     * @param non-empty-string $value
     */
    public function hydrate(string $value): int
    {
        return strlen($value);
    }
}
