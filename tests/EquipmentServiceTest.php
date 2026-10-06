<?php
declare(strict_types=1);

foreach (['EquipmentStatAggregator.php', 'EquipmentService.php'] as $equipmentLibrary) {
    $path = __DIR__ . '/../ascii-quest/lib/' . $equipmentLibrary;
    if (is_file($path)) {
        require_once $path;
    }
}

final class FakeEquipmentStore
{
    public array $characters;
    public array $items;
    public array $equipment;
    public array $requests = [];
    public ?string $encounterStatus = null;
    public bool $failWrite = false;
    private ?string $snapshot = null;

    public function __construct(array $character, array $items = [], array $equipment = [])
    {
        $this->characters = [(int) $character['id'] => $character];
        $this->items = [];
        foreach ($items as $item) {
            $this->items[(int) $item['id']] = $item;
        }
        $this->equipment = $equipment;
    }

    public function beginMutation(): void
    {
        $this->snapshot = serialize([$this->characters, $this->items, $this->equipment, $this->requests]);
    }

    public function commitMutation(): void
    {
        $this->snapshot = null;
    }

    public function rollBackMutation(): void
    {
        if ($this->snapshot !== null) {
            [$this->characters, $this->items, $this->equipment, $this->requests] = unserialize($this->snapshot);
            $this->snapshot = null;
        }
    }

    public function lockOwnedCharacterForEquipment(int $userId, int $characterId): ?array
    {
        $row = $this->characters[$characterId] ?? null;
        return is_array($row) && (int) $row['user_id'] === $userId ? $row : null;
    }

    public function lockAccountCombatMutex(int $userId): void {}

    public function lockUnresolvedEncounter(int $characterId): ?array
    {
        return $this->encounterStatus === null ? null : ['status' => $this->encounterStatus];
    }

    public function lockMutationRequest(int $characterId, string $token): ?array
    {
        return $this->requests[$characterId . ':' . $token] ?? null;
    }

    public function createEquipmentRequest(int $characterId, string $token, string $command, string $fingerprint, int $itemId, ?string $sourceSlot, ?string $targetSlot): void
    {
        $this->requests[$characterId . ':' . $token] = [
            'command_type' => $command,
            'request_fingerprint' => $fingerprint,
            'item_id' => $itemId,
            'source_slot' => $sourceSlot,
            'target_slot' => $targetSlot,
            'result_payload' => null,
            'completed_at' => null,
        ];
    }

