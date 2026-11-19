<?php
declare(strict_types=1);

require_once __DIR__ . '/Task31Fixtures.php';
require_once __DIR__ . '/../scripts/lib/TestEquipmentGrantRepository.php';
require_once __DIR__ . '/../scripts/lib/TestEquipmentGrantService.php';
require_once __DIR__ . '/../scripts/lib/TestEquipmentGrantArguments.php';

/** Connection-free SQL-boundary fixture. Runs REAL maintenance/item/equipment repositories.
 * Reads accepted catalogue SQL literals, never executes migrations or opens a DB.
 * Transaction simulation is not proof of MariaDB DDL/locking/FK execution.
 */
final class Task32Pdo extends PDO
{
    public array $definitions = [];
    public array $catalogueAffixes = [];
    public array $characters = [];
    public array $items = [];
    public array $affixes = [];
    public array $equipment = [];
    public array $drops = [];
    public array $actions = [];
    public array $receipts = [];
    public array $queries = [];
    public array $writes = [];
    public ?string $encounter = null;
    public int $insertAttempts = 0;
    public int $failInsertAt = 0;
    public int $failRetirementAfter = 0;
    public int $rollbacks = 0;
    public int $commits = 0;
    private int $lastId = 1000;
    private ?string $snapshot = null;

    public function __construct()
    {
        [$definitions, $affixes] = task31Catalogue();
        foreach ($definitions as $d) {
            $this->definitions[$d['definition_key']] = $d + ['is_active' => 1, 'base_attack_rate_modifier_bp' => 0, 'base_cast_rate_modifier_bp' => 0, 'base_block_rate_modifier_bp' => 0];
        }
        foreach ($affixes as $a) { $this->catalogueAffixes[$a['affix_key']] = $a + ['is_active' => 1]; }
        $this->characters[42] = equipmentCharacter();
        foreach ([901 => ['starter', 'initial_weapon'], 902 => ['combat_drop', 'encounter:1'],
            903 => ['maintenance_test', 'unrelated:accuracy'], 904 => ['combat_drop', 'encounter:2'],
            905 => ['maintenance_test', 'task32:accuracy']] as $id => [$type, $key]) {
            $this->items[$id] = inventoryItem($id, '2026-10-04 12:00:00.000000', ['retired_at' => null, 'source_type' => $type, 'source_key' => $key, 'character_id' => $id === 905 ? 99 : 42]);
            $this->affixes[] = ['id' => $id, 'item_id' => $id, 'position' => 'prefix', 'affix_key' => 'strong', 'tier' => 1, 'family_key' => 'strength', 'display_fragment' => 'Strong', 'modifier_type' => 'strength', 'modifier_operation' => 'flat', 'rolled_value' => 1];
        }
        $this->equipment['weapon'] = 901;
        $this->drops = [902, 904];
    }
    public function state(): string { return serialize([$this->characters, $this->items, $this->affixes, $this->equipment, $this->drops, $this->actions, $this->receipts]); }
    public function beginTransaction(): bool
    {
        if ($this->snapshot !== null) { throw new LogicException('Nested fixture transaction.'); }
        $this->snapshot = $this->state(); $this->queries[] = 'BEGIN'; return true;
    }
    public function inTransaction(): bool { return $this->snapshot !== null; }
    public function commit(): bool { $this->snapshot = null; $this->commits++; $this->queries[] = 'COMMIT'; return true; }
    public function rollBack(): bool
    {
        if ($this->snapshot !== null) { [$this->characters, $this->items, $this->affixes, $this->equipment, $this->drops, $this->actions, $this->receipts] = unserialize($this->snapshot); }
        $this->snapshot = null; $this->rollbacks++; $this->queries[] = 'ROLLBACK'; return true;
    }
    public function lastInsertId(?string $name = null): string|false { return (string) $this->lastId; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = preg_replace('/\s+/', ' ', trim($query));
        $this->queries[] = $query;
        return new Task32Statement($this, $query);
    }
    public function insert(string $query, array $row): void
    {
        $this->writes[] = $query;
        $this->insertAttempts++;
        if ($this->insertAttempts === $this->failInsertAt) { throw new RuntimeException('Injected insertion failure.'); }
        if (str_starts_with($query, 'INSERT INTO character_items ')) {
            foreach ($this->items as $item) {
                if ([$item['character_id'], $item['source_type'], $item['source_key']] === [$row['character_id'], $row['source_type'], $row['source_key']]) { throw new RuntimeException('Duplicate provenance.'); }
            }
            $row['retired_at'] ??= null;
            $row['id'] = ++$this->lastId;
            $this->items[$row['id']] = $row;
        } elseif (str_starts_with($query, 'INSERT INTO character_item_affixes ')) {
            $row['id'] = count($this->affixes) + 1; $this->affixes[] = $row;
        } else { throw new RuntimeException('Unexpected write.'); }
    }
    public function taskItems(): array
    {
        return array_values(array_filter($this->items, static fn ($i) => $i['character_id'] === 42 && $i['source_type'] === 'maintenance_test' && str_starts_with($i['source_key'], 'task32:')));
    }
}

