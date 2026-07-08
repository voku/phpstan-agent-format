<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Context;

use RuntimeException;
use Voku\PhpstanAgentFormat\Config\AgentFormatConfig;
use Voku\PhpstanAgentFormat\Dto\RelatedDefinition;
use Voku\PhpstanAgentFormat\Dto\SymbolContext;

final readonly class RelatedDefinitionExtractor
{
    public function __construct(private AgentFormatConfig $config)
    {
    }

    public function extract(string $file, int $line, SymbolContext $symbolContext): ?RelatedDefinition
    {
        if (!is_file($file)) {
            return null;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            throw new RuntimeException(sprintf('Could not read file for related definition extraction: %s', $file));
        }

        $candidates = $this->candidatePatterns($symbolContext);
        foreach ($candidates as $candidate) {
            for ($i = 1, $count = count($lines); $i <= $count; $i++) {
                $sourceLine = (string) $lines[$i - 1];
                if (preg_match($candidate['pattern'], $sourceLine) !== 1) {
                    continue;
                }

                return new RelatedDefinition(
                    file: $file,
                    line: $i,
                    symbol: $candidate['symbol'],
                    kind: $candidate['kind'],
                    snippet: [$this->redact($this->compactDeclaration($lines, $i))],
                );
            }
        }

        $nearest = $this->nearestDeclaration($lines, $line);
        if ($nearest === null) {
            return null;
        }

        return new RelatedDefinition(
            file: $file,
            line: $nearest['line'],
            symbol: $nearest['symbol'],
            kind: $nearest['kind'],
            snippet: [$this->redact($this->compactDeclaration($lines, $nearest['line']))],
        );
    }

    /**
     * @return list<array{kind:string,symbol:string,pattern:string}>
     */
    private function candidatePatterns(SymbolContext $context): array
    {
        $candidates = [];
        if ($context->methodName !== null) {
            $name = $this->shortName($context->methodName);
            $candidates[] = ['kind' => 'method', 'symbol' => $context->methodName, 'pattern' => '/^\s*(?:(?:public|protected|private|static|abstract|final)\s+)*function\s+' . preg_quote($name, '/') . '\s*\(/'];
        }
        if ($context->functionName !== null) {
            $name = $this->shortName($context->functionName);
            $candidates[] = ['kind' => 'function', 'symbol' => $context->functionName, 'pattern' => '/^\s*function\s+' . preg_quote($name, '/') . '\s*\(/'];
        }
        if ($context->propertyName !== null) {
            $candidates[] = ['kind' => 'property', 'symbol' => '$' . $context->propertyName, 'pattern' => '/^\s*(?:(?:public|protected|private|static|readonly)\s+)+[^;{]*\$' . preg_quote($context->propertyName, '/') . '\b/'];
        }
        if ($context->className !== null) {
            $name = $this->shortName($context->className);
            $candidates[] = ['kind' => 'class', 'symbol' => $context->className, 'pattern' => '/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+' . preg_quote($name, '/') . '\b/'];
        }

        return $candidates;
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

    /**
     * @param list<string> $lines
     */
    private function compactDeclaration(array $lines, int $line): string
    {
        $parts = [];
        for ($i = $line, $count = count($lines); $i <= $count && count($parts) < 6; $i++) {
            $parts[] = trim((string) $lines[$i - 1]);
            $joined = trim(implode(' ', $parts));
            if (str_contains($joined, '{')) {
                return trim(substr($joined, 0, (int) strpos($joined, '{') + 1));
            }
            if (str_contains($joined, ';')) {
                return trim(substr($joined, 0, (int) strpos($joined, ';') + 1));
            }
        }
        return trim(implode(' ', $parts));
    }

    /**
     * @param list<string> $lines
     * @return array{line:int,kind:string,symbol:string}|null
     */
    private function nearestDeclaration(array $lines, int $line): ?array
    {
        for ($i = min(count($lines), $line); $i >= 1; $i--) {
            $text = (string) $lines[$i - 1];
            if (preg_match('/^\s*(?:(?:public|protected|private|static|abstract|final)\s+)*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $text, $m) === 1) {
                return ['line' => $i, 'kind' => 'method', 'symbol' => $m[1]];
            }
            if (preg_match('/^\s*(?:(?:abstract|final|readonly)\s+)*(class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)\b/', $text, $m) === 1) {
                return ['line' => $i, 'kind' => $m[1], 'symbol' => $m[2]];
            }
        }
        return null;
    }

    private function redact(string $value): string
    {
        $result = $value;
        foreach ($this->config->redactPatterns as $pattern) {
            $replaced = @preg_replace('/' . str_replace('/', '\\/', $pattern) . '/', '[REDACTED]', $result);
            if (is_string($replaced)) {
                $result = $replaced;
                continue;
            }
            $replaced = @preg_replace($pattern, '[REDACTED]', $result);
            if (is_string($replaced)) {
                $result = $replaced;
            }
        }
        return $result;
    }
}
