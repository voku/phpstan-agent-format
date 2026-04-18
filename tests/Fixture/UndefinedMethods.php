<?php

final class UndefinedMethodsTarget
{
}

final class UndefinedMethods
{
    public function run(UndefinedMethodsTarget $value): void
    {
        $value->missingMethod();
        UndefinedMethodsTarget::missingStatic();
    }
}
