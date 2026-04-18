<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Support;

final class PhpstanTipHints
{
    public const PHPDOC_TYPE_ORIGIN_FRAGMENT = 'because the type is coming from a phpdoc';

    public static function stripFormatting(string $value): string
    {
        $plain = preg_replace('/<[^>]+>/', '', $value);
        if (!is_string($plain)) {
            return $value;
        }

        return trim($plain);
    }
}
