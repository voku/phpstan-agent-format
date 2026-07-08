<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Context;

use RuntimeException;
use Throwable;
use Voku\PhpstanAgentFormat\Dto\SymbolContext;
use voku\SimplePhpParser\Model\BasePHPClass;
use voku\SimplePhpParser\Model\PHPConst;
use voku\SimplePhpParser\Model\PHPFunction;
use voku\SimplePhpParser\Model\PHPProperty;
use voku\SimplePhpParser\Parsers\PhpCodeParser;
use voku\SimplePhpParser\Parsers\Helper\ParserContainer;

final class PhpSymbolScanner
{
    private const MAX_CACHE = 50;

    /**
     * @var array<string, ParserContainer>
     */
    private array $cache = [];

    /**
     * @return array{file:string,line:int,symbol:string,kind:string}|null
     */
    public function findNearestDeclaration(string $file, int $line): ?array
    {
        $declarations = $this->declarations($file);
        $nearest = null;

        foreach ($declarations as $declaration) {
            if ($declaration['line'] > $line) {
                continue;
            }
            if ($nearest === null || $declaration['line'] > $nearest['line']) {
                $nearest = $declaration;
            }
        }

        return $nearest;
    }

    /**
     * @return array{file:string,line:int,symbol:string,kind:string}|null
     */
    public function findRelatedDeclaration(string $file, int $line, SymbolContext $symbolContext): ?array
    {
        $declarations = $this->declarations($file);
        $candidates = $this->candidateSymbols($symbolContext);

        foreach ($candidates as $candidate) {
            $best = null;
            foreach ($declarations as $declaration) {
                if ($declaration['kind'] !== $candidate['kind']) {
                    continue;
                }

                if (!$this->symbolMatches($declaration['symbol'], $candidate['symbol'])) {
                    continue;
                }

                if ($best === null || abs($declaration['line'] - $line) < abs($best['line'] - $line)) {
                    $best = $declaration;
                }
            }

            if ($best !== null) {
                return $best;
            }
        }

        return $this->findNearestDeclaration($file, $line);
    }

    private function parserContainer(string $file): ParserContainer
    {
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Could not scan PHP symbols because file does not exist: %s', $file));
        }

        $realPath = realpath($file) ?: $file;
        if (isset($this->cache[$realPath])) {
            $container = $this->cache[$realPath];
            unset($this->cache[$realPath]);

            return $this->cache[$realPath] = $container;
        }

        try {
            $container = PhpCodeParser::getPhpFiles($realPath);
        } catch (Throwable $throwable) {
            throw new RuntimeException(sprintf('Could not scan PHP symbols in file: %s', $file), 0, $throwable);
        }

        if (count($this->cache) >= self::MAX_CACHE) {
            array_shift($this->cache);
        }

