<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../../ascii-quest/lib/EquipmentRepository.php';

/** Only the CLI constructs this repository; no browser route/bootstrap registration. */
final class TestEquipmentGrantRepository
{
    private EquipmentRepository $equipment;
    public function __construct(private PDO $pdo) { $this->equipment = new EquipmentRepository($pdo); }
    public function beginMutation(): void { $this->equipment->beginMutation(); }
    public function commitMutation(): void { $this->equipment->commitMutation(); }
    public function rollBackMutation(): void { $this->equipment->rollBackMutation(); }
    public function lockOwnedCharacter(int $userId, int $characterId): ?array
    {
        return $this->equipment->lockOwnedCharacterForEquipment($userId, $characterId);
    }
    public function lockAccountCombatMutex(int $userId): void { $this->equipment->lockAccountCombatMutex($userId); }
    public function lockUnresolvedEncounter(int $characterId): ?array { return $this->equipment->lockUnresolvedEncounter($characterId); }

    private function rows(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function taskItems(int $characterId, bool $activeOnly = false): array
    {
        $active = $activeOnly ? ' AND retired_at IS NULL' : '';
        $items = $this->rows("SELECT * FROM character_items WHERE character_id = :character_id AND source_type = 'maintenance_test' AND source_key LIKE 'task32:%'{$active} ORDER BY id FOR UPDATE", ['character_id' => $characterId]);
        foreach ($items as &$item) {
            $item['affixes'] = $this->rows('SELECT * FROM character_item_affixes WHERE item_id = :item_id ORDER BY id FOR UPDATE', ['item_id' => $item['id']]);
        }
        unset($item);
        return $items;
    }

    public function dependencies(string $base, string $affix, int $tier): array
    {
        $d = $this->rows('SELECT * FROM item_definitions WHERE definition_key = :definition_key FOR UPDATE', ['definition_key' => $base])[0] ?? null;
        $a = $this->rows('SELECT * FROM item_affix_definitions WHERE affix_key = :affix_key FOR UPDATE', ['affix_key' => $affix])[0] ?? null;
        $t = $this->rows('SELECT * FROM item_affix_tiers WHERE affix_key = :affix_key AND tier = :tier FOR UPDATE', ['affix_key' => $affix, 'tier' => $tier])[0] ?? null;
        if ($d !== null) {
            $d['allowed_affix_families'] = array_column($this->rows('SELECT family_key FROM item_definition_affix_families WHERE definition_key = :definition_key FOR UPDATE', ['definition_key' => $base]), 'family_key');
        }
        if ($a !== null) {
            $a['categories'] = array_column($this->rows('SELECT category FROM item_affix_category_rules WHERE affix_key = :affix_key FOR UPDATE', ['affix_key' => $affix]), 'category');
            $a['tiers'] = $t === null ? [] : [$t];
        }
        return [$d, $a, $t];
    }

    public function createItem(int $characterId, array $item, string $now): void
    {
        $affixes = $item['affixes'];
        unset($item['affixes']);
        $item += ['character_id' => $characterId, 'generated_at' => $now, 'claimed_at' => $now];
        // Field names come exclusively from the service's fixed snapshot schema, never CLI input.
        $this->insert('character_items', $item);
        $itemId = (int) $this->pdo->lastInsertId();
        foreach ($affixes as $affix) { $this->insert('character_item_affixes', ['item_id' => $itemId] + $affix); }
    }

    private function insert(string $table, array $row): void
    {
        $columns = implode(', ', array_keys($row));
        $values = ':' . implode(', :', array_keys($row));
        $stmt = $this->pdo->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$values})");
        $stmt->execute($row);
    }

    public function assertCleanupSafe(int $itemId): void
    {
        if ($this->rows('SELECT item_id FROM character_equipment WHERE item_id = :item_id FOR UPDATE', ['item_id' => $itemId]) !== []) {
            throw new RuntimeException('test_item_equipped');
        }
        // Task32 must never share combat-drop identity. Historical action/receipt
        // RESTRICT references are intentionally allowed: retirement retains the item/affixes.
        if ($this->rows('SELECT item_id FROM combat_item_drops WHERE item_id = :item_id FOR UPDATE', ['item_id' => $itemId]) !== []) {
            throw new RuntimeException('test_item_referenced');
        }
    }

    public function retireTaskItems(int $characterId, string $now): int
    {
        $stmt = $this->pdo->prepare("UPDATE character_items SET retired_at = :retired_at WHERE character_id = :character_id AND source_type = 'maintenance_test' AND source_key LIKE 'task32:%' AND retired_at IS NULL");
        $stmt->execute(['retired_at' => $now, 'character_id' => $characterId]);
        return $stmt->rowCount();
    }
}
