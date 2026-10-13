<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Dto;

/**
 * Advisory, non-error context attached to a report. Never counted as a PHPStan issue.
 */
final readonly class Notice
{
    /**
     * @param list<string> $refs
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $message,
        public ?string $subject = null,
        public array $refs = [],
    ) {
    }

    /**
     * @param callable(string): string $transform
     */
    public function mapStrings(callable $transform): self
    {
        return new self(
            id: $this->id,
            title: $transform($this->title),
            message: $transform($this->message),
            subject: $this->subject === null ? null : $transform($this->subject),
            refs: array_map($transform, $this->refs),
        );
    }

    public function withoutMessage(): self
    {
        return new self($this->id, $this->title, '', $this->subject, $this->refs);
    }

    /**
     * @return array{id:string,title:string,message:string,subject:string|null,refs:list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'message' => $this->message,
            'subject' => $this->subject,
            'refs' => $this->refs,
        ];
    }
}
