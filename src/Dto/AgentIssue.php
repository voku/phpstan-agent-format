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
        public bool $exposeDocblock = false,
        public ?string $docblock = null,
        public bool $exposeRelatedDefinition = false,
        public ?RelatedDefinition $relatedDefinition = null,
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
     *     contextTrace: array{hops: list<array{kind:string,location: array{file: string, line: int}, summary: string, symbol: ?string, ruleIdentifier: ?string}>},
     *     rootCauseSummary: string,
     *     repairStrategySummary: string,
     *     secondaryLocations: list<array{file: string, line: int}>,
     *     docblock?: ?string,
     *     relatedDefinition?: array{file:string,line:int,symbol:string,kind:string,snippet:list<string>,attributes:list<string>}|null
     * }
     */
    public function toArray(): array
    {
        $secondaryLocations = [];
        foreach ($this->secondaryLocations as $location) {
            $secondaryLocations[] = $location->toArray();
        }

        $result = [
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

        if ($this->exposeDocblock) {
            $result['docblock'] = $this->docblock;
        }
        if ($this->exposeRelatedDefinition) {
            $result['relatedDefinition'] = $this->relatedDefinition?->toArray();
        }

        return $result;
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
            exposeDocblock: $this->exposeDocblock,
            docblock: $this->docblock,
            exposeRelatedDefinition: $this->exposeRelatedDefinition,
            relatedDefinition: $this->relatedDefinition,
        );
    }

    public function withoutExtractedContext(): self
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
            secondaryLocations: $this->secondaryLocations,
            exposeDocblock: $this->exposeDocblock,
            docblock: null,
            exposeRelatedDefinition: $this->exposeRelatedDefinition,
            relatedDefinition: null,
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
            exposeDocblock: $this->exposeDocblock,
            docblock: $this->docblock,
            exposeRelatedDefinition: $this->exposeRelatedDefinition,
            relatedDefinition: $this->relatedDefinition,
        );
    }
}
