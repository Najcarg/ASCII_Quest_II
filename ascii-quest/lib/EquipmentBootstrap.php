<?php
declare(strict_types=1);

require_once __DIR__ . '/EquipmentRepository.php';
require_once __DIR__ . '/EquipmentService.php';
require_once __DIR__ . '/EquipmentStatAggregator.php';
require_once __DIR__ . '/CharacterStats.php';

final class EquipmentBootstrap
{
    public static function service(PDO $pdo): EquipmentService
    {
        return new EquipmentService(
            new EquipmentRepository($pdo),
            new EquipmentStatAggregator(),
        );
    }

    public static function stats(PDO $pdo, array $character, bool $locked = false): array
    {
        $repository = new EquipmentRepository($pdo);
        $items = $locked
            ? $repository->lockEquippedItems((int) $character['id'])
            : $repository->readEquippedItems((int) $character['id']);
        return CharacterStats::calculate(
            $character,
            (new EquipmentStatAggregator())->aggregate($items),
        );
    }

    public static function publicCharacterState(PDO $pdo, int $userId, int $characterId): array
    {
        $repository = new EquipmentRepository($pdo);
        $character = $repository->ownedCharacterForStats($userId, $characterId);
        if ($character === null) {
            throw new OutOfBoundsException('Champion unavailable.');
        }
        return [
            'current_hp' => (int) $character['current_hp'],
            'current_mana' => (int) $character['current_mana'],
            'stats' => self::stats($pdo, $character),
        ];
    }
}
