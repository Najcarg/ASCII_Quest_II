<?php
declare(strict_types=1);

require_once __DIR__ . '/WarpDefinitionRegistry.php';

final class WarpService
{
    public function __construct(
        private WarpDefinitionRegistry $definitions,
        private object $repository,
    ) {
    }

    public static function isAdjacent(
        int $playerX,
        int $playerY,
        int $warpX,
        int $warpY,
    ): bool {
        return abs($playerX - $warpX) + abs($playerY - $warpY) === 1;
    }

    public function findInteractableWarp(
        string $mapFile,
        int $playerX,
        int $playerY,
    ): ?array {
        $warp = $this->definitions->forMapFile($mapFile);
        if ($warp === null) {
            return null;
        }

        return self::isAdjacent(
            $playerX,
            $playerY,
            (int) $warp['x'],
            (int) $warp['y'],
        ) ? $warp : null;
    }

    public function unlock(int $userId, int $characterId, string $warpId): array
    {
        $character = $this->ownedCharacter($userId, $characterId);
        $warp = $this->definitions->forMapFile(
            (string) $character['current_map_file'],
        );

        if ($warp === null || $warp['id'] !== $warpId) {
            throw new DomainException('That Warp cannot be unlocked here.');
        }

        if (!self::isAdjacent(
            (int) $character['pos_x'],
            (int) $character['pos_y'],
            (int) $warp['x'],
            (int) $warp['y'],
        )) {
            throw new DomainException('Stand directly beside the Warp to unlock it.');
        }

        $newlyUnlocked = $this->repository->unlock($characterId, $warpId);

        return [
            'newly_unlocked' => $newlyUnlocked,
            'message' => $newlyUnlocked
                ? 'Warp unlocked: ' . $warp['name']
                : 'Warp already unlocked: ' . $warp['name'],
            'destinations' => $this->listDestinations($userId, $characterId),
            'character_updates' => [
                'gold' => (int) $character['gold'],
            ],
        ];
    }

    public function listDestinations(int $userId, int $characterId): array
    {
        $character = $this->ownedCharacter($userId, $characterId);
        $destinations = [];

        foreach ($this->repository->unlockedWarpIds($characterId) as $warpId) {
            $warp = $this->definitions->byId((string) $warpId);
            if ($warp === null) {
                continue;
            }

            $currentLocation =
                (string) $character['current_map_file'] === $warp['map_file'];
            $destinations[] = [
                'id' => $warp['id'],
                'name' => $warp['name'],
                'cost' => $warp['cost'],
                'current_location' => $currentLocation,
                'can_travel' => !$currentLocation &&
                    (int) $character['gold'] >= (int) $warp['cost'],
            ];
        }

        return $destinations;
    }

    public function travel(int $userId, int $characterId, string $warpId): array
    {
        $this->repository->beginTransaction();

        try {
            $character = $this->ownedCharacter($userId, $characterId, true);
            if (!$this->repository->hasUnlocked($characterId, $warpId)) {
                throw new DomainException('That Warp has not been unlocked.');
            }

            $warp = $this->definitions->byId($warpId);
            if ($warp === null) {
                throw new DomainException('That Warp destination is unavailable.');
            }

            if ((string) $character['current_map_file'] === $warp['map_file']) {
                $this->repository->commit();

                return [
                    'current_location' => true,
                    'reload' => false,
                    'message' => $warp['name'] . ' is your current location.',
                    'destination' => $warp,
                    'character_updates' => [
                        'gold' => (int) $character['gold'],
                        'current_hp' => (int) $character['current_hp'],
                        'current_mana' => (int) $character['current_mana'],
                    ],
                ];
            }

            $cost = (int) $warp['cost'];
            if ((int) $character['gold'] < $cost) {
                throw new DomainException('Not enough Gold to use that Warp.');
            }

            $targetMap = $this->repository->findMapByKey((string) $warp['map_key']);
            if (
                $targetMap === null ||
                (string) $targetMap['map_file'] !== $warp['map_file']
            ) {
                throw new DomainException('That Warp destination is unavailable.');
            }

            $updated = $this->repository->updateTravel(
                $userId,
                $characterId,
                (int) $targetMap['id'],
                (int) $warp['arrival_x'],
                (int) $warp['arrival_y'],
                $cost,
            );
            if (!$updated) {
                throw new RuntimeException('Warp travel update failed.');
            }

            $this->repository->commit();

            return [
                'current_location' => false,
                'reload' => true,
                'message' => 'Warped to ' . $warp['name'] . '.',
                'destination' => $warp,
                'character_updates' => [
                    'gold' => (int) $character['gold'] - $cost,
                    'current_hp' => (int) $character['current_hp'],
                    'current_mana' => (int) $character['current_mana'],
                ],
            ];
        } catch (Throwable $e) {
            $this->repository->rollBack();
            throw $e;
        }
    }

    private function ownedCharacter(
        int $userId,
        int $characterId,
        bool $forUpdate = false,
    ): array {
        $character = $this->repository->findOwnedCharacter(
            $userId,
            $characterId,
            $forUpdate,
        );
        if ($character === null) {
            throw new OutOfBoundsException('Champion not found.');
        }

        return $character;
    }
}
