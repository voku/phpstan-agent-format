<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Context;

final class Redactor
{
    /**
     * @var array<string, true>
     */
    private array $warnedPatterns = [];

    /**
     * @param list<string> $patterns
     */
    public function __construct(private readonly array $patterns)
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
                continue;
            }

            $this->warnInvalidPattern($pattern);
        }

        return $result;
    }

    private function warnInvalidPattern(string $pattern): void
    {
        if (isset($this->warnedPatterns[$pattern])) {
            return;
        }

        $this->warnedPatterns[$pattern] = true;
        trigger_error(sprintf('Invalid redact pattern skipped: %s', $pattern), E_USER_WARNING);
    }
}
