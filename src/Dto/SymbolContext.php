<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Dto;

final readonly class SymbolContext
{
    public function __construct(
        public ?string $className,
        public ?string $methodName,
        public ?string $propertyName,
        public ?string $functionName,
        public ?string $parameterName,
        public ?string $expectedType,
        public ?string $inferredType,
        public ?string $typeOrigin,
    ) {
    }

    /**
     * @return array{
     *     className:?string,
     *     methodName:?string,
     *     propertyName:?string,
     *     functionName:?string,
     *     parameterName:?string,
     *     expectedType:?string,
     *     inferredType:?string,
     *     typeOrigin:?string
     * }
     */
    public function toArray(): array
    {
        return [
            'className' => $this->className,
            'methodName' => $this->methodName,
            'propertyName' => $this->propertyName,
            'functionName' => $this->functionName,
            'parameterName' => $this->parameterName,
            'expectedType' => $this->expectedType,
            'inferredType' => $this->inferredType,
            'typeOrigin' => $this->typeOrigin,
        ];
    }
}
