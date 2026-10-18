<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Fixture\ItpContextBroken;

use ItpContext\Attribute\Rule;
use Voku\PhpstanAgentFormat\Tests\Fixture\ItpContext\NoSuchRules;

#[Rule(NoSuchRules::Database)]
final class UnresolvableRule
{
    public function run(string $value): int
    {
        return $value->length();
    }
}
