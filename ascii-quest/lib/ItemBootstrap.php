<?php
declare(strict_types=1);

require_once __DIR__ . '/ItemRepository.php';
require_once __DIR__ . '/ItemProjector.php';
require_once __DIR__ . '/InventoryService.php';

final class ItemBootstrap
{
    public static function service(PDO $pdo): InventoryService
    {
        return new InventoryService(
            new ItemRepository($pdo),
            new ItemProjector(),
        );
    }
}
