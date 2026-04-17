<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Contract;

use Voku\PhpstanAgentFormat\Dto\PresentationResult;

interface AgentSerializerInterface
{
    public function serialize(PresentationResult $presentation): string;
}
