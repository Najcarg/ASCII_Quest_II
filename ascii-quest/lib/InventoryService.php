<?php
declare(strict_types=1);

final class InventoryService
{
    public const PAGE_SIZE = 25;

    public function __construct(
        private ItemInventoryReader $repository,
        private ItemProjector $projector,
    ) {}

    public function state(int $userId, int $characterId, int $page): array
    {
        if ($userId < 1 || $characterId < 1) {
            throw new OutOfBoundsException('Champion unavailable.');
        }
        if ($page < 1) {
            throw new InvalidArgumentException('Inventory page must be positive.');
        }

        $character = $this->repository->ownedCharacter($userId, $characterId);
        if ($character === null) {
            throw new OutOfBoundsException('Champion unavailable.');
        }

        $totalItems = $this->repository->countOwnedItems($userId, $characterId);
        $totalPages = max(1, (int) ceil($totalItems / self::PAGE_SIZE));
        if ($page > $totalPages) {
            throw new InvalidArgumentException('Inventory page is out of bounds.');
        }

        $rows = $this->repository->ownedItemsPage(
            $userId,
            $characterId,
            self::PAGE_SIZE,
            ($page - 1) * self::PAGE_SIZE,
        );
        $items = array_map(
            fn (array $row): array => $this->projector->projectItem($row),
            $rows,
        );
        [$locked, $reason] = $this->mutationLock($character);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => self::PAGE_SIZE,
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'has_previous' => $page > 1,
                'has_next' => $page < $totalPages,
            ],
            'equipment_locked' => $locked,
            'mutation_disabled_reason' => $reason,
        ];
    }

    private function mutationLock(array $character): array
    {
        if (($character['life_state'] ?? null) === 'dead') {
            return [true, 'champion_dead'];
        }
        return match ($character['encounter_status'] ?? null) {
            'active' => [true, 'combat_active'],
            'victory_loot' => [true, 'victory_loot_pending'],
            default => [false, null],
        };
    }
}