final class Task32Statement extends PDOStatement
{
    private array $rows = [];
    private array $bindings = [];
    private int $affected = 0;
    public function __construct(private Task32Pdo $pdo, private string $query) {}
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool { $this->bindings[ltrim((string) $param, ':')] = $value; return true; }
    public function execute(?array $params = null): bool
    {
        $p = $params ?? $this->bindings;
        $db = $this->pdo; $q = $this->query; $this->rows = []; $this->affected = 0;
        if (str_starts_with($q, 'SELECT id, user_id, life_state, strength')) {
            $c = $db->characters[$p['character_id']] ?? null;
            $this->rows = $c !== null && $c['user_id'] === $p['user_id'] ? [$c] : [];
        } elseif (str_starts_with($q, 'SELECT id FROM users ')) {
            $this->rows = $p['user_id'] === 7 ? [['id' => 7]] : [];
        } elseif (str_starts_with($q, 'SELECT id, status FROM combat_encounters ')) {
            $this->rows = in_array($db->encounter, ['active', 'victory_loot'], true) ? [['id' => 91, 'status' => $db->encounter]] : [];
        } elseif (str_starts_with($q, 'SELECT * FROM character_items WHERE character_id') && str_contains($q, "source_type = 'maintenance_test'")) {
            $this->rows = array_values(array_filter($db->taskItems(), static fn ($i) => !str_contains($q, 'retired_at IS NULL') || $i['retired_at'] === null));
        } elseif (str_starts_with($q, 'SELECT * FROM character_item_affixes WHERE item_id')) {
            $this->rows = array_values(array_filter($db->affixes, static fn ($a) => $a['item_id'] === (int) $p['item_id']));
        } elseif (str_starts_with($q, 'SELECT * FROM item_definitions ')) {
            $this->rows = isset($db->definitions[$p['definition_key']]) ? [$db->definitions[$p['definition_key']]] : [];
        } elseif (str_starts_with($q, 'SELECT * FROM item_affix_definitions ')) {
            $this->rows = isset($db->catalogueAffixes[$p['affix_key']]) ? [$db->catalogueAffixes[$p['affix_key']]] : [];
        } elseif (str_starts_with($q, 'SELECT * FROM item_affix_tiers ')) {
            $this->rows = array_values(array_filter($db->catalogueAffixes[$p['affix_key']]['tiers'] ?? [], static fn ($t) => $t['tier'] === $p['tier']));
        } elseif (str_starts_with($q, 'SELECT family_key FROM item_definition_affix_families ')) {
            $this->rows = array_map(static fn ($f) => ['family_key' => $f], $db->definitions[$p['definition_key']]['allowed_affix_families'] ?? []);
        } elseif (str_starts_with($q, 'SELECT category FROM item_affix_category_rules ')) {
            $this->rows = array_map(static fn ($c) => ['category' => $c], $db->catalogueAffixes[$p['affix_key']]['categories'] ?? []);
        } elseif (str_starts_with($q, 'INSERT INTO ')) {
            $db->insert($q, $p); $this->affected = 1;
        } elseif (str_starts_with($q, 'SELECT item_id FROM character_equipment WHERE item_id')) {
            $this->rows = in_array($p['item_id'], $db->equipment, true) ? [['item_id' => $p['item_id']]] : [];
        } elseif (str_starts_with($q, 'SELECT item_id FROM combat_item_drops ')) {
            $this->rows = in_array($p['item_id'], $db->drops, true) ? [['item_id' => $p['item_id']]] : [];
        } elseif (str_starts_with($q, 'SELECT snapshot_weapon_item_id FROM combat_actions ')) {
            $this->rows = in_array($p['item_id'], $db->actions, true) ? [['snapshot_weapon_item_id' => $p['item_id']]] : [];
        } elseif (str_starts_with($q, 'SELECT item_id FROM item_mutation_requests ')) {
            $this->rows = in_array($p['item_id'], $db->receipts, true) ? [['item_id' => $p['item_id']]] : [];
        } elseif (str_starts_with($q, 'UPDATE character_items SET retired_at')) {
            $db->writes[] = $q;
            foreach ($db->taskItems() as $item) {
                if ($item['retired_at'] !== null) { continue; }
                $db->items[$item['id']]['retired_at'] = $p['retired_at']; $this->affected++;
                if ($db->failRetirementAfter === $this->affected) { throw new RuntimeException('Injected retirement failure.'); }
            }
        } elseif (str_starts_with($q, 'SELECT * FROM character_items WHERE id =')) {
            $item = $db->items[$p['item_id']] ?? null;
            $this->rows = $item !== null && $item['character_id'] === $p['character_id'] ? [$item] : [];
        } elseif (str_starts_with($q, 'SELECT * FROM character_items WHERE character_id') && str_contains($q, 'snapshot_equipment_slot')) {
            $this->rows = array_values(array_filter($db->items, static fn ($i) => $i['character_id'] === $p['character_id'] && $i['snapshot_equipment_slot'] === 'weapon' && (!str_contains($q, 'retired_at IS NULL') || $i['retired_at'] === null)));
        } elseif (str_starts_with($q, 'SELECT passive_key,`row_number`,pass_number,unlocked_at FROM character_passives')) {
            $this->rows = []; // No passive allocations in existing equipment fixture.
        } elseif (str_starts_with($q, 'SELECT ci.*, ce.equipment_slot FROM character_equipment')) {
            foreach ($db->equipment as $slot => $id) {
                if ($db->items[$id]['character_id'] === $p['character_id'] && (!str_contains($q, 'retired_at IS NULL') || $db->items[$id]['retired_at'] === null)) { $this->rows[] = ['equipment_slot' => $slot] + $db->items[$id]; }
            }
        } elseif (str_starts_with($q, 'SELECT item_id, ' ) && str_contains($q, 'FROM character_item_affixes WHERE item_id IN')) {
            $this->rows = array_values(array_filter($db->affixes, static fn ($a) => in_array($a['item_id'], $p, true)));
        } elseif (str_starts_with($q, 'SELECT COUNT(*) FROM character_items AS ci') || str_starts_with($q, 'SELECT ci.id, ci.character_id')) {
            if ($p['user_id'] === 7 && $p['character_id'] === 42) {
                $this->rows = array_values(array_filter($db->items, static fn ($i) => $i['character_id'] === 42 && (!str_contains($q, 'retired_at IS NULL') || $i['retired_at'] === null) && !in_array($i['id'], $db->equipment, true)));
                if (str_starts_with($q, 'SELECT COUNT(*)')) { $this->rows = [[count($this->rows)]]; }
                else { $this->rows = array_slice($this->rows, $p['item_offset'], $p['item_limit']); }
            }
        } else { throw new RuntimeException('Unexpected Task32 SQL: ' . $q); }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return array_shift($this->rows) ?: false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed { return array_values($this->rows[0] ?? [])[$column] ?? false; }
    public function rowCount(): int { return $this->affected; }
}

function task32Service(Task32Pdo $pdo): TestEquipmentGrantService { return new TestEquipmentGrantService(new TestEquipmentGrantRepository($pdo)); }
function task32Granted(): array
{
    $pdo = new Task32Pdo(); $service = task32Service($pdo); $service->execute(7, 42, true);
    return [$pdo, $service];
}
function task32Generated(Task32Pdo $pdo, string $key): array
{
    foreach ($pdo->taskItems() as $item) {
        if ($item['source_key'] === 'task32:' . $key) {
            $item['affixes'] = array_values(array_filter($pdo->affixes, static fn ($a) => $a['item_id'] === $item['id']));
            return $item;
        }
    }
    throw new RuntimeException('Missing granted item ' . $key);
}
function task32Reject(callable $action, string $message): void
{
    try { $action(); } catch (Throwable $e) { assertSameValue($message, $e->getMessage(), 'Expected safe failure.'); return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}
