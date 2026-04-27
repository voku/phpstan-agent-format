<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Serializer;

use Voku\PhpstanAgentFormat\Contract\AgentSerializerInterface;
use Voku\PhpstanAgentFormat\Dto\PresentationResult;

final class NdjsonAgentSerializer implements AgentSerializerInterface
{
    public function serialize(PresentationResult $presentation): string
    {
        $lines = [];
        $root = $presentation->toArray();

        $summary = [
            'tool' => $root['tool'],
            'phpstanVersion' => $root['phpstanVersion'],
            'summary' => $root['summary'],
        ];
        $lines[] = (string) json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        foreach ($root['clusters'] as $cluster) {
            $lines[] = (string) json_encode(['cluster' => $cluster], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines) . "\n";
    }
}
