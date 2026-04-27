<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Serializer;

use HelgeSverre\Toon\Toon;
use Voku\PhpstanAgentFormat\Contract\AgentSerializerInterface;
use Voku\PhpstanAgentFormat\Dto\PresentationResult;

final class ToonAgentSerializer implements AgentSerializerInterface
{
    public function serialize(PresentationResult $presentation): string
    {
        return Toon::encode($presentation->toArray());
    }
}
