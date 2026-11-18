<?php
declare(strict_types=1);
require_once __DIR__ . '/SkillRepository.php';
require_once __DIR__ . '/SkillService.php';

final class SkillBootstrap
{
    public static function service(PDO $pdo): SkillService
    {
        $repository = new SkillRepository($pdo);
        return new SkillService($repository,$repository->catalogue());
    }
}
