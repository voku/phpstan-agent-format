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

    /**
     * @return array{
     *     id: string,
     *     message: string,
     *     ruleIdentifier: ?string,
     *     location: array{file: string, line: int},
     *     symbolContext: array{className: ?string, methodName: ?string, propertyName: ?string, functionName: ?string, parameterName: ?string, expectedType: ?string, inferredType: ?string, typeOrigin: ?string},
     *     snippet: array{startLine: int, highlightLine: int, lines: list<string>},
     *     contextTrace: array{hops: list<array{location: array{file: string, line: int}, summary: string, symbol: ?string, ruleIdentifier: ?string}>},
     *     rootCauseSummary: string,
     *     repairStrategySummary: string,
     *     secondaryLocations: list<array{file: string, line: int}>
     * }
     */
    public function toArray(): array
    {
        $secondaryLocations = [];
        foreach ($this->secondaryLocations as $location) {
            $secondaryLocations[] = $location->toArray();
        }

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
            'secondaryLocations' => $secondaryLocations,
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
