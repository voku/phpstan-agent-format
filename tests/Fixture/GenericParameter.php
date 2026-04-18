<?php

/**
 * @param array<int, string> $items
 */
function genericParameterFixture(array $items): void
{
}

function genericParameterFixtureCaller(): void
{
    genericParameterFixture(['foo' => 1]);
}
