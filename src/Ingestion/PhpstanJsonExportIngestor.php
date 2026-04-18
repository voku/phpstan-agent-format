<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Ingestion;

use InvalidArgumentException;

final class PhpstanJsonExportIngestor
{
    /**
     * @param array<string, mixed>|string $payload
     * @return array{fileSpecificErrors:list<object>,notFileSpecificErrors:list<string>}
     */
    public function ingest(array|string $payload): array
    {
        $decoded = is_string($payload)
            ? json_decode($payload, true, 512, JSON_THROW_ON_ERROR)
            : $payload;

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('PHPStan JSON export must decode to an object-like array.');
        }

        /** @var array<string, mixed> $decoded */
        $fileSpecificErrors = [];
        $files = $decoded['files'] ?? [];
        if (is_array($files)) {
            foreach ($files as $file => $entry) {
                if (!is_string($file) || !is_array($entry)) {
                    continue;
                }

                $messages = $entry['messages'] ?? [];
                if (!is_array($messages)) {
                    continue;
                }

                foreach ($messages as $message) {
                    if (!is_array($message) || !isset($message['message']) || !is_string($message['message']) || $message['message'] === '') {
                        continue;
                    }

                    $line = isset($message['line']) && is_int($message['line']) ? $message['line'] : 1;
                    $identifier = isset($message['identifier']) && is_string($message['identifier']) && $message['identifier'] !== '' ? $message['identifier'] : null;
                    $tip = isset($message['tip']) && is_string($message['tip']) && $message['tip'] !== '' ? $message['tip'] : null;
                    $metadata = isset($message['metadata']) && is_array($message['metadata']) ? $this->normalizeMetadata($message['metadata']) : [];
                    $nodeLine = isset($message['nodeLine']) && is_int($message['nodeLine']) ? $message['nodeLine'] : null;
                    $nodeType = isset($message['nodeType']) && is_string($message['nodeType']) && $message['nodeType'] !== '' ? $message['nodeType'] : null;
                    $traitFilePath = isset($message['traitFilePath']) && is_string($message['traitFilePath']) && $message['traitFilePath'] !== '' ? $message['traitFilePath'] : null;

                    $fileSpecificErrors[] = new ImportedPhpstanError(
                        message: $message['message'],
                        file: $file,
                        line: max(1, $line),
                        identifier: $identifier,
                        tip: $tip,
                        metadata: $metadata,
                        nodeLine: $nodeLine,
                        nodeType: $nodeType,
                        traitFilePath: $traitFilePath,
                    );
                }
            }
        }

        $notFileSpecificErrors = [];
        $errors = $decoded['errors'] ?? [];
        if (is_array($errors)) {
            foreach ($errors as $error) {
                if (is_string($error) && $error !== '') {
                    $notFileSpecificErrors[] = $error;
                }
            }
        }

        return [
            'fileSpecificErrors' => $fileSpecificErrors,
            'notFileSpecificErrors' => $notFileSpecificErrors,
        ];
    }

    /**
     * @param array<mixed> $metadata
     * @return array<string, mixed>
     */
    private function normalizeMetadata(array $metadata): array
    {
        $normalized = [];

        foreach ($metadata as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (is_array($value)) {
                $normalized[$key] = $this->normalizeMetadata($value);
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
