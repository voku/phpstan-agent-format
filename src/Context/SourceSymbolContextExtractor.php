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
     * Parse the source file at `$file` and return symbol context for the given `$line`.
     *
     * Results are used as a low-priority fallback when PHPStan's error message and metadata
     * do not already supply class/method/function/parameter/type information.
     *
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
        $empty = ['className' => null, 'methodName' => null, 'propertyName' => null,
                  'functionName' => null, 'parameterName' => null, 'expectedType' => null,
                  'inferredType' => null, 'typeOrigin' => null];

        if (!array_key_exists($file, $this->parsedFiles)) {
            try {
                $this->parsedFiles[$file] = is_file($file) ? PhpCodeParser::getPhpFiles($file) : null;
            } catch (\Throwable) {
                $this->parsedFiles[$file] = null;
            }
        }

        $container = $this->parsedFiles[$file];
        if ($container === null) {
            return $empty;
        }

        $line = max(1, $line);
        $realFile = realpath($file) ?: $file;

        // Find the deepest class that starts at or before the error line.
        $bestClass = null;
        $bestClassLine = -1;
        foreach ($container->getClasses() as $class) {
            if ($class->line === null || $class->line > $line) {
                continue;
            }
            if ($class->file !== null && (realpath($class->file) ?: $class->file) !== $realFile) {
                continue;
            }
            if ($class->line >= $bestClassLine) {
                $bestClass = $class;
                $bestClassLine = $class->line;
            }
        }

        $className = $bestClass !== null ? $bestClass->name : null;

        // Build callable candidates: methods when inside a class, free functions otherwise.
        $candidates = $bestClass !== null ? array_values($bestClass->methods) : [];
        if ($bestClass === null) {
            foreach ($container->getFunctions() as $fn) {
                if ($fn->file !== null && (realpath($fn->file) ?: $fn->file) !== $realFile) {
                    continue;
                }
                $candidates[] = $fn;
            }
        }

        // Pick the deepest callable at or before the error line.
        $bestCallable = null;
        $bestCallableLine = -1;
        foreach ($candidates as $callable) {
            if ($callable->line === null || $callable->line > $line) {
                continue;
            }
            if ($callable->line >= $bestCallableLine) {
                $bestCallable = $callable;
                $bestCallableLine = $callable->line;
            }
        }

        $isMethod = $bestCallable !== null && $bestClass !== null;
        $methodName = $isMethod && $bestCallable->name !== ''
            ? ($className !== null ? $className . '::' . $bestCallable->name : $bestCallable->name)
            : null;
        $functionName = !$isMethod && $bestCallable !== null && $bestCallable->name !== ''
            ? $bestCallable->name
            : null;

        // Resolve expected type and its origin from the callable's parameter or return type.
        $paramName = $preferredParameter !== null ? ltrim($preferredParameter, '$') : null;
        $expectedType = null;
        $typeOrigin = null;

        if ($bestCallable !== null) {
            if ($paramName !== null && isset($bestCallable->parameters[$paramName])) {
                $param = $bestCallable->parameters[$paramName];
                $expectedType = $param->getType();
                $typeOrigin = ($param->typeFromPhpDocExtended || $param->typeFromPhpDoc || $param->typeFromPhpDocSimple)
                    ? 'phpdoc' : ($param->type !== null ? 'native' : null);
            } else {
                $expectedType = $bestCallable->getReturnType();
                $typeOrigin = ($bestCallable->returnTypeFromPhpDocExtended || $bestCallable->returnTypeFromPhpDoc)
                    ? 'phpdoc' : ($bestCallable->returnType !== null ? 'native' : null);
            }
        }

        $propertyName = $preferredProperty !== null && $preferredProperty !== ''
            ? ltrim($preferredProperty, '$')
            : null;

        return [
            'className' => $className,
            'methodName' => $methodName,
            'propertyName' => $propertyName,
            'functionName' => $functionName,
            'parameterName' => $paramName,
            'expectedType' => $expectedType,
            'inferredType' => null,
            'typeOrigin' => $typeOrigin,
        ];
    }
}
