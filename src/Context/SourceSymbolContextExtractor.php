<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Context;

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
        $method = $classLike !== null ? $this->bestByLine($classLike->methods, $file, $line) : null;
        $property = $classLike !== null ? $this->bestByLine($classLike->properties, $file, $line) : null;
        $function = $this->bestByLine($container->getFunctions(), $file, $line);
        $constant = $classLike !== null
            ? $this->bestByLine($classLike->constants, $file, $line)
            : $this->bestByLine($container->getConstants(), $file, $line);

        $className = is_string($classLike?->name) && $classLike->name !== '' ? $classLike->name : null;
        $methodName = null;
        if (is_string($method?->name) && $method->name !== '') {
            $methodName = $className !== null ? $className . '::' . $method->name : $method->name;
        }

        $propertyName = is_string($property?->name) && $property->name !== '' ? ltrim($property->name, '$') : null;
        $functionName = is_string($function?->name) && $function->name !== '' ? $function->name : null;
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

    private function bestClassLike(ParserContainer $container, string $file, int $line): ?object
    {
        $best = null;
        $bestLine = -1;

        foreach ([$container->getClasses(), $container->getTraits(), $container->getInterfaces(), $container->getEnums()] as $classLikes) {
            $candidate = $this->bestByLine($classLikes, $file, $line);
            if ($candidate === null || !is_int($candidate->line) || $candidate->line < $bestLine) {
                continue;
            }

            $best = $candidate;
            $bestLine = $candidate->line;
        }

        return $best;
    }

    /**
     * @param array<array-key, object> $elements
     */
    private function bestByLine(array $elements, string $file, int $line): ?object
    {
        $best = null;
        $bestLine = -1;

        foreach ($elements as $element) {
            $elementLine = is_int($element->line ?? null) ? $element->line : null;
            if ($elementLine === null || $elementLine > $line) {
                continue;
            }

            $elementFile = is_string($element->file ?? null) ? $element->file : null;
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

    private function resolveParameterName(?object $method, ?object $function, ?string $preferredParameter, int $line): ?string
    {
        if ($preferredParameter !== null && $preferredParameter !== '') {
            return ltrim($preferredParameter, '$');
        }

        foreach ([$method, $function] as $callable) {
            $parameters = is_array($callable?->parameters ?? null) ? $callable->parameters : [];
            if (count($parameters) !== 1) {
                continue;
            }

            $callableLine = is_int($callable->line ?? null) ? $callable->line : null;
            if ($callableLine === null || $callableLine !== $line) {
                continue;
            }

            $parameter = array_values($parameters)[0];
            if (is_string($parameter->name ?? null) && $parameter->name !== '') {
                return ltrim($parameter->name, '$');
            }
        }

        return null;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function resolveExpectedType(
        ?object $method,
        ?object $function,
        ?object $property,
        ?object $constant,
        ?string $parameterName,
        ?string $propertyName,
    ): array {
        if ($parameterName !== null) {
            $parameter = $this->findParameter($method, $parameterName) ?? $this->findParameter($function, $parameterName);
            if ($parameter !== null) {
                return [$this->bestTypeFromElement($parameter), $this->typeOriginFromElement($parameter)];
            }
        }

        if ($propertyName !== null && $property !== null && ltrim((string) ($property->name ?? ''), '$') === $propertyName) {
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

    private function findParameter(?object $callable, string $parameterName): ?object
    {
        $parameters = is_array($callable?->parameters ?? null) ? $callable->parameters : [];
        $normalizedTarget = ltrim($parameterName, '$');

        foreach ($parameters as $parameter) {
            if (!is_string($parameter->name ?? null)) {
                continue;
            }

            if (ltrim($parameter->name, '$') === $normalizedTarget) {
                return $parameter;
            }
        }

        return null;
    }

    private function bestTypeFromElement(object $element): ?string
    {
        if (method_exists($element, 'getType')) {
            $type = $element->getType();
            if (is_string($type) && $type !== '') {
                return $type;
            }
        }

        return null;
    }

    private function bestCallableReturnType(object $callable): ?string
    {
        if (method_exists($callable, 'getReturnType')) {
            $type = $callable->getReturnType();
            if (is_string($type) && $type !== '') {
                return $type;
            }
        }

        return null;
    }

    private function bestTypeFromConstant(object $constant): ?string
    {
        $declarationType = is_string($constant->typeFromDeclaration ?? null) ? $constant->typeFromDeclaration : null;
        if ($declarationType !== null && $declarationType !== '') {
            return $declarationType;
        }

        $type = is_string($constant->type ?? null) ? $constant->type : null;
        if ($type !== null && $type !== '') {
            return $type;
        }

        return null;
    }

    private function typeOriginFromElement(object $element): ?string
    {
        foreach (['typeFromPhpDocExtended', 'typeFromPhpDoc', 'typeFromPhpDocSimple'] as $property) {
            if (is_string($element->{$property} ?? null) && $element->{$property} !== '') {
                return 'phpdoc';
            }
        }

        if (is_string($element->type ?? null) && $element->type !== '') {
            return 'native';
        }

        return null;
    }

    private function typeOriginFromCallable(object $callable): ?string
    {
        foreach (['returnTypeFromPhpDocExtended', 'returnTypeFromPhpDoc', 'returnTypeFromPhpDocSimple'] as $property) {
            if (is_string($callable->{$property} ?? null) && $callable->{$property} !== '') {
                return 'phpdoc';
            }
        }

        if (is_string($callable->returnType ?? null) && $callable->returnType !== '') {
            return 'native';
        }

        return null;
    }
}
