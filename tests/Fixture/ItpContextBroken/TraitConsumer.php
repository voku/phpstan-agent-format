<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Fixture\ItpContextBroken;

final class TraitConsumer
{
    use AnnotatedBrokenTrait;
}
