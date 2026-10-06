<?php
declare(strict_types=1);

interface ItemInventoryReader
{
    public function ownedCharacter(int $userId, int $characterId): ?array;

    public function countOwnedItems(int $userId, int $characterId): int;

    public function ownedItemsPage(int $userId, int $characterId, int $limit, int $offset): array;

    public function definition(string $definitionKey): ?array;
}

final class ItemRepository implements ItemInventoryReader
{
    public function __construct(private PDO $pdo) {}

    public function ownedCharacter(int $userId, int $characterId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT c.id, c.user_id, c.life_state,
                    (
                        SELECT ce.status
                        FROM combat_encounters AS ce
                        WHERE ce.character_id = c.id
                          AND ce.status IN ('active', 'victory_loot')
                        ORDER BY ce.id DESC
                        LIMIT 1
                    ) AS encounter_status
             FROM characters AS c
             WHERE c.id = :character_id
               AND c.user_id = :user_id
             LIMIT 1",
        );
        $statement->execute([
            'character_id' => $characterId,
            'user_id' => $userId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function countOwnedItems(int $userId, int $characterId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM character_items AS ci
             INNER JOIN characters AS c ON c.id = ci.character_id
             LEFT JOIN character_equipment AS ce ON ce.item_id = ci.id
             WHERE c.user_id = :user_id
               AND c.id = :character_id
               AND ce.item_id IS NULL',
        );
        $statement->execute([
            'user_id' => $userId,
            'character_id' => $characterId,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function ownedItemsPage(
        int $userId,
        int $characterId,
        int $limit,
        int $offset,
    ): array {
        if ($limit < 1 || $offset < 0) {
            throw new InvalidArgumentException('Invalid inventory paging window.');
        }

        $statement = $this->pdo->prepare(
            'SELECT
                 ci.id,
                 ci.character_id,
                 ci.definition_key,
                 ci.item_level,
                 ci.rarity,
                 ci.display_name,
                 ci.snapshot_category,
                 ci.snapshot_subtype,
                 ci.snapshot_equipment_slot,
                 ci.snapshot_damage_type,
                 ci.snapshot_damage_min,
                 ci.snapshot_damage_max,
                 ci.snapshot_toughness,
                 ci.snapshot_attack_rate_modifier_bp,
                 ci.snapshot_cast_rate_modifier_bp,
                 ci.snapshot_block_rate_modifier_bp,
                 ci.glyph,
                 ci.claimed_at
             FROM character_items AS ci
             INNER JOIN characters AS c ON c.id = ci.character_id
             LEFT JOIN character_equipment AS ce ON ce.item_id = ci.id
             WHERE c.user_id = :user_id
               AND c.id = :character_id
               AND ce.item_id IS NULL
             ORDER BY ci.claimed_at DESC, ci.id DESC
             LIMIT :item_limit OFFSET :item_offset',
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':character_id', $characterId, PDO::PARAM_INT);
        $statement->bindValue(':item_limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':item_offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function definition(string $definitionKey): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT definition_key, display_name, category, subtype,
                    equipment_slot, damage_type, base_damage_min,
                    base_damage_max, base_toughness,
                    base_attack_rate_modifier_bp,
                    base_cast_rate_modifier_bp,
                    base_block_rate_modifier_bp, minimum_item_level,
                    maximum_rarity, glyph, is_active
             FROM item_definitions
             WHERE definition_key = :definition_key
             LIMIT 1',
        );
        $statement->execute(['definition_key' => $definitionKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function equippedItems(int $userId, int $characterId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ci.id, ci.character_id, ci.definition_key, ci.item_level,
                    ci.rarity, ci.display_name, ci.snapshot_category,
                    ci.snapshot_subtype, ci.snapshot_equipment_slot,
                    ci.snapshot_damage_type, ci.snapshot_damage_min,
                    ci.snapshot_damage_max, ci.snapshot_toughness,
                    ci.snapshot_attack_rate_modifier_bp,
                    ci.snapshot_cast_rate_modifier_bp,
                    ci.snapshot_block_rate_modifier_bp, ci.glyph,
                    ce.equipment_slot
             FROM character_equipment ce
             INNER JOIN character_items ci
                ON ci.id = ce.item_id AND ci.character_id = ce.character_id
             INNER JOIN characters c ON c.id = ce.character_id
             WHERE c.user_id = :user_id AND c.id = :character_id
             ORDER BY ce.equipment_slot',
        );
        $statement->execute(['user_id' => $userId, 'character_id' => $characterId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function beginMutation(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Item mutation transaction is already active.');
        }
        $this->pdo->beginTransaction();
    }

    public function commitMutation(): void
    {
        $this->pdo->commit();
    }

    public function rollBackMutation(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function lockOwnedCharacterForMutation(int $userId, int $characterId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, user_id, life_state FROM characters WHERE id = :character_id AND user_id = :user_id LIMIT 1 FOR UPDATE');
        $stmt->execute(['character_id' => $characterId, 'user_id' => $userId]);
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

    public function lockDropForClaim(int $dropId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT d.id AS drop_id, d.outcome AS claim_state, d.item_id,
            ce.id AS encounter_id, ce.character_id AS encounter_character_id, ce.status AS encounter_status,
            ci.character_id AS item_character_id, ci.id, ci.item_level, ci.rarity, ci.display_name,
            ci.snapshot_category, ci.snapshot_subtype, ci.snapshot_equipment_slot,
            ci.snapshot_damage_type, ci.snapshot_damage_min, ci.snapshot_damage_max,
            ci.snapshot_toughness, ci.snapshot_attack_rate_modifier_bp,
            ci.snapshot_cast_rate_modifier_bp, ci.snapshot_block_rate_modifier_bp, ci.glyph
            FROM combat_item_drops d
            INNER JOIN combat_encounters ce ON ce.id = d.encounter_id
            LEFT JOIN character_items ci ON ci.id = d.item_id
            WHERE d.id = :drop_id LIMIT 1 FOR UPDATE');
        $stmt->execute(['drop_id' => $dropId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function createClaimRequest(int $characterId, string $requestToken, string $fingerprint, int $itemId): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO item_mutation_requests
            (character_id, request_token, command_type, request_fingerprint, item_id)
            VALUES (:character_id, :request_token, 'claim', :fingerprint, :item_id)");
        $stmt->execute([
            'character_id' => $characterId, 'request_token' => $requestToken,
            'fingerprint' => $fingerprint, 'item_id' => $itemId,
        ]);
    }

    public function claimDrop(int $dropId, int $itemId, int $characterId, string $claimedAt): bool
    {
        $item = $this->pdo->prepare('UPDATE character_items SET character_id = :character_id, claimed_at = :claimed_at WHERE id = :item_id AND character_id IS NULL AND claimed_at IS NULL');
        $item->execute(['character_id' => $characterId, 'claimed_at' => $claimedAt, 'item_id' => $itemId]);
        if ($item->rowCount() !== 1) {
            return false;
        }
        $drop = $this->pdo->prepare("UPDATE combat_item_drops SET outcome = 'claimed', claimed_character_id = :character_id, claimed_at = :claimed_at WHERE id = :drop_id AND item_id = :item_id AND outcome = 'unclaimed' AND claimed_character_id IS NULL AND claimed_at IS NULL");
        $drop->execute(['character_id' => $characterId, 'claimed_at' => $claimedAt, 'drop_id' => $dropId, 'item_id' => $itemId]);
        return $drop->rowCount() === 1;
    }

    public function completeClaimRequest(int $characterId, string $requestToken, array $payload, string $completedAt): void
    {
        $stmt = $this->pdo->prepare("UPDATE item_mutation_requests SET result_code = 'claimed', result_payload = :payload, completed_at = :completed_at WHERE character_id = :character_id AND request_token = :request_token AND result_code IS NULL");
        $stmt->execute([
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'completed_at' => $completedAt, 'character_id' => $characterId,
            'request_token' => $requestToken,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Item claim receipt changed concurrently. Please retry.');
        }
    }

    public function affixesForItems(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->pdo->prepare("SELECT item_id, position, tier, display_fragment, modifier_type, modifier_operation, rolled_value FROM character_item_affixes WHERE item_id IN ({$placeholders}) ORDER BY item_id, FIELD(position, 'prefix', 'suffix')");
        $stmt->execute(array_values($itemIds));
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['item_id']][] = $row;
        }
        return $result;
    }
}
