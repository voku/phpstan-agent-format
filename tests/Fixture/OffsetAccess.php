<?php

final class OffsetAccess
{
    public function run(string $value): void
    {
        $this->consume($value['foo']);
    }

    private function consume(mixed $value): void
    {
    }
}
