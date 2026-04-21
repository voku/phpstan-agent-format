<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Context;

use voku\SimplePhpParser\Model\BasePHPClass;
use voku\SimplePhpParser\Model\BasePHPElement;
use voku\SimplePhpParser\Model\PHPConst;
use voku\SimplePhpParser\Model\PHPFunction;
use voku\SimplePhpParser\Model\PHPParameter;
use voku\SimplePhpParser\Model\PHPProperty;
use voku\SimplePhpParser\Parsers\Helper\ParserContainer;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

final class SourceSymbolContextExtractor
{
    /**
     * @var array<string, ParserContainer|null>
     */
    private array $parsedFiles = [];

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
    public function extract(string $file, int $line, ?string $preferredParameter = null, ?string $preferredProperty = null): array
    {
        $container = $this->parseFile($file);
        if ($container === null) {
            return $this->emptyContext();
        }

        $line = max(1, $line);
        $classLike = $this->bestClassLike($container, $file, $line);
        $methodCandidate = $classLike !== null ? $this->bestByLine($classLike->methods, $file, $line) : null;
        $propertyCandidate = $classLike !== null ? $this->bestByLine($classLike->properties, $file, $line) : null;
        $functionCandidate = $this->bestByLine($container->getFunctions(), $file, $line);
        $constantCandidate = $classLike !== null
            ? $this->bestByLine($classLike->constants, $file, $line)
            : $this->bestByLine($container->getConstants(), $file, $line);

        $method = $methodCandidate instanceof PHPFunction ? $methodCandidate : null;
        $property = $propertyCandidate instanceof PHPProperty ? $propertyCandidate : null;
        $function = $functionCandidate instanceof PHPFunction ? $functionCandidate : null;
        $constant = $constantCandidate instanceof PHPConst ? $constantCandidate : null;

        $className = $classLike?->name !== '' ? $classLike?->name : null;
        $methodName = null;
        if ($method?->name !== null && $method->name !== '') {
            $methodName = $className !== null ? $className . '::' . $method->name : $method->name;
        }

        $propertyName = $property?->name !== null && $property->name !== '' ? ltrim($property->name, '$') : null;
        $functionName = $function?->name !== null && $function->name !== '' ? $function->name : null;
        $parameterName = $this->resolveParameterName($method, $function, $preferredParameter, $line);

        $resolvedProperty = $preferredProperty !== null && $preferredProperty !== '' ? $preferredProperty : $propertyName;
        [$expectedType, $typeOrigin] = $this->resolveExpectedType(
            $method,
            $function,
            $property,
            $constant,
            $parameterName,
            $resolvedProperty,
        );

        return [
            'className' => $className,
            'methodName' => $methodName,
            'propertyName' => $resolvedProperty,
            'functionName' => $functionName,
            'parameterName' => $parameterName,
            'expectedType' => $expectedType,
            'inferredType' => null,
            'typeOrigin' => $typeOrigin,
        ];
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
    private function emptyContext(): array
    {
        return [
            'className' => null,
            'methodName' => null,
            'propertyName' => null,
            'functionName' => null,
            'parameterName' => null,
            'expectedType' => null,
            'inferredType' => null,
            'typeOrigin' => null,
        ];
    }

    private function parseFile(string $file): ?ParserContainer
    {
        if (!is_file($file)) {
            return null;
        }

        if (array_key_exists($file, $this->parsedFiles)) {
            return $this->parsedFiles[$file];
        }

        try {
            $this->parsedFiles[$file] = PhpCodeParser::getPhpFiles($file);
        } catch (\Throwable) {
            $this->parsedFiles[$file] = null;
        }

        return $this->parsedFiles[$file];
    }

    private function bestClassLike(ParserContainer $container, string $file, int $line): ?BasePHPClass
    {
        $best = null;
        $bestLine = -1;

        foreach ([$container->getClasses(), $container->getTraits(), $container->getInterfaces(), $container->getEnums()] as $classLikes) {
            $candidate = $this->bestByLine($classLikes, $file, $line);
            if ($candidate === null || $candidate->line === null || $candidate->line < $bestLine) {
                continue;
            }

            $best = $candidate;
            $bestLine = $candidate->line;
        }

        return $best;
    }

    /**
     * @template T of BasePHPElement
     * @param array<array-key, T> $elements
     * @return T|null
     */
    private function bestByLine(array $elements, string $file, int $line): ?BasePHPElement
    {
        $best = null;
        $bestLine = -1;

        foreach ($elements as $element) {
            $elementLine = $element->line;
            if ($elementLine === null || $elementLine > $line) {
                continue;
            }

            $elementFile = $element->file;
            if ($elementFile !== null && !$this->sameFile($elementFile, $file)) {
                continue;
            }

            if ($elementLine < $bestLine) {
                continue;
            }

            $best = $element;
            $bestLine = $elementLine;
        }

        return $best;
    }

    private function sameFile(string $left, string $right): bool
    {
        $normalizedLeft = realpath($left) ?: $left;
        $normalizedRight = realpath($right) ?: $right;

        return $normalizedLeft === $normalizedRight;
    }

    private function resolveParameterName(?PHPFunction $method, ?PHPFunction $function, ?string $preferredParameter, int $line): ?string
    {
        if ($preferredParameter !== null && $preferredParameter !== '') {
            return ltrim($preferredParameter, '$');
        }

        foreach ([$method, $function] as $callable) {
            if ($callable === null) {
                continue;
            }

            $parameters = $callable->parameters;
            if (count($parameters) !== 1) {
                continue;
            }

            $callableLine = $callable->line;
            if ($callableLine === null || $callableLine !== $line) {
                continue;
            }

            /** @var PHPParameter $parameter */
            $parameter = array_values($parameters)[0];
            if ($parameter->name !== '') {
                return ltrim($parameter->name, '$');
            }
        }

        return null;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function resolveExpectedType(
        ?PHPFunction $method,
        ?PHPFunction $function,
        ?PHPProperty $property,
        ?PHPConst $constant,
        ?string $parameterName,
        ?string $propertyName,
    ): array {
        if ($parameterName !== null) {
            $parameter = $this->findParameter($method, $parameterName) ?? $this->findParameter($function, $parameterName);
            if ($parameter !== null) {
                return [$this->bestTypeFromElement($parameter), $this->typeOriginFromElement($parameter)];
            }
        }

        if ($propertyName !== null && $property !== null && ltrim($property->name, '$') === $propertyName) {
            return [$this->bestTypeFromElement($property), $this->typeOriginFromElement($property)];
        }

        if ($method !== null) {
            return [$this->bestCallableReturnType($method), $this->typeOriginFromCallable($method)];
        }

        if ($function !== null) {
            return [$this->bestCallableReturnType($function), $this->typeOriginFromCallable($function)];
        }

        if ($constant !== null) {
            return [$this->bestTypeFromConstant($constant), null];
        }

        return [null, null];
    }

    private function findParameter(?PHPFunction $callable, string $parameterName): ?PHPParameter
    {
        if ($callable === null) {
            return null;
        }

        $parameters = $callable->parameters;
        $normalizedTarget = ltrim($parameterName, '$');

        foreach ($parameters as $parameter) {
            if (ltrim($parameter->name, '$') === $normalizedTarget) {
                return $parameter;
            }
        }

        return null;
    }

    private function bestTypeFromElement(PHPParameter|PHPProperty $element): ?string
    {
        $type = $element->getType();
        if ($type !== null && $type !== '') {
            return $type;
        }

        return null;
    }

    private function bestCallableReturnType(PHPFunction $callable): ?string
    {
        $type = $callable->getReturnType();
        if ($type !== null && $type !== '') {
            return $type;
        }

        return null;
    }

    private function bestTypeFromConstant(PHPConst $constant): ?string
    {
        $declarationType = $constant->typeFromDeclaration;
        if ($declarationType !== null && $declarationType !== '') {
            return $declarationType;
        }

        $type = $constant->type;
        if ($type !== null && $type !== '') {
            return $type;
        }

        return null;
    }

    private function typeOriginFromElement(PHPParameter|PHPProperty $element): ?string
    {
        foreach (['typeFromPhpDocExtended', 'typeFromPhpDoc', 'typeFromPhpDocSimple'] as $property) {
            $value = $element->{$property};
            if (is_string($value) && $value !== '') {
                return 'phpdoc';
            }
        }

        if ($element->type !== null && $element->type !== '') {
            return 'native';
        }

        return null;
    }

    private function typeOriginFromCallable(PHPFunction $callable): ?string
    {
        foreach (['returnTypeFromPhpDocExtended', 'returnTypeFromPhpDoc', 'returnTypeFromPhpDocSimple'] as $property) {
            $value = $callable->{$property};
            if (is_string($value) && $value !== '') {
                return 'phpdoc';
            }
        }

        if ($callable->returnType !== null && $callable->returnType !== '') {
            return 'native';
        }

        return null;
    }
}
