<?php
declare(strict_types=1);

$starterPath = __DIR__ . '/../ascii-quest/lib/StarterEquipmentService.php';
if (is_file($starterPath)) {
    require_once $starterPath;
}

final class FakeStarterStore
{
    public array $characters;
    public array $items = [];
    public array $equipment = [];
    public ?string $encounterStatus = null;
    public int $nextItemId = 1;
    public bool $failItem = false;
    public bool $failEquipment = false;
    private ?string $snapshot = null;

    public function __construct(array $character)
    {
        $this->characters[(int) $character['id']] = $character;
    }

    public function beginMutation(): void { $this->snapshot = serialize([$this->characters, $this->items, $this->equipment]); }
    public function commitMutation(): void { $this->snapshot = null; }
    public function rollBackMutation(): void
    {
        if ($this->snapshot !== null) {
            [$this->characters, $this->items, $this->equipment] = unserialize($this->snapshot);
            $this->snapshot = null;
        }
    }
    public function lockOwnedCharacterForStarter(int $userId, int $characterId): ?array
    {
        $row = $this->characters[$characterId] ?? null;
        return is_array($row) && (int) $row['user_id'] === $userId ? $row : null;
    }
    public function lockAccountCombatMutex(int $userId): void {}
    public function lockUnresolvedEncounter(int $characterId): ?array
    {
        return $this->encounterStatus === null ? null : ['status' => $this->encounterStatus];
    }
    public function starterItem(int $characterId): ?array
    {
        foreach ($this->items as $item) {
            if ((int) $item['character_id'] === $characterId && $item['source_type'] === 'starter' && $item['source_key'] === 'initial_weapon') {
                return $item;
            }
        }
        return null;
    }
    public function equippedUsableWeapon(int $characterId): ?array
    {
        $itemId = $this->equipment['weapon'] ?? null;
        $item = $itemId === null ? null : ($this->items[$itemId] ?? null);
        return is_array($item) && $item['snapshot_equipment_slot'] === 'weapon' ? $item : null;
    }
    public function ownedUsableWeapon(int $characterId): ?array
    {
        foreach ($this->items as $item) {
            if ((int) $item['character_id'] === $characterId && $item['snapshot_equipment_slot'] === 'weapon') {
                return $item;
            }
        }
        return null;
    }
    public function starterDefinition(string $key): ?array
    {
        return [
            'definition_key' => $key, 'display_name' => ucwords(str_replace('_', ' ', $key)),
            'category' => 'weapon', 'subtype' => substr($key, 6), 'equipment_slot' => 'weapon',
            'damage_type' => $key === 'basic_wand' ? 'fire' : 'physical',
            'base_damage_min' => 3, 'base_damage_max' => 7, 'base_toughness' => 0,
            'base_attack_rate_modifier_bp' => 0, 'base_cast_rate_modifier_bp' => 0,
            'base_block_rate_modifier_bp' => 0, 'glyph' => '/', 'is_active' => 1,
        ];
    }
    public function createStarterItem(int $characterId, array $definition, string $now): array
    {
        if ($this->failItem) { throw new RuntimeException('Injected item failure.'); }
        $item = [
            'id' => $this->nextItemId++, 'character_id' => $characterId,
            'definition_key' => $definition['definition_key'], 'item_level' => 1,
            'rarity' => 'normal', 'display_name' => $definition['display_name'],
            'snapshot_category' => 'weapon', 'snapshot_subtype' => $definition['subtype'],
            'snapshot_equipment_slot' => 'weapon', 'snapshot_damage_type' => $definition['damage_type'],
            'snapshot_damage_min' => $definition['base_damage_min'], 'snapshot_damage_max' => $definition['base_damage_max'],
            'snapshot_toughness' => 0, 'snapshot_attack_rate_modifier_bp' => 0,
            'snapshot_cast_rate_modifier_bp' => 0, 'snapshot_block_rate_modifier_bp' => 0,
            'glyph' => '/', 'source_type' => 'starter', 'source_key' => 'initial_weapon',
            'claimed_at' => $now, 'affixes' => [],
        ];
        $this->items[$item['id']] = $item;
        return $item;
    }
    public function equipStarterWeapon(int $characterId, int $itemId): void
    {
        if ($this->failEquipment) { throw new RuntimeException('Injected equipment failure.'); }
        if (isset($this->equipment['weapon']) && $this->equipment['weapon'] !== $itemId) {
            throw new DomainException('Weapon slot is occupied.');
        }
        $this->equipment['weapon'] = $itemId;
    }
}

function starterService(FakeStarterStore $store): object
{
    if (!class_exists('StarterEquipmentService')) {
        throw new RuntimeException('StarterEquipmentService must exist.');
    }
    return new StarterEquipmentService($store);
}

