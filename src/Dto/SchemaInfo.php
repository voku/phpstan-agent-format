<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Dto;

final readonly class SchemaInfo
{
    public function __construct(
        public string $name,
        public string $version,
    ) {
    }

    /**
     * @return array{name:string,version:string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
        ];
    }
}
