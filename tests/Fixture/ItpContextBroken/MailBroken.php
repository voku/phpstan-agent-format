<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Fixture\ItpContextBroken;

use ItpContext\Attribute\Rule;
use Voku\PhpstanAgentFormat\Tests\Fixture\ItpContext\Rules;

#[Rule(Rules::Mail)]
final class MailBroken
{
    public function run(string $value): int
    {
        return $value->length();
    }
}
