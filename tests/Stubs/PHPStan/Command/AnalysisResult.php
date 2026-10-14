<?php

declare(strict_types=1);

namespace PHPStan\Command;

if (class_exists(AnalysisResult::class, false)) {
    return;
}

final class AnalysisResult
{
    /**
     * @param list<object> $fileSpecificErrors
     * @param list<object|string> $notFileSpecificErrors
     */
    public function __construct(
        private array $fileSpecificErrors,
        private array $notFileSpecificErrors = [],
    ) {
    }

    /**
     * @return list<object>
     */
    public function getFileSpecificErrors(): array
    {
        return $this->fileSpecificErrors;
    }

    /**
     * @return list<object|string>
     */
    public function getNotFileSpecificErrors(): array
    {
        return $this->notFileSpecificErrors;
    }

    public function hasErrors(): bool
    {
        return $this->fileSpecificErrors !== [] || $this->notFileSpecificErrors !== [];
    }
}
