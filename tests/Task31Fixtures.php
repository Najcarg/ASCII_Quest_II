<?php
declare(strict_types=1);

require_once __DIR__ . '/../ascii-quest/lib/ItemGenerator.php';
require_once __DIR__ . '/../ascii-quest/lib/ItemProjector.php';
require_once __DIR__ . '/../ascii-quest/lib/EquipmentRepository.php';

/** Read literal INSERT VALUES only, never execute SQL or open a database. */
function task31InsertRows(string $file, string $table): array
{
    $sql = file_get_contents(__DIR__ . '/../database/migrations/' . $file);
    if (!preg_match('/INSERT INTO ' . $table . '\s*\(([^;]+?)\)\s*VALUES\s*(.*?);/s', $sql, $match)) {
        throw new RuntimeException('Missing literal catalogue INSERT: ' . $table);
    }
    $columns = array_map('trim', explode(',', $match[1]));
    preg_match_all('/\(([^()]*)\)/', $match[2], $tuples);
    $rows = [];
    foreach ($tuples[1] as $tuple) {
        preg_match_all("/'[^']*'|NULL|-?\d+/", $tuple, $tokens);
        $values = array_map(static fn ($v) => $v === 'NULL' ? null : ($v[0] === "'" ? substr($v, 1, -1) : (int) $v), $tokens[0]);
        $rows[] = array_combine($columns, $values);
    }
    return $rows;
}

/** Expected Task31 contract, deliberately independent of migration values. */
function task31Spec(): array
{
    return [
        'keen' => ['accuracy', 'accuracy', 'prefix', 'Keen', 'flat', ['weapon', 'jewellery'], [1, 3, 4, 7], 'Accuracy'],
        'deadly' => ['critical_chance', 'critical_chance', 'prefix', 'Deadly', 'flat', ['weapon', 'jewellery'], [1, 3, 4, 7], 'Critical Chance'],
        'savage' => ['critical_damage', 'critical_damage', 'prefix', 'Savage', 'flat', ['weapon', 'jewellery'], [5, 10, 11, 20], 'Critical Damage'],
        'vampiric' => ['life_on_hit', 'life_on_hit', 'prefix', 'Vampiric', 'flat', ['weapon', 'jewellery'], [1, 3, 4, 7], 'Life on Hit'],
        'flaming' => ['fire_damage', 'fire_damage', 'prefix', 'Flaming', 'flat', ['weapon', 'jewellery'], [1, 3, 4, 7], 'Fire Damage'],
        'stormcharged' => ['lightning_damage', 'lightning_damage', 'prefix', 'Stormcharged', 'flat', ['weapon', 'jewellery'], [1, 3, 4, 7], 'Lightning Damage'],
        'frosted' => ['cold_damage', 'cold_damage', 'prefix', 'Frosted', 'flat', ['weapon', 'jewellery'], [1, 3, 4, 7], 'Cold Damage'],
        'venomous' => ['poison_damage', 'poison_damage', 'prefix', 'Venomous', 'flat', ['weapon', 'jewellery'], [1, 3, 4, 7], 'Poison Damage'],
        'bleeding' => ['bleed_damage', 'bleed_damage', 'prefix', 'Bleeding', 'flat', ['weapon', 'jewellery'], [1, 3, 4, 7], 'Bleed'],
        'scorching' => ['burn_damage', 'burn_damage', 'prefix', 'Scorching', 'flat', ['weapon', 'jewellery'], [1, 3, 4, 7], 'Burn'],
        'freezing' => ['freeze_damage', 'freeze_damage', 'prefix', 'Freezing', 'flat', ['weapon', 'jewellery'], [1, 3, 4, 7], 'Freeze'],
        'shocking' => ['shock_damage', 'shock_damage', 'prefix', 'Shocking', 'flat', ['weapon', 'jewellery'], [1, 3, 4, 7], 'Shock'],
        'of_evasion' => ['dodging', 'dodging', 'suffix', 'of Evasion', 'flat', ['armour', 'jewellery'], [1, 3, 4, 7], 'Dodging'],
        'of_guarding' => ['block_rate', 'block_rate_bp', 'suffix', 'of Guarding', 'additive_percent', ['off_hand'], [1000, 2500, 2501, 5000], 'Block Rate'],
        'of_storm_warding' => ['lightning_resistance', 'lightning_resistance', 'suffix', 'of Storm Warding', 'flat', ['armour', 'off_hand', 'jewellery'], [1, 3, 4, 7], 'Lightning Resistance'],
        'of_poison_warding' => ['poison_resistance', 'poison_resistance', 'suffix', 'of Poison Warding', 'flat', ['armour', 'off_hand', 'jewellery'], [1, 3, 4, 7], 'Poison Resistance'],
    ];
}

