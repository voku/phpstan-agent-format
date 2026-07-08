<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Dto;

final readonly class RelatedDefinition
{
    /**
     * @param list<string> $snippet
     * @param list<string> $attributes
     */
    public function __construct(
        public string $file,
        public int $line,
        public string $symbol,
        public string $kind,
        public array $snippet,
        public array $attributes = [],
    ) {
    }

    /**
     * @return array{file:string,line:int,symbol:string,kind:string,snippet:list<string>,attributes:list<string>}
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'symbol' => $this->symbol,
            'kind' => $this->kind,
            'snippet' => $this->snippet,
            'attributes' => $this->attributes,
        ];
    }
}
