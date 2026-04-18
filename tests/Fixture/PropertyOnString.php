<?php

final class PropertyOnString
{
    public function run(): void
    {
        $value = 'abc';
        $this->consume($value->length);
    }

    private function consume(mixed $value): void
    {
    }
}