return [
    'Starter mapping creates one persistent equipped item for all four classes' => function (): void {
        $expected = ['warrior' => 'basic_sword', 'mage' => 'basic_wand', 'rogue' => 'basic_dagger', 'cleric' => 'basic_mace'];
        foreach ($expected as $classKey => $definitionKey) {
            $character = equipmentCharacter(['class_key' => $classKey]);
            $store = new FakeStarterStore($character);
            $result = starterService($store)->grantForNewLockedChampion($character, $classKey);
            $item = array_values($store->items)[0] ?? null;
            assertSameValue($definitionKey, $item['definition_key'] ?? null, $classKey . ' mapping.');
            assertSameValue('normal', $item['rarity'], 'Normal rarity.');
            assertSameValue(1, $item['item_level'], 'Level one.');
            assertSameValue([], $item['affixes'], 'No affixes.');
            assertSameValue('starter', $item['source_type'], 'Starter provenance.');
            assertSameValue('initial_weapon', $item['source_key'], 'Stable provenance key.');
            assertSameValue($item['id'], $store->equipment['weapon'], 'Owned starter is equipped.');
            assertSameValue('granted', $result['result'], 'Grant result.');
        }
    },

    'Starter grant retry converges on one provenance row and one weapon slot' => function (): void {
        $character = equipmentCharacter(['class_key' => 'warrior']);
        $store = new FakeStarterStore($character);
        $service = starterService($store);
        $first = $service->grantForNewLockedChampion($character, 'warrior');
        $second = $service->grantForNewLockedChampion($character, 'warrior');
        assertSameValue(1, count($store->items), 'One starter item.');
        assertSameValue(1, count($store->equipment), 'One equipment row.');
        assertSameValue($first['item_id'], $second['item_id'], 'Retry returns same item.');
    },

    'Existing bootstrap is preview first and preserves owned weapon policy' => function (): void {
        $character = equipmentCharacter(['class_key' => 'warrior']);
        $store = new FakeStarterStore($character);
        $service = starterService($store);
        $preview = $service->bootstrapExisting(7, 42, false);
        assertSameValue('would_grant', $preview['result'], 'Preview reports intent.');
        assertSameValue([], $store->items, 'Preview creates nothing.');
        $apply = $service->bootstrapExisting(7, 42, true);
        assertSameValue('granted', $apply['result'], 'Apply grants.');
        assertSameValue(1, count($store->items), 'One apply item.');
        assertSameValue(100, $store->characters[42]['current_hp'], 'HP is preserved.');
        assertSameValue(100, $store->characters[42]['current_mana'], 'Mana is preserved.');
        assertSameValue('unchanged', $service->bootstrapExisting(7, 42, true)['result'], 'Repeated apply is unchanged.');

        $ownedStore = new FakeStarterStore($character);
        $ownedStore->items[9] = equipmentItem(9, 'weapon');
        assertSameValue('owned_weapon_available', starterService($ownedStore)->bootstrapExisting(7, 42, true)['result'], 'Owned usable weapon prevents starter creation.');
        assertSameValue(1, count($ownedStore->items), 'No starter was added.');

        $equippedStore = new FakeStarterStore($character);
        $equippedStore->items[9] = equipmentItem(9, 'weapon');
        $equippedStore->equipment['weapon'] = 9;
        assertSameValue('unchanged', starterService($equippedStore)->bootstrapExisting(7, 42, true)['result'], 'Equipped weapon is untouched.');
    },

    'Existing bootstrap skips DEAD and defers unresolved combat' => function (): void {
        $deadStore = new FakeStarterStore(equipmentCharacter(['class_key' => 'mage', 'life_state' => 'dead']));
        assertSameValue('skipped_dead', starterService($deadStore)->bootstrapExisting(7, 42, true)['result'], 'DEAD skip.');
        assertSameValue([], $deadStore->items, 'DEAD receives nothing.');
        foreach (['active', 'victory_loot'] as $status) {
            $store = new FakeStarterStore(equipmentCharacter(['class_key' => 'mage']));
            $store->encounterStatus = $status;
            assertSameValue('deferred_combat', starterService($store)->bootstrapExisting(7, 42, true)['result'], $status . ' defer.');
            assertSameValue([], $store->items, 'Deferred Champion receives nothing.');
        }
    },

    'Existing bootstrap failure rolls back its per Champion transaction' => function (): void {
        $store = new FakeStarterStore(equipmentCharacter(['class_key' => 'rogue']));
        $store->failEquipment = true;
        $service = starterService($store);
        try {
            $service->bootstrapExisting(7, 42, true);
        } catch (RuntimeException) {
            assertSameValue([], $store->items, 'Item insert rolled back.');
            assertSameValue([], $store->equipment, 'Equipment insert rolled back.');
            return;
        }
        throw new RuntimeException('Bootstrap failure must escape.');
    },

    'Character creation invokes starter grant before its transaction commit' => function (): void {
        $source = file_get_contents(__DIR__ . '/../ascii-quest/create_character.php');
        if (!is_string($source)) { throw new RuntimeException('Character creation must be readable.'); }
        $grant = strpos($source, 'grantForNewLockedChampion');
        $commit = strpos($source, '$pdo->commit()', $grant === false ? 0 : $grant);
        if ($grant === false || $commit === false || $grant > $commit) {
            throw new RuntimeException('Starter grant must occur inside creation transaction before commit.');
        }
        if (!str_contains($source, '$pdo->rollBack()')) {
            throw new RuntimeException('Creation must roll back starter failures.');
        }
    },
];
