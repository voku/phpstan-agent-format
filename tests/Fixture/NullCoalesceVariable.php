<?php

function nullCoalesceFixture(string $value): string
{
    return $value ?? 'fallback';
}