        return $this->cache[$realPath] = $container;
    }

    /**
     * @return list<array{file:string,line:int,symbol:string,kind:string}>
     */
    private function declarations(string $file): array
    {
        $container = $this->parserContainer($file);
        $declarations = [];

        foreach ($container->getFunctions() as $function) {
            $declarations[] = $this->declarationFromFunction($function, 'function');
        }

        foreach ($container->getClasses() as $class) {
            $this->appendClassLikeDeclarations($declarations, $class, 'class');
        }
        foreach ($container->getInterfaces() as $interface) {
            $this->appendClassLikeDeclarations($declarations, $interface, 'interface');
        }
        foreach ($container->getTraits() as $trait) {
            $this->appendClassLikeDeclarations($declarations, $trait, 'trait');
        }
        foreach ($container->getEnums() as $enum) {
            $this->appendClassLikeDeclarations($declarations, $enum, 'enum');
        }
        foreach ($container->getConstants() as $constant) {
            $declarations[] = $this->declarationFromConstant($constant, $file, null);
        }

        usort($declarations, static function (array $a, array $b): int {
            return [$a['line'], $a['kind'], $a['symbol']] <=> [$b['line'], $b['kind'], $b['symbol']];
        });

        return array_values(array_filter(
            $declarations,
            static fn (array $declaration): bool => $declaration['line'] > 0,
        ));
    }

    /**
     * @param list<array{file:string,line:int,symbol:string,kind:string}> $declarations
     */
    private function appendClassLikeDeclarations(array &$declarations, BasePHPClass $class, string $kind): void
    {
        $className = $this->shortName($class->name);
        $classFile = $class->file ?? '';
        $declarations[] = [
            'file' => $classFile,
            'line' => $class->line ?? 0,
            'symbol' => $class->name,
            'kind' => $kind,
        ];

        foreach ($class->methods as $method) {
            $declarations[] = $this->declarationFromFunction($method, 'method', $className);
        }
        foreach ($class->properties as $property) {
            $declarations[] = $this->declarationFromProperty($property, $classFile, $className);
        }
        foreach ($class->constants as $constant) {
            $declarations[] = $this->declarationFromConstant($constant, $classFile, $className);
        }
    }

    /**
     * @return array{file:string,line:int,symbol:string,kind:string}
     */
    private function declarationFromFunction(PHPFunction $function, string $kind, ?string $className = null): array
    {
        return [
            'file' => $function->file ?? '',
            'line' => $function->line ?? 0,
            'symbol' => $className !== null ? $className . '::' . $function->name : $function->name,
            'kind' => $kind,
        ];
    }

    /**
     * @return array{file:string,line:int,symbol:string,kind:string}
     */
    private function declarationFromProperty(PHPProperty $property, string $classFile, string $className): array
    {
        return [
            'file' => $property->file ?? $classFile,
            'line' => $property->line ?? 0,
            'symbol' => $className . '::$' . $property->name,
            'kind' => 'property',
        ];
    }

    /**
     * @return array{file:string,line:int,symbol:string,kind:string}
     */
    private function declarationFromConstant(PHPConst $constant, string $classFile, ?string $className): array
    {
        return [
            'file' => $constant->file ?? $classFile,
            'line' => $constant->line ?? 0,
            'symbol' => $className !== null ? $className . '::' . $constant->name : $constant->name,
            'kind' => 'constant',
        ];
    }

    /**
     * @return list<array{kind:string,symbol:string}>
     */
    private function candidateSymbols(SymbolContext $context): array
    {
        $candidates = [];
        if ($context->methodName !== null) {
            $method = $context->methodName;
            $symbol = $context->className !== null && !str_contains($method, '::')
                ? $context->className . '::' . $method
                : $method;
            $candidates[] = ['kind' => 'method', 'symbol' => $symbol];
        }
        if ($context->functionName !== null) {
            $candidates[] = ['kind' => 'function', 'symbol' => $context->functionName];
        }
        if ($context->propertyName !== null) {
            $property = ltrim($context->propertyName, '$');
            $candidates[] = ['kind' => 'property', 'symbol' => $context->className !== null ? $context->className . '::$' . $property : '$' . $property];
        }
        if ($context->className !== null) {
            foreach (['class', 'interface', 'trait', 'enum'] as $kind) {
                $candidates[] = ['kind' => $kind, 'symbol' => $context->className];
            }
        }

        return $candidates;
    }

    private function symbolMatches(string $actual, string $expected): bool
    {
        if ($actual === $expected) {
            return true;
        }

        if (str_contains($actual, '::') && str_contains($expected, '::')) {
            [$actualClass, $actualMember] = explode('::', $actual, 2);
            [$expectedClass, $expectedMember] = explode('::', $expected, 2);

            return $this->shortName($actualClass) === $this->shortName($expectedClass)
                && $this->shortName($actualMember) === $this->shortName($expectedMember);
        }

        return $this->shortName($actual) === $this->shortName($expected);
    }

    private function shortName(string $symbol): string
    {
        $symbol = trim($symbol);
        if (str_contains($symbol, '::')) {
            $parts = explode('::', $symbol);
            return end($parts) ?: $symbol;
        }
        if (str_contains($symbol, '\\')) {
            $parts = explode('\\', $symbol);
            return end($parts) ?: $symbol;
        }

        return ltrim($symbol, '$');
    }
}
