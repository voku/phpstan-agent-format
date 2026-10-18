<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Fixture\ItpContextBroken;

use ItpContext\Attribute\Rule;
use Voku\PhpstanAgentFormat\Tests\Fixture\ItpContext\Rules;

#[Rule(Rules::Mail)]
trait AnnotatedBrokenTrait
{
    public function broken(string $value): int
    {
        return $value->length();
    }
}
