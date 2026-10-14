<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Fixture\ItpContext;

use ItpContext\Attribute\Rule;

#[Rule(Rules::Mail)]
final class Mailer
{
    public function run(): string
    {
        return 'Mailer';
    }
}
