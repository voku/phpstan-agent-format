<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Dto;

final readonly class CodeSnippet
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public int $startLine,
        public int $highlightLine,
        public array $lines,
    ) {
    }

    /**
     * @return array{startLine:int,highlightLine:int,lines:list<string>}
     */
    public function toArray(): array
    {
        return [
            'startLine' => $this->startLine,
            'highlightLine' => $this->highlightLine,
            'lines' => $this->lines,
        ];
    }
}
