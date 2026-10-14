<?php

declare(strict_types=1);

namespace PHPStan\Collectors;

if (class_exists(CollectedData::class, false)) {
    return;
}

final class CollectedData
{
    public function __construct(
        private mixed $data,
        private string $filePath,
        private string $collectorType,
    ) {
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getCollectorType(): string
    {
        return $this->collectorType;
    }
}
