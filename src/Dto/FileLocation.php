<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Dto;

final readonly class FileLocation
{
    public function __construct(
        public string $file,
        public int $line,
    ) {
    }

    /**
     * @return array{file:string,line:int}
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
        ];
    }
}
