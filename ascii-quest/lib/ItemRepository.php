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
             WHERE c.user_id = :user_id
               AND c.id = :character_id',
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
             WHERE c.user_id = :user_id
               AND c.id = :character_id
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
}
