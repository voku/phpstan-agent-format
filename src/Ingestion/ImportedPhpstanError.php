<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Ingestion;

final readonly class ImportedPhpstanError
{
    /**
     * @param array<array-key, mixed> $metadata
     */
    public function __construct(
        private string $message,
        private string $file,
        private int $line,
        private ?string $identifier = null,
        private ?string $tip = null,
        private array $metadata = [],
        private ?int $nodeLine = null,
        private ?string $nodeType = null,
        private ?string $traitFilePath = null,
    ) {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function getFilePath(): string
    {
        return $this->file;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function getTip(): ?string
    {
        return $this->tip;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getNodeLine(): ?int
    {
        return $this->nodeLine;
    }

    public function getNodeType(): ?string
    {
        return $this->nodeType;
    }

    public function getTraitFilePath(): ?string
    {
        return $this->traitFilePath;
    }
}
