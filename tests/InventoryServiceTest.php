<?php
declare(strict_types=1);

foreach (['ItemRepository.php', 'ItemProjector.php', 'InventoryService.php', 'ItemBootstrap.php'] as $itemLibrary) {
    $path = __DIR__ . '/../ascii-quest/lib/' . $itemLibrary;
    if (is_file($path)) {
        require_once $path;
    }
}

function inventoryItem(int $id, string $claimedAt = '2026-10-04 12:00:00.000000', array $overrides = []): array
{
    return array_replace([
        'id' => $id,
        'character_id' => 42,
        'definition_key' => 'basic_sword',
        'item_level' => 1,
        'rarity' => 'normal',
        'display_name' => 'Basic Sword ' . $id,
        'snapshot_category' => 'weapon',
        'snapshot_subtype' => 'sword',
        'snapshot_equipment_slot' => 'weapon',
        'snapshot_damage_type' => 'physical',
        'snapshot_damage_min' => 4,
        'snapshot_damage_max' => 7,
        'snapshot_toughness' => 0,
        'snapshot_attack_rate_modifier_bp' => 0,
        'snapshot_cast_rate_modifier_bp' => 0,
        'snapshot_block_rate_modifier_bp' => 0,
        'glyph' => '/',
        'source_type' => 'starter',
        'source_key' => 'initial_weapon',
        'generated_at' => $claimedAt,
        'claimed_at' => $claimedAt,
        'is_active' => 1,
        'request_fingerprint' => str_repeat('a', 64),
    ], $overrides);
}

function inventoryServiceFixture(array $characters, array $items): object
{
    if (!interface_exists('ItemInventoryReader') || !class_exists('InventoryService') || !class_exists('ItemProjector')) {
        throw new RuntimeException('Inventory domain classes must exist.');
    }

    $reader = new class($characters, $items) implements ItemInventoryReader {
        public function __construct(private array $characters, private array $items) {}

        public function ownedCharacter(int $userId, int $characterId): ?array
        {
            $character = $this->characters[$characterId] ?? null;
            return is_array($character) && (int) $character['user_id'] === $userId
                ? $character
                : null;
        }

        public function countOwnedItems(int $userId, int $characterId): int
        {
            if ($this->ownedCharacter($userId, $characterId) === null) {
                return 0;
            }
            return count(array_filter(
                $this->items,
                static fn (array $item): bool => (int) ($item['character_id'] ?? 0) === $characterId,
            ));
        }

        public function ownedItemsPage(int $userId, int $characterId, int $limit, int $offset): array
        {
            if ($this->ownedCharacter($userId, $characterId) === null) {
                return [];
            }
            $owned = array_values(array_filter(
                $this->items,
                static fn (array $item): bool => (int) ($item['character_id'] ?? 0) === $characterId,
            ));
            usort($owned, static function (array $left, array $right): int {
                return [$right['claimed_at'], (int) $right['id']] <=> [$left['claimed_at'], (int) $left['id']];
            });
            return array_slice($owned, $offset, $limit);
        }

        public function definition(string $definitionKey): ?array
        {
            foreach ($this->items as $item) {
                if (($item['definition_key'] ?? null) === $definitionKey) {
                    return $item;
                }
            }
            return null;
        }
    };

    return new InventoryService($reader, new ItemProjector());
}

function recursiveInventoryKeys(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $keys = [];
    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        $keys = array_merge($keys, recursiveInventoryKeys($child));
    }
    return array_values(array_unique($keys));
}

