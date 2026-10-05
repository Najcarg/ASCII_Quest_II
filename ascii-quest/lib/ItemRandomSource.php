<?php
declare(strict_types=1);

interface ItemRandomSource
{
    public function integer(int $minimum, int $maximum): int;
}
