<?php
declare(strict_types=1);
require_once __DIR__.'/PassiveRepository.php';
require_once __DIR__.'/PassiveService.php';
require_once __DIR__.'/PassiveStatAggregator.php';

final class PassiveBootstrap
{
    public static function service(PDO $pdo): PassiveService
    {
        $repository=new PassiveRepository($pdo);
        return new PassiveService($repository,$repository->catalogue());
    }
    public static function champion(PDO $pdo,int $user,int $id): array
    {
        require_once __DIR__.'/EquipmentBootstrap.php';
        return EquipmentBootstrap::publicCharacterState($pdo,$user,$id);
    }
    public static function modifiers(PDO $pdo,int $id): array
    {
        $repository=new PassiveRepository($pdo);
        $unlocks=$repository->unlocks($id);
        if ($unlocks===[]) { return []; }
        return (new PassiveStatAggregator())->aggregate($repository->catalogue(),$unlocks);
    }
}