/** Reconstitute the accepted 006 + additive 010 catalogue without applying either. */
function task31Catalogue(): array
{
    $old = '006_item_generation_and_drops.sql';
    $new = '010_combat_item_affix_completion.sql';
    $affixes = [];
    foreach (task31InsertRows($old, 'item_affix_definitions') as $a) {
        $percent = $a['modifier_operation'] === 'additive_percent';
        $a['selection_weight'] = 100;
        $a['categories'] = in_array($a['modifier_type'], ['flat_damage', 'damage_percent_bp', 'attack_rate_bp', 'cast_rate_bp'], true)
            ? ['weapon'] : ['weapon', 'armour', 'off_hand', 'jewellery'];
        $a['tiers'] = [
            ['tier' => 1, 'minimum_item_level' => 1, 'minimum_value' => $percent ? 100 : 1, 'maximum_value' => $percent ? 300 : 3],
            ['tier' => 2, 'minimum_item_level' => 10, 'minimum_value' => $percent ? 301 : 4, 'maximum_value' => $percent ? 600 : 7],
        ];
        $affixes[] = $a;
    }
    $tiers = task31InsertRows($new, 'item_affix_tiers');
    $rules = task31InsertRows($new, 'item_affix_category_rules');
    foreach (task31InsertRows($new, 'item_affix_definitions') as $a) {
        $a['tiers'] = array_values(array_filter($tiers, static fn ($t) => $t['affix_key'] === $a['affix_key']));
        $a['categories'] = array_column(array_filter($rules, static fn ($r) => $r['affix_key'] === $a['affix_key']), 'category');
        $affixes[] = $a;
    }
    $definitions = array_merge(task31InsertRows($old, 'item_definitions'), task31InsertRows($new, 'item_definitions'));
    foreach ($definitions as &$d) {
        // Same category join as the migration; structural tests assert the actual SQL.
        $d['allowed_affix_families'] = array_values(array_unique(array_column(array_filter($affixes,
            static fn ($a) => in_array($d['category'], $a['categories'], true)), 'family_key')));
    }
    unset($d);
    return [$definitions, $affixes];
}

function task31Registry(?array $definitions = null, ?array $affixes = null): ItemDefinitionRegistry
{
    [$d, $a] = task31Catalogue();
    return new ItemDefinitionRegistry($definitions ?? $d, $affixes ?? $a, ['normal' => 60, 'magic' => 30, 'rare' => 10]);
}

