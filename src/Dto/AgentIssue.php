<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Dto;

final readonly class AgentIssue
{
    /**
     * @param list<FileLocation> $secondaryLocations
     */
    public function __construct(
        public string $id,
        public string $message,
        public ?string $ruleIdentifier,
        public FileLocation $location,
        public SymbolContext $symbolContext,
        public CodeSnippet $snippet,
        public ContextTrace $contextTrace,
        public FixHint $fixHint,
        public array $secondaryLocations = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'message' => $this->message,
            'ruleIdentifier' => $this->ruleIdentifier,
            'location' => $this->location->toArray(),
            'symbolContext' => $this->symbolContext->toArray(),
            'snippet' => $this->snippet->toArray(),
            'contextTrace' => $this->contextTrace->toArray(),
            'rootCauseSummary' => $this->fixHint->rootCauseSummary,
            'repairStrategySummary' => $this->fixHint->repairStrategySummary,
            'secondaryLocations' => array_map(static fn (FileLocation $location): array => $location->toArray(), $this->secondaryLocations),
        ];
    }

    /**
     * @param list<FileLocation> $secondaryLocations
     */
    public function withSecondaryLocations(array $secondaryLocations): self
    {
        return new self(
            id: $this->id,
            message: $this->message,
            ruleIdentifier: $this->ruleIdentifier,
            location: $this->location,
            symbolContext: $this->symbolContext,
            snippet: $this->snippet,
            contextTrace: $this->contextTrace,
            fixHint: $this->fixHint,
            secondaryLocations: $secondaryLocations,
        );
    }

    public function withSnippet(CodeSnippet $snippet): self
    {
        return new self(
            id: $this->id,
            message: $this->message,
            ruleIdentifier: $this->ruleIdentifier,
            location: $this->location,
            symbolContext: $this->symbolContext,
            snippet: $snippet,
            contextTrace: $this->contextTrace,
            fixHint: $this->fixHint,
            secondaryLocations: $this->secondaryLocations,
        );
    }
}
