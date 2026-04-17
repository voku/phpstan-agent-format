<?php

declare(strict_types=1);

namespace PHPStan\Command;

if (class_exists(Output::class, false)) {
    return;
}

final class Output
{
    public string $buffer = '';

    public function writeRaw(string $message): void
    {
        $this->buffer .= $message;
    }
}
