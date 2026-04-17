<?php

class SampleService
{
    public function run(?string $name): int
    {
        $password = 'super-secret';

        return strlen($name);
    }
}
