<?php

interface IntersectionReturnA
{
}

interface IntersectionReturnB
{
}

final class IntersectionReturnOnlyA implements IntersectionReturnA
{
}

function intersectionReturnFixture(): IntersectionReturnA&IntersectionReturnB
{
    return new IntersectionReturnOnlyA();
}