return [
    'Inventory state is scoped to the exact account and Champion' => function (): void {
        $service = inventoryServiceFixture([
            42 => ['id' => 42, 'user_id' => 7, 'life_state' => 'alive', 'encounter_status' => null],
            43 => ['id' => 43, 'user_id' => 7, 'life_state' => 'alive', 'encounter_status' => null],
            99 => ['id' => 99, 'user_id' => 8, 'life_state' => 'alive', 'encounter_status' => null],
        ], [
            inventoryItem(1),
            inventoryItem(2, overrides: ['character_id' => 43]),
            inventoryItem(3, overrides: ['character_id' => 99]),
        ]);

        $state = $service->state(7, 42, 1);
        assertSameValue([1], array_column($state['items'], 'id'), 'Only the selected Champion item is visible.');

        foreach ([[8, 42], [7, 99], [8, 43]] as [$userId, $characterId]) {
            try {
                $service->state($userId, $characterId, 1);
                throw new RuntimeException('Cross-owner inventory access must fail.');
            } catch (OutOfBoundsException) {
            }
        }
    },

    'Inventory state handles empty and strict page bounds' => function (): void {
        $service = inventoryServiceFixture([
            42 => ['id' => 42, 'user_id' => 7, 'life_state' => 'alive', 'encounter_status' => null],
        ], []);
        $state = $service->state(7, 42, 1);
        assertSameValue([], $state['items'], 'Empty inventory items.');
        assertSameValue(['page' => 1, 'per_page' => 25, 'total_items' => 0, 'total_pages' => 1, 'has_previous' => false, 'has_next' => false], $state['pagination'], 'Empty pagination.');

        foreach ([0, -1, 2] as $page) {
            try {
                $service->state(7, 42, $page);
                throw new RuntimeException('Invalid inventory page must fail.');
            } catch (InvalidArgumentException) {
            }
        }
    },

    'Inventory uses 25-item stable newest-claim then descending-ID pages' => function (): void {
        $items = [];
        for ($id = 1; $id <= 26; $id++) {
            $items[] = inventoryItem($id);
        }
        $service = inventoryServiceFixture([
            42 => ['id' => 42, 'user_id' => 7, 'life_state' => 'alive', 'encounter_status' => null],
        ], $items);
        $first = $service->state(7, 42, 1);
        $second = $service->state(7, 42, 2);
        assertSameValue(25, count($first['items']), 'First page size.');
        assertSameValue(range(26, 2), array_column($first['items'], 'id'), 'Stable first page order.');
        assertSameValue([1], array_column($second['items'], 'id'), 'Stable second page order.');
        assertSameValue(true, $first['pagination']['has_next'], 'Next page exists.');
        assertSameValue(true, $second['pagination']['has_previous'], 'Previous page exists.');
    },

    'Inventory remains read only for DEAD and unresolved Champions' => function (): void {
        foreach ([
            ['life_state' => 'dead', 'encounter_status' => null, 'reason' => 'champion_dead'],
            ['life_state' => 'alive', 'encounter_status' => 'active', 'reason' => 'combat_active'],
            ['life_state' => 'alive', 'encounter_status' => 'victory_loot', 'reason' => 'victory_loot_pending'],
        ] as $case) {
            $service = inventoryServiceFixture([
                42 => ['id' => 42, 'user_id' => 7] + $case,
            ], [inventoryItem(1)]);
            $state = $service->state(7, 42, 1);
            assertSameValue([1], array_column($state['items'], 'id'), 'Historical inventory remains readable.');
            assertSameValue(true, $state['equipment_locked'], 'Mutations are locked.');
            assertSameValue($case['reason'], $state['mutation_disabled_reason'], 'Server lock reason.');
        }
    },

    'Item projection is immutable allowlisted and keeps inactive history readable' => function (): void {
        $service = inventoryServiceFixture([
            42 => ['id' => 42, 'user_id' => 7, 'life_state' => 'alive', 'encounter_status' => null],
        ], [inventoryItem(8, overrides: ['is_active' => 0])]);
        $item = $service->state(7, 42, 1)['items'][0];
        assertSameValue(['id', 'display_name', 'rarity', 'item_level', 'base_type', 'category', 'equipment_slot', 'glyph', 'base_stats', 'equipped', 'equipped_slot'], array_keys($item), 'Exact public item fields.');
        assertSameValue(false, $item['equipped'], 'Task 18 items are unequipped.');
        assertSameValue('Basic Sword 8', $item['display_name'], 'Stored display name remains authoritative.');
        foreach (['character_id', 'definition_key', 'source_type', 'source_key', 'generated_at', 'claimed_at', 'is_active', 'request_fingerprint', 'snapshot_damage_min'] as $hidden) {
            assertSameValue(false, in_array($hidden, recursiveInventoryKeys($item), true), $hidden . ' stays private.');
        }
    },

    'Item projector rejects malformed repository rows' => function (): void {
        if (!class_exists('ItemProjector')) {
            throw new RuntimeException('ItemProjector must exist.');
        }
        foreach ([
            inventoryItem(0),
            inventoryItem(1, overrides: ['rarity' => 'legendary']),
            inventoryItem(1, overrides: ['snapshot_category' => '<script>']),
            inventoryItem(1, overrides: ['snapshot_equipment_slot' => 'backpack']),
        ] as $row) {
            try {
                (new ItemProjector())->projectItem($row);
                throw new RuntimeException('Malformed repository item must fail projection.');
            } catch (UnexpectedValueException) {
            }
        }
    },
];
