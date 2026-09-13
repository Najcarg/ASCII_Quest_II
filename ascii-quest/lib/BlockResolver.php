<?php
declare(strict_types=1);

interface BlockResolver
{
    public function resolve(
        array $incomingAction,
        array $currentDefense,
        bool $attempted,
    ): array;
}
