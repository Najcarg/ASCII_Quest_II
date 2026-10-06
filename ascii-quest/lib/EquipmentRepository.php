<?php
declare(strict_types=1);

final class EquipmentRepository
{
    public function __construct(private PDO $pdo) {}

    public function beginMutation(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Equipment transaction is already active.');
        }
        $this->pdo->beginTransaction();
    }

    public function commitMutation(): void { $this->pdo->commit(); }

    public function rollBackMutation(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function lockOwnedCharacterForEquipment(int $userId, int $characterId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, user_id, life_state, strength, dexterity, vitality, energy, fate, current_hp, current_mana FROM characters WHERE id = :character_id AND user_id = :user_id LIMIT 1 FOR UPDATE');
        $stmt->execute(['character_id' => $characterId, 'user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function lockAccountCombatMutex(int $userId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE id = :user_id LIMIT 1 FOR UPDATE');
        $stmt->execute(['user_id' => $userId]);
        if (!is_array($stmt->fetch(PDO::FETCH_ASSOC))) {
            throw new OutOfBoundsException('Champion unavailable.');
        }
    }

    public function lockUnresolvedEncounter(int $characterId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id, status FROM combat_encounters WHERE character_id = :character_id AND status IN ('active', 'victory_loot') ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $stmt->execute(['character_id' => $characterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function lockMutationRequest(int $characterId, string $requestToken): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM item_mutation_requests WHERE character_id = :character_id AND request_token = :request_token LIMIT 1 FOR UPDATE');
        $stmt->execute(['character_id' => $characterId, 'request_token' => $requestToken]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function createEquipmentRequest(int $characterId, string $token, string $command, string $fingerprint, int $itemId, ?string $sourceSlot, ?string $targetSlot): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO item_mutation_requests (character_id, request_token, command_type, request_fingerprint, item_id, source_slot, target_slot) VALUES (:character_id, :request_token, :command_type, :fingerprint, :item_id, :source_slot, :target_slot)');
        $stmt->execute(['character_id' => $characterId, 'request_token' => $token, 'command_type' => $command, 'fingerprint' => $fingerprint, 'item_id' => $itemId, 'source_slot' => $sourceSlot, 'target_slot' => $targetSlot]);
    }

    public function completeEquipmentRequest(int $characterId, string $token, array $payload, string $completedAt): void
    {
        $stmt = $this->pdo->prepare("UPDATE item_mutation_requests SET result_code = 'completed', result_payload = :payload, completed_at = :completed_at WHERE character_id = :character_id AND request_token = :request_token AND result_code IS NULL");
        $stmt->execute(['payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'completed_at' => $completedAt, 'character_id' => $characterId, 'request_token' => $token]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Equipment receipt changed concurrently. Please retry.');
        }
    }

    public function lockOwnedItem(int $characterId, int $itemId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM character_items WHERE id = :item_id AND character_id = :character_id LIMIT 1 FOR UPDATE');
        $stmt->execute(['item_id' => $itemId, 'character_id' => $characterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function lockEquippedItems(int $characterId): array
    {
        return $this->equippedItemsQuery($characterId, true);
    }

    public function readEquippedItems(int $characterId): array
    {
        return $this->equippedItemsQuery($characterId, false);
    }

    public function ownedCharacterForStats(int $userId, int $characterId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, user_id, strength, dexterity, vitality, energy, fate, current_hp, current_mana FROM characters WHERE id = :character_id AND user_id = :user_id LIMIT 1');
        $stmt->execute(['character_id' => $characterId, 'user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function equippedItemsQuery(int $characterId, bool $lock): array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        $stmt = $this->pdo->prepare('SELECT ci.*, ce.equipment_slot FROM character_equipment ce INNER JOIN character_items ci ON ci.id = ce.item_id AND ci.character_id = ce.character_id WHERE ce.character_id = :character_id ORDER BY ce.equipment_slot' . $suffix);
        $stmt->execute(['character_id' => $characterId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $affixes = $this->affixesForItems($ids);
        foreach ($rows as &$row) {
            $row['affixes'] = $affixes[(int) $row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }

    public function equipItem(int $characterId, int $itemId, string $slot): ?int
    {
        $stmt = $this->pdo->prepare('SELECT item_id FROM character_equipment WHERE character_id = :character_id AND equipment_slot = :slot LIMIT 1 FOR UPDATE');
        $stmt->execute(['character_id' => $characterId, 'slot' => $slot]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            $insert = $this->pdo->prepare('INSERT INTO character_equipment (character_id, equipment_slot, item_id) VALUES (:character_id, :slot, :item_id)');
            $insert->execute(['character_id' => $characterId, 'slot' => $slot, 'item_id' => $itemId]);
            return null;
        }
        $currentId = (int) $current;
        if ($currentId !== $itemId) {
            $update = $this->pdo->prepare('UPDATE character_equipment SET item_id = :item_id, updated_at = CURRENT_TIMESTAMP(6) WHERE character_id = :character_id AND equipment_slot = :slot AND item_id = :current_item_id');
            $update->execute(['item_id' => $itemId, 'character_id' => $characterId, 'slot' => $slot, 'current_item_id' => $currentId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Equipment changed concurrently. Please retry.');
            }
        }
        return $currentId;
    }

    public function unequipItem(int $characterId, int $itemId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT equipment_slot FROM character_equipment WHERE character_id = :character_id AND item_id = :item_id LIMIT 1 FOR UPDATE');
        $stmt->execute(['character_id' => $characterId, 'item_id' => $itemId]);
        $slot = $stmt->fetchColumn();
        if (!is_string($slot)) {
            return null;
        }
        $delete = $this->pdo->prepare('DELETE FROM character_equipment WHERE character_id = :character_id AND equipment_slot = :slot AND item_id = :item_id');
        $delete->execute(['character_id' => $characterId, 'slot' => $slot, 'item_id' => $itemId]);
        return $delete->rowCount() === 1 ? $slot : null;
    }

    public function clampResources(int $characterId, int $currentHp, int $currentMana): void
    {
        $stmt = $this->pdo->prepare('UPDATE characters SET current_hp = :current_hp, current_mana = :current_mana WHERE id = :character_id');
        $stmt->execute(['current_hp' => $currentHp, 'current_mana' => $currentMana, 'character_id' => $characterId]);
        if ($stmt->rowCount() > 1) {
            throw new RuntimeException('Champion resource update failed.');
        }
    }

    public function affixesForItems(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->pdo->prepare("SELECT item_id, modifier_type, modifier_operation, rolled_value FROM character_item_affixes WHERE item_id IN ({$placeholders}) ORDER BY item_id, id");
        $stmt->execute(array_values($itemIds));
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['item_id']][] = $row;
        }
        return $result;
    }

    public function lockOwnedCharacterForStarter(int $userId, int $characterId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT c.id, c.user_id, c.life_state, c.current_hp, c.current_mana, cc.class_key FROM characters c INNER JOIN character_classes cc ON cc.id = c.class_id WHERE c.id = :character_id AND c.user_id = :user_id LIMIT 1 FOR UPDATE');
        $stmt->execute(['character_id' => $characterId, 'user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function starterItem(int $characterId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM character_items WHERE character_id = :character_id AND source_type = 'starter' AND source_key = 'initial_weapon' LIMIT 1 FOR UPDATE");
        $stmt->execute(['character_id' => $characterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function equippedUsableWeapon(int $characterId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT ci.* FROM character_equipment ce INNER JOIN character_items ci ON ci.id = ce.item_id AND ci.character_id = ce.character_id WHERE ce.character_id = :character_id AND ce.equipment_slot = 'weapon' AND ci.snapshot_equipment_slot = 'weapon' LIMIT 1 FOR UPDATE");
        $stmt->execute(['character_id' => $characterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function ownedUsableWeapon(int $characterId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM character_items WHERE character_id = :character_id AND snapshot_equipment_slot = 'weapon' ORDER BY id LIMIT 1 FOR UPDATE");
        $stmt->execute(['character_id' => $characterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function starterDefinition(string $definitionKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM item_definitions WHERE definition_key = :definition_key LIMIT 1');
        $stmt->execute(['definition_key' => $definitionKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function createStarterItem(int $characterId, array $definition, string $now): array
    {
        $stmt = $this->pdo->prepare("INSERT INTO character_items (character_id, definition_key, item_level, rarity, display_name, snapshot_category, snapshot_subtype, snapshot_equipment_slot, snapshot_damage_type, snapshot_damage_min, snapshot_damage_max, snapshot_toughness, snapshot_attack_rate_modifier_bp, snapshot_cast_rate_modifier_bp, snapshot_block_rate_modifier_bp, glyph, source_type, source_key, generated_at, claimed_at) VALUES (:character_id, :definition_key, 1, 'normal', :display_name, :category, :subtype, :equipment_slot, :damage_type, :damage_min, :damage_max, :toughness, :attack_rate, :cast_rate, :block_rate, :glyph, 'starter', 'initial_weapon', :generated_at, :claimed_at)");
        $stmt->execute([
            'character_id' => $characterId, 'definition_key' => $definition['definition_key'],
            'display_name' => $definition['display_name'], 'category' => $definition['category'],
            'subtype' => $definition['subtype'], 'equipment_slot' => $definition['equipment_slot'],
            'damage_type' => $definition['damage_type'], 'damage_min' => $definition['base_damage_min'],
            'damage_max' => $definition['base_damage_max'], 'toughness' => $definition['base_toughness'],
            'attack_rate' => $definition['base_attack_rate_modifier_bp'],
            'cast_rate' => $definition['base_cast_rate_modifier_bp'],
            'block_rate' => $definition['base_block_rate_modifier_bp'], 'glyph' => $definition['glyph'],
            'generated_at' => $now, 'claimed_at' => $now,
        ]);
        return ['id' => (int) $this->pdo->lastInsertId()];
    }

    public function equipStarterWeapon(int $characterId, int $itemId): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO character_equipment (character_id, equipment_slot, item_id) VALUES (:character_id, 'weapon', :item_id)");
        $stmt->execute(['character_id' => $characterId, 'item_id' => $itemId]);
    }

    public function bootstrapCandidates(): array
    {
        $stmt = $this->pdo->query('SELECT c.id, c.user_id, c.character_name, cc.class_key FROM characters c INNER JOIN character_classes cc ON cc.id = c.class_id ORDER BY c.id');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }
}