    public function completeEquipmentRequest(int $characterId, string $token, array $payload, string $completedAt): void
    {
        $key = $characterId . ':' . $token;
        $this->requests[$key]['result_payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->requests[$key]['completed_at'] = $completedAt;
    }

    public function lockOwnedItem(int $characterId, int $itemId): ?array
    {
        $item = $this->items[$itemId] ?? null;
        return is_array($item) && (int) $item['character_id'] === $characterId ? $item : null;
    }

    public function lockEquippedItems(int $characterId): array
    {
        $rows = [];
        foreach ($this->equipment as $slot => $itemId) {
            $item = $this->items[$itemId];
            $item['equipment_slot'] = $slot;
            $rows[] = $item;
        }
        return $rows;
    }

    public function equipItem(int $characterId, int $itemId, string $slot): ?int
    {
        if ($this->failWrite) {
            throw new RuntimeException('Injected equipment write failure.');
        }
        $displaced = $this->equipment[$slot] ?? null;
        foreach ($this->equipment as $otherSlot => $equippedId) {
            if ($equippedId === $itemId && $otherSlot !== $slot) {
                throw new DomainException('Item is already equipped elsewhere.');
            }
        }
        $this->equipment[$slot] = $itemId;
        return $displaced;
    }

    public function unequipItem(int $characterId, int $itemId): ?string
    {
        if ($this->failWrite) {
            throw new RuntimeException('Injected equipment write failure.');
        }
        foreach ($this->equipment as $slot => $equippedId) {
            if ($equippedId === $itemId) {
                unset($this->equipment[$slot]);
                return $slot;
            }
        }
        return null;
    }

    public function clampResources(int $characterId, int $currentHp, int $currentMana): void
    {
        $this->characters[$characterId]['current_hp'] = $currentHp;
        $this->characters[$characterId]['current_mana'] = $currentMana;
    }
}

function equipmentCharacter(array $overrides = []): array
{
    return array_replace([
        'id' => 42, 'user_id' => 7, 'life_state' => 'alive',
        'strength' => 5, 'dexterity' => 5, 'vitality' => 5, 'energy' => 5, 'fate' => 5,
        'current_hp' => 100, 'current_mana' => 100,
    ], $overrides);
}

function equipmentItem(int $id, string $slot, array $affixes = [], int $characterId = 42): array
{
    return [
        'id' => $id, 'character_id' => $characterId,
        'snapshot_equipment_slot' => $slot,
        'snapshot_toughness' => 0,
        'snapshot_attack_rate_modifier_bp' => 0,
        'snapshot_cast_rate_modifier_bp' => 0,
        'snapshot_block_rate_modifier_bp' => 0,
        'affixes' => $affixes,
    ];
}

function equipmentService(FakeEquipmentStore $store): object
{
    if (!class_exists('EquipmentService')) {
        throw new RuntimeException('EquipmentService must exist.');
    }
    return new EquipmentService($store, new EquipmentStatAggregator());
}

function equipmentToken(int $suffix): string
{
    return sprintf('00000000-0000-4000-8000-%012d', $suffix);
}

return [
    'Equipment accepts every authoritative compatible slot and rejects a wrong slot' => function (): void {
        $slots = ['helm', 'gloves', 'chest', 'ring', 'weapon', 'off-hand', 'amulet', 'belt', 'charm', 'boots'];
        foreach ($slots as $index => $slot) {
            $item = equipmentItem($index + 1, $slot);
            $store = new FakeEquipmentStore(equipmentCharacter(), [$item]);
            $result = equipmentService($store)->equip(7, 42, $index + 1, $slot, equipmentToken($index + 1));
            assertSameValue($index + 1, $store->equipment[$slot], $slot . ' item equipped.');
            assertSameValue('equipped', $result['result'], $slot . ' result.');
        }

        $store = new FakeEquipmentStore(equipmentCharacter(), [equipmentItem(20, 'ring')]);
        try {
            equipmentService($store)->equip(7, 42, 20, 'charm', equipmentToken(20));
        } catch (DomainException) {
            assertSameValue([], $store->equipment, 'Wrong slot changes nothing.');
            return;
        }
        throw new RuntimeException('Wrong-slot equip must fail.');
    },

    'Equipment enforces exact Champion ownership' => function (): void {
        $store = new FakeEquipmentStore(equipmentCharacter(), [equipmentItem(1, 'weapon', characterId: 99)]);
        foreach ([[8, 42], [7, 99], [7, 42]] as [$userId, $characterId]) {
            try {
                equipmentService($store)->equip($userId, $characterId, 1, 'weapon', equipmentToken($userId + $characterId));
                throw new RuntimeException('Foreign equipment mutation must fail.');
            } catch (OutOfBoundsException|DomainException) {
            }
        }
        assertSameValue([], $store->equipment, 'No foreign item is equipped.');
    },

    'Equipment occupied slot swap is atomic and unequip removes only the requested item' => function (): void {
        $store = new FakeEquipmentStore(equipmentCharacter(), [equipmentItem(1, 'weapon'), equipmentItem(2, 'weapon')], ['weapon' => 1]);
        $service = equipmentService($store);
        $swap = $service->equip(7, 42, 2, 'weapon', equipmentToken(30));
        assertSameValue(2, $store->equipment['weapon'], 'New item occupies slot.');
        assertSameValue(1, $swap['displaced_item_id'], 'Displaced item remains owned and reported.');
        $unequip = $service->unequip(7, 42, 2, equipmentToken(31));
        assertSameValue([], $store->equipment, 'Requested item is unequipped.');
        assertSameValue('weapon', $unequip['slot'], 'Actual slot is reported.');

        try {
            $service->unequip(7, 42, 1, equipmentToken(32));
        } catch (DomainException) {
            return;
        }
        throw new RuntimeException('Stale unequip must fail.');
    },

    'Equipment mutation UUID replay is stable and token collision is rejected' => function (): void {
        $store = new FakeEquipmentStore(equipmentCharacter(), [equipmentItem(1, 'weapon'), equipmentItem(2, 'weapon')]);
        $service = equipmentService($store);
        $token = equipmentToken(40);
        $first = $service->equip(7, 42, 1, 'weapon', $token);
        assertSameValue($first, $service->equip(7, 42, 1, 'weapon', $token), 'Same intent replays stable payload.');
        try {
            $service->equip(7, 42, 2, 'weapon', $token);
        } catch (DomainException) {
            assertSameValue(1, $store->equipment['weapon'], 'Collision cannot swap equipment.');
            return;
        }
        throw new RuntimeException('Different payload with the same token must collide.');
    },

    'Two equipment service instances serialize competing same-slot swaps' => function (): void {
        $store = new FakeEquipmentStore(
            equipmentCharacter(),
            [equipmentItem(1, 'weapon'), equipmentItem(2, 'weapon')],
        );
        $first = equipmentService($store)->equip(7, 42, 1, 'weapon', equipmentToken(44));
        $second = equipmentService($store)->equip(7, 42, 2, 'weapon', equipmentToken(45));
        assertSameValue(null, $first['displaced_item_id'], 'First serialized equip sees an empty slot.');
        assertSameValue(1, $second['displaced_item_id'], 'Second serialized equip observes and displaces the first.');
        assertSameValue(['weapon' => 2], $store->equipment, 'Exactly one item owns the slot.');
        assertSameValue(2, count($store->items), 'Both items remain Champion-owned.');
    },

    'Equipment rejects DEAD active and victory loot mutations but permits closed state' => function (): void {
        foreach ([['dead', null], ['alive', 'active'], ['alive', 'victory_loot']] as $index => [$lifeState, $status]) {
            $store = new FakeEquipmentStore(equipmentCharacter(['life_state' => $lifeState]), [equipmentItem(1, 'weapon')]);
            $store->encounterStatus = $status;
            try {
                equipmentService($store)->equip(7, 42, 1, 'weapon', equipmentToken(50 + $index));
                throw new RuntimeException('Locked equipment mutation must fail.');
            } catch (DomainException) {
                assertSameValue([], $store->equipment, 'Locked state is unchanged.');
            }
        }
        $store = new FakeEquipmentStore(equipmentCharacter(), [equipmentItem(1, 'weapon')]);
        $store->encounterStatus = 'closed';
        equipmentService($store)->equip(7, 42, 1, 'weapon', equipmentToken(59));
        assertSameValue(1, $store->equipment['weapon'], 'Closed encounter permits mutation.');
    },

    'Equipment maximum increases do not refill Life or Mana' => function (): void {
        $item = equipmentItem(1, 'ring', [
            ['modifier_type' => 'maximum_life', 'modifier_operation' => 'flat', 'rolled_value' => 100],
            ['modifier_type' => 'maximum_mana', 'modifier_operation' => 'flat', 'rolled_value' => 100],
        ]);
        $store = new FakeEquipmentStore(equipmentCharacter(['current_hp' => 37, 'current_mana' => 41]), [$item]);
        equipmentService($store)->equip(7, 42, 1, 'ring', equipmentToken(60));
        assertSameValue(37, $store->characters[42]['current_hp'], 'Life is not refilled.');
        assertSameValue(41, $store->characters[42]['current_mana'], 'Mana is not refilled.');
    },

    'Equipment maximum decreases clamp down once without underflow' => function (): void {
        $item = equipmentItem(1, 'ring', [
            ['modifier_type' => 'maximum_life', 'modifier_operation' => 'flat', 'rolled_value' => 100],
            ['modifier_type' => 'maximum_mana', 'modifier_operation' => 'flat', 'rolled_value' => 100],
        ]);
        $store = new FakeEquipmentStore(equipmentCharacter(['current_hp' => 240, 'current_mana' => 230]), [$item], ['ring' => 1]);
        equipmentService($store)->unequip(7, 42, 1, equipmentToken(70));
        assertSameValue(150, $store->characters[42]['current_hp'], 'Life clamps to final maximum.');
        assertSameValue(175, $store->characters[42]['current_mana'], 'Mana clamps to final maximum.');

        $store = new FakeEquipmentStore(equipmentCharacter(['current_hp' => 0, 'current_mana' => 0]), [$item], ['ring' => 1]);
        equipmentService($store)->unequip(7, 42, 1, equipmentToken(71));
        assertSameValue(0, $store->characters[42]['current_hp'], 'Zero Life stays zero.');
        assertSameValue(0, $store->characters[42]['current_mana'], 'Zero Mana stays zero.');
    },

    'Equipment resource-item swap clamps against only the final equipped item' => function (): void {
        $large = equipmentItem(1, 'ring', [
            ['modifier_type' => 'maximum_life', 'modifier_operation' => 'flat', 'rolled_value' => 100],
            ['modifier_type' => 'maximum_mana', 'modifier_operation' => 'flat', 'rolled_value' => 100],
        ]);
        $small = equipmentItem(2, 'ring', [
            ['modifier_type' => 'maximum_life', 'modifier_operation' => 'flat', 'rolled_value' => 10],
            ['modifier_type' => 'maximum_mana', 'modifier_operation' => 'flat', 'rolled_value' => 5],
        ]);
        $store = new FakeEquipmentStore(
            equipmentCharacter(['current_hp' => 240, 'current_mana' => 230]),
            [$large, $small],
            ['ring' => 1],
        );
        equipmentService($store)->equip(7, 42, 2, 'ring', equipmentToken(72));
        assertSameValue(160, $store->characters[42]['current_hp'], 'Life clamps to final swapped maximum.');
        assertSameValue(180, $store->characters[42]['current_mana'], 'Mana clamps to final swapped maximum.');

        $store = new FakeEquipmentStore(
            equipmentCharacter(['current_hp' => 1, 'current_mana' => 2]),
            [$large, $small],
            ['ring' => 1],
        );
        equipmentService($store)->equip(7, 42, 2, 'ring', equipmentToken(73));
        assertSameValue(1, $store->characters[42]['current_hp'], 'Near-zero Life is not refilled.');
        assertSameValue(2, $store->characters[42]['current_mana'], 'Near-zero Mana is not refilled.');
    },

    'Equipment write failure rolls back slot resources and receipt' => function (): void {
        $store = new FakeEquipmentStore(equipmentCharacter(), [equipmentItem(1, 'weapon')]);
        $store->failWrite = true;
        $service = equipmentService($store);
        try {
            $service->equip(7, 42, 1, 'weapon', equipmentToken(80));
        } catch (RuntimeException) {
            assertSameValue([], $store->equipment, 'Slot rollback.');
            assertSameValue([], $store->requests, 'Receipt rollback.');
            assertSameValue(100, $store->characters[42]['current_hp'], 'Resource rollback.');
            return;
        }
        throw new RuntimeException('Injected failure must escape after rollback.');
    },
];
