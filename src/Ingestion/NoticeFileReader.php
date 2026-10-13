<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Ingestion;

use InvalidArgumentException;
use JsonException;
use Voku\PhpstanAgentFormat\Dto\Notice;

/**
 * Reads notices from `{"notices": [{"id", "title", "message", "subject"?, "refs"?}]}`.
 */
final class NoticeFileReader
{
    /**
     * @return list<Notice>
     */
    public function read(string $path): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Notices file "%s" is not readable.', $path));
        }

        return $this->parse($contents, $path);
    }

    /**
     * @return list<Notice>
     */
    public function parse(string $json, string $source = 'notices'): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(sprintf('Notices file "%s" is not valid JSON: %s', $source, $exception->getMessage()), 0, $exception);
        }

        if (!is_array($decoded) || !isset($decoded['notices']) || !is_array($decoded['notices']) || !array_is_list($decoded['notices'])) {
            throw new InvalidArgumentException(sprintf('Notices file "%s" must contain a "notices" list.', $source));
        }

        $notices = [];
        $ids = [];
        foreach ($decoded['notices'] as $index => $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException(sprintf('Notice #%d in "%s" must be an object.', $index, $source));
            }

            $notice = new Notice(
                id: $this->requiredString($raw, 'id', $index, $source),
                title: $this->requiredString($raw, 'title', $index, $source),
                message: $this->requiredString($raw, 'message', $index, $source),
                subject: $this->optionalString($raw, 'subject', $index, $source),
                refs: $this->refs($raw, $index, $source),
            );

            if (isset($ids[$notice->id])) {
                throw new InvalidArgumentException(sprintf('Duplicate notice id "%s" in "%s".', $notice->id, $source));
            }
            $ids[$notice->id] = true;
            $notices[] = $notice;
        }

        return $notices;
    }

    /**
     * @param array<mixed> $raw
     */
    private function requiredString(array $raw, string $key, int $index, string $source): string
    {
        $value = $raw[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Notice #%d in "%s" needs a non-empty string "%s".', $index, $source, $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $raw
     */
    private function optionalString(array $raw, string $key, int $index, string $source): ?string
    {
        if (!isset($raw[$key])) {
            return null;
        }

        return $this->requiredString($raw, $key, $index, $source);
    }

    /**
     * @param array<mixed> $raw
     * @return list<string>
     */
    private function refs(array $raw, int $index, string $source): array
    {
        $refs = $raw['refs'] ?? [];
        if (!is_array($refs) || !array_is_list($refs)) {
            throw new InvalidArgumentException(sprintf('Notice #%d in "%s" needs "refs" to be a list of strings.', $index, $source));
        }

        foreach ($refs as $ref) {
            if (!is_string($ref)) {
                throw new InvalidArgumentException(sprintf('Notice #%d in "%s" needs "refs" to be a list of strings.', $index, $source));
            }
        }

        /** @var list<string> $refs */
        return $refs;
    }
}