/** Select by weighted offsets from the FULL real catalogue, not a one-affix bypass. */
function task31Generate(string $key, array $affixKeys, int $level = 1, int $tier = 1, bool $minimum = false): array
{
    $registry = task31Registry();
    $offset = 1;
    $definition = null;
    foreach ($registry->eligibleDefinitions($level) as $d) {
        if ($d['definition_key'] === $key) { $definition = $d; break; }
        $offset += $d['loot_weight'];
    }
    if ($definition === null) { throw new RuntimeException('No eligible definition: ' . $key); }
    $rolls = [$offset, count($affixKeys) === 2 ? 91 : ($affixKeys === [] ? 1 : 61)];
    [, $all] = task31Catalogue();
    $all = array_column($all, null, 'affix_key');
    if (count($affixKeys) === 1) { $rolls[] = $all[$affixKeys[0]]['position'] === 'prefix' ? 0 : 1; }
    $families = [];
    foreach ($affixKeys as $affixKey) {
        $target = $all[$affixKey];
        $offset = 1;
        $found = false;
        foreach ($registry->eligibleAffixes($definition, $target['position'], $level, $families) as $a) {
            if ($a['affix_key'] === $affixKey) { $found = true; break; }
            $offset += $a['selection_weight'];
        }
        if (!$found) { throw new DomainException('Illegal affix for this definition: ' . $affixKey); }
        $eligible = $registry->eligibleTiers($target, $level);
        $chosen = $eligible[$tier - 1] ?? throw new DomainException('Ineligible tier');
        $rolls = array_merge($rolls, [$offset, $tier, $chosen[$minimum ? 'minimum_value' : 'maximum_value']]);
        $families[] = $target['family_key'];
    }
    return (new ItemGenerator($registry))->generate($level, new QueuedItemRandomSource($rolls));
}

/** SQL-boundary fake only: production EquipmentRepository performs both reads.
 * Generated immutable rows are separated into character_items and character_item_affixes.
 * No SQL is executed and no real PDO connection is created.
 */
final class Task31EquipmentPdo extends PDO
{
    public array $items = [];
    public array $affixes = [];
    public array $queries = [];
    public function __construct(array $generated)
    {
        foreach ($generated as $i => $item) {
            $id = $i + 1;
            foreach ($item['affixes'] as $a) { $this->affixes[] = ['item_id' => $id] + $a; }
            unset($item['affixes']);
            $this->items[] = ['id' => $id, 'character_id' => 42, 'equipment_slot' => $item['snapshot_equipment_slot']] + $item;
        }
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        return new Task31EquipmentStatement($this, $query);
    }
}
final class Task31EquipmentStatement extends PDOStatement
{
    private array $rows = [];
    public function __construct(private Task31EquipmentPdo $pdo, private string $query) {}
    public function execute(?array $params = null): bool
    {
        if (str_starts_with($this->query, 'SELECT passive_key,row_number,pass_number,unlocked_at FROM character_passives')) {
            $this->rows = []; // Existing regression fixture has no passive allocations.
        } elseif (str_starts_with($this->query, 'SELECT ci.*, ce.equipment_slot FROM character_equipment ce INNER JOIN character_items')) {
            $this->rows = array_values(array_filter($this->pdo->items, static fn ($r) => $r['character_id'] === $params['character_id']));
        } elseif (str_starts_with($this->query, 'SELECT item_id, modifier_type, modifier_operation, rolled_value FROM character_item_affixes WHERE item_id IN')) {
            $this->rows = array_map(static fn ($r) => array_intersect_key($r, array_flip(['item_id', 'modifier_type', 'modifier_operation', 'rolled_value'])),
                array_values(array_filter($this->pdo->affixes, static fn ($r) => in_array($r['item_id'], $params, true))));
        } else { throw new RuntimeException('Unexpected equipment SQL: ' . $this->query); }
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}

function task31Provider(array $generated): array
{
    $pdo = new Task31EquipmentPdo($generated);
    $repository = new EquipmentRepository($pdo);
    return [new PersistentCombatEquipmentProvider($repository, CombatDefinitionRegistry::fromDefaultConfig(), new EquipmentStatAggregator()), $repository, $pdo];
}

function task31Combat(array $generated, array $rolls): array
{
    [$pdo, $clock, , , $random] = task25Fixture([], $rolls);
    [$provider, $repository, $equipmentPdo] = task31Provider($generated);
    $pdo->encounters[91]['enemy_current_hp'] = 100000;
    $pdo->encounters[91]['enemy_max_hp'] = 100000;
    $service = CombatBootstrap::serviceForRepository(new CombatRepository($pdo), $clock, $provider, $random);
    return [$pdo, $clock, $provider, $random, $service, $repository, $equipmentPdo];
}
