<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Fixture\ItpContextBroken;

final class PlainBroken
{
    public function run(string $value): int
    {
        return $value->length();
    }
}
