<?php

final class UndefinedPropertyTarget
{
}

final class UndefinedProperty
{
    public function run(UndefinedPropertyTarget $value): void
    {
        $this->consume($value->missingProperty);
    }

    private function consume(mixed $value): void
    {
    }
}
