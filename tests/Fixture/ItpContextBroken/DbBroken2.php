<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Fixture\ItpContextBroken;

use ItpContext\Attribute\Rule;
use Voku\PhpstanAgentFormat\Tests\Fixture\ItpContext\Rules;

#[Rule(Rules::Database)]
final class DbBroken2
{
    public function run(string $value): int
    {
        return $value->length();
    }
}
