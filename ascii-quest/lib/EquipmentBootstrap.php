<?php
declare(strict_types=1);

require_once __DIR__ . '/EquipmentRepository.php';
require_once __DIR__ . '/EquipmentService.php';
require_once __DIR__ . '/EquipmentStatAggregator.php';

final class EquipmentBootstrap
{
    public static function service(PDO $pdo): EquipmentService
    {
        return new EquipmentService(
            new EquipmentRepository($pdo),
            new EquipmentStatAggregator(),
        );
    }
}
