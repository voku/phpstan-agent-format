<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Fixture\ItpContext;

use ItpContext\Attribute\Rule;

#[Rule(Rules::Database)]
final class Bar
{
    public function run(): string
    {
        return 'Bar';
    }
}
