<?php

final class DuplicateUndefinedMethodTarget
{
}

final class DuplicateUndefinedMethod
{
    public function run(DuplicateUndefinedMethodTarget $value): void
    {
        $value->missingMethod();
        $value->missingMethod();
    }
}
