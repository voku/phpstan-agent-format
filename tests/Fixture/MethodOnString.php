<?php

final class MethodOnString
{
    public function run(): void
    {
        $value = 'abc';
        $value->trim();
    }
}
