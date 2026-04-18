<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Support;

final class MetadataNormalizer
{
    /**
     * @param array<mixed> $metadata
     * @return array<array-key, mixed>
     */
    public static function normalize(array $metadata): array
    {
        $normalized = [];

        foreach ($metadata as $key => $value) {
            if (is_string($key) && $key === '') {
                continue;
            }

            if (is_array($value)) {
                $normalized[$key] = self::normalize($value);
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
