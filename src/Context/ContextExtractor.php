<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Context;

use RuntimeException;
use Voku\PhpstanAgentFormat\Config\AgentFormatConfig;
use Voku\PhpstanAgentFormat\Dto\CodeSnippet;

final readonly class ContextExtractor
{
    public function __construct(
        private AgentFormatConfig $config,
        private ?Redactor $redactor = null,
    ) {
    }

    public function extractSnippet(string $file, int $line): CodeSnippet
    {
        if (!is_file($file)) {
            return new CodeSnippet(max(1, $line), max(1, $line), []);
        }

        $content = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($content)) {
            throw new RuntimeException(sprintf('Could not read file for snippet extraction: %s', $file));
        }

        $start = max(1, $line - $this->config->snippetLinesBefore);
        $end = min(count($content), $line + $this->config->snippetLinesAfter);
        $lines = [];

        for ($i = $start; $i <= $end; $i++) {
            $lineContent = (string) ($content[$i - 1] ?? '');
            $lines[] = $this->redactor()->redact($lineContent);
        }

        return new CodeSnippet($start, max(1, $line), $lines);
    }

    private function redactor(): Redactor
    {
        return $this->redactor ?? new Redactor($this->config->redactPatterns);
    }
}
