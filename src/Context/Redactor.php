<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Context;

final readonly class Redactor
{
    /**
     * @param list<string> $patterns
     */
    public function __construct(private array $patterns)
    {
    }

    public function redact(string $value): string
    {
        $result = $value;
        foreach ($this->patterns as $pattern) {
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
