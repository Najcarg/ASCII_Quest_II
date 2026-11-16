<?php
declare(strict_types=1);

require_once __DIR__ . '/Task32Fixtures.php';

/** Independent exact acceptance table, not read from the service specification. */
function task32Expected(): array
{
    return [
        'accuracy' => ['worn_sword', 'keen', 7, 'Keen Sword', '+7 Accuracy', 'combat', 'accuracy'],
        'critical_chance' => ['worn_sword', 'deadly', 7, 'Deadly Sword', '+7 Critical Chance', 'combat', 'critical_chance'],
        'critical_damage' => ['worn_sword', 'savage', 20, 'Savage Sword', '+20 Critical Damage', 'combat', 'critical_damage'],
        'life_on_hit' => ['copper_ring', 'vampiric', 7, 'Vampiric Copper Ring', '+7 Life on Hit', 'utility', 'life_on_hit'],
        'dodge' => ['hide_boots', 'of_evasion', 7, 'Hide Boots of Evasion', '+7 Dodging', 'combat', 'dodging'],
        'block_rate' => ['wooden_shield', 'of_guarding', 5000, 'Wooden Shield of Guarding', '+50.00% Block Rate', 'rates', 'block_rate'],
        'fire_damage' => ['copper_amulet', 'flaming', 7, 'Flaming Copper Amulet', '+7 Fire Damage', 'utility', 'fire_damage'],
        'lightning_damage' => ['copper_amulet', 'stormcharged', 7, 'Stormcharged Copper Amulet', '+7 Lightning Damage', 'utility', 'lightning_damage'],
        'cold_damage' => ['copper_amulet', 'frosted', 7, 'Frosted Copper Amulet', '+7 Cold Damage', 'utility', 'cold_damage'],
        'poison' => ['copper_charm', 'venomous', 7, 'Venomous Copper Charm', '+7 Poison Damage', 'utility', 'poison_damage'],
        'bleed' => ['copper_charm', 'bleeding', 7, 'Bleeding Copper Charm', '+7 Bleed', 'utility', 'bleed_damage'],
        'burn' => ['copper_charm', 'scorching', 7, 'Scorching Copper Charm', '+7 Burn', 'utility', 'burn_damage'],
        'freeze' => ['copper_charm', 'freezing', 7, 'Freezing Copper Charm', '+7 Freeze', 'utility', 'freeze_damage'],
        'shock' => ['copper_charm', 'shocking', 7, 'Shocking Copper Charm', '+7 Shock', 'utility', 'shock_damage'],
    ];
}

/** Real equipment service writes completed receipts; retirement is a later fixture event. */
function task32RetiredEquipmentHistory(): array
{
    [$pdo] = task32Granted();
    $item = task32Generated($pdo, 'accuracy');
    $store = new FakeEquipmentStore(equipmentCharacter(), [$item]);
    $service = equipmentService($store);
    $equip = $service->equip(7, 42, $item['id'], 'weapon', equipmentToken(3203));
    $unequip = $service->unequip(7, 42, $item['id'], equipmentToken(3204));
    $store->items[$item['id']]['retired_at'] = '2026-11-16 12:00:00.000000';
    $store->failWrite = true; // Any attempt to reapply equipment mutations must fail the test.
    return [$store, $service, $item['id'], $equip, $unequip];
}

$tests = [
    'Task32 maintenance service and repository exist without generator or web bootstrap' => function (): void {
        foreach (['TestEquipmentGrantService', 'TestEquipmentGrantRepository'] as $class) {
            $path = __DIR__ . '/../scripts/lib/' . $class . '.php';
            assertSameValue(false, str_contains(file_get_contents($path), 'ItemGenerator'), 'No random generator dependency.');
        }
        assertSameValue(14, count(TestEquipmentGrantService::specification()), 'Exact14.');
        assertSameValue(array_keys(task32Expected()), array_keys(TestEquipmentGrantService::specification()), 'Exact keys.');
    },
    'Task32 preview is write-free including mutex and preserves resources equipment everything' => function (): void {
        $pdo = new Task32Pdo(); $before = $pdo->state();
        $r = task32Service($pdo)->execute(7, 42);
        assertSameValue(['preview', 0, 14, 0, 0, 14], [$r['result'], $r['existing_task32'], $r['would_create'], $r['created'], $r['final_count'], $r['planned_final_count']], 'Preview counts.');
        assertSameValue([$before, [], 1, 0], [$pdo->state(), $pdo->writes, $pdo->rollbacks, $pdo->commits], 'Zero writes, end preview rollback.');
        assertSameValue('BEGIN', $pdo->queries[0], 'Transaction first.');
        foreach ([[1, 'FROM characters'], [2, 'FROM users'], [3, 'FROM combat_encounters'], [4, 'FROM character_items']] as [$n, $part]) {
            assertSameValue(true, str_contains($pdo->queries[$n], $part) && str_ends_with($pdo->queries[$n], 'FOR UPDATE'), 'Lock order ' . $part);
        }
    },
    'Task32 apply exact14 real immutable snapshots one tier2 affix each no equipment HP Mana change' => function (): void {
        $pdo = new Task32Pdo(); $before = [$pdo->characters, $pdo->equipment, $pdo->drops];
        $r = task32Service($pdo)->execute(7, 42, true);
        assertSameValue(['created', 14, 0, 14], [$r['result'], $r['created'], $r['already_exists'], $r['final_count']], 'Apply counts.');
        assertSameValue($before, [$pdo->characters, $pdo->equipment, $pdo->drops], 'Existing resources/equipment/drop records untouched.');
        assertSameValue([14, 28, 1], [count($pdo->taskItems()), count($pdo->writes), $pdo->commits], '14 parent +14 affix inserts.');
        assertSameValue(1, count(array_unique(array_column($pdo->taskItems(), 'generated_at'))), 'One common generation timestamp.');
        foreach (task32Expected() as $key => [$base, $affix, $value, $name]) {
            $item = task32Generated($pdo, $key); $d = $pdo->definitions[$base]; $a = $pdo->catalogueAffixes[$affix];
            assertSameValue([42, $base, 10, 'magic', $name, 'maintenance_test', 'task32:' . $key],
                array_map(static fn ($k) => $item[$k], ['character_id', 'definition_key', 'item_level', 'rarity', 'display_name', 'source_type', 'source_key']), 'Exact item identity ' . $key);
            assertSameValue($item['generated_at'], $item['claimed_at'], 'Same timestamp.');
            assertSameValue(true, (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D', $item['generated_at']), 'UTC timestamp.');
            foreach (['category', 'subtype', 'equipment_slot', 'damage_type'] as $f) { assertSameValue($d[$f], $item['snapshot_' . $f], 'Base snapshot ' . $f); }
            foreach (['damage_min', 'damage_max', 'toughness', 'attack_rate_modifier_bp', 'cast_rate_modifier_bp', 'block_rate_modifier_bp'] as $f) { assertSameValue($d['base_' . $f], $item['snapshot_' . $f], 'Base snapshot ' . $f); }
            assertSameValue($d['glyph'], $item['glyph'], 'Real glyph.');
            assertSameValue(1, count($item['affixes']), 'Exactly one affix.');
            foreach (['position', 'affix_key', 'family_key', 'display_fragment', 'modifier_type', 'modifier_operation'] as $f) { assertSameValue($a[$f], $item['affixes'][0][$f], 'Affix snapshot ' . $f); }
            assertSameValue([2, $value], [$item['affixes'][0]['tier'], $item['affixes'][0]['rolled_value']], 'Fixed tier/value.');
        }
    },
    'Task32 repeated apply is durable idempotency and does not repair genuine historical snapshots' => function (): void {
        [$pdo, $service] = task32Granted();
        $id = task32Generated($pdo, 'accuracy')['id'];
        $pdo->items[$id]['display_name'] = 'Historical Keen Sword'; $pdo->items[$id]['snapshot_damage_min'] = 2;
        foreach ($pdo->affixes as &$a) { if ($a['item_id'] === $id) { $a['rolled_value'] = 4; $a['display_fragment'] = 'Historical Keen'; } } unset($a);
        $pdo->definitions['worn_sword']['display_name'] = 'Current Sword';
        $before = $pdo->state(); $writes = count($pdo->writes);
        $r = $service->execute(7, 42, true);
        assertSameValue([0, 14, 14, $before, $writes], [$r['created'], $r['already_exists'], $r['final_count'], $pdo->state(), count($pdo->writes)], 'No delete/recreate/reroll or name repair.');
        assertSameValue('Historical Keen Sword', $r['items']['accuracy']['display_name'], 'Report stored immutable name.');
    },
    'Task32 mixed grant creates only missing provenance' => function (): void {
        [$pdo, $service] = task32Granted();
        foreach (['accuracy', 'shock'] as $key) {
            $id = task32Generated($pdo, $key)['id']; unset($pdo->items[$id]);
            $pdo->affixes = array_values(array_filter($pdo->affixes, static fn ($a) => $a['item_id'] !== $id));
        }
        $existing = $pdo->taskItems(); $r = $service->execute(7, 42, true);
        assertSameValue([12, 2, 12, 14], [$r['existing_task32'], $r['created'], $r['already_exists'], $r['final_count']], 'Mixed counts.');
        foreach ($existing as $item) { assertSameValue($item, $pdo->items[$item['id']], 'Existing IDs/snapshots retained.'); }
    },
    'Task32 wrong existing definition affix tier extra affix or unknown Task32 key fails closed' => function (): void {
        foreach (['definition', 'affix', 'tier', 'extra', 'unknown'] as $bad) {
            [$pdo, $service] = task32Granted(); $item = task32Generated($pdo, 'accuracy'); $id = $item['id'];
            if ($bad === 'definition') { $pdo->items[$id]['definition_key'] = 'worn_dagger'; }
            elseif ($bad === 'unknown') { $pdo->items[$id]['source_key'] = 'task32:unknown'; }
            elseif ($bad === 'extra') { $pdo->affixes[] = ['item_id' => $id] + $pdo->affixes[0]; }
            else { foreach ($pdo->affixes as &$a) { if ($a['item_id'] === $id) { $a[$bad === 'affix' ? 'affix_key' : 'tier'] = $bad === 'affix' ? 'deadly' : 1; } } unset($a); }
            $before = $pdo->state();
            task32Reject(fn () => $service->execute(7, 42, true), 'Task32 provenance identity mismatch.');
            assertSameValue($before, $pdo->state(), 'Mismatch leaves all state unchanged.');
        }
    },
    'Task32 insertion failure rolls back whole set including parent whose affix failed' => function (): void {
        foreach ([1, 2, 15, 28] as $failure) {
            $pdo = new Task32Pdo(); $pdo->failInsertAt = $failure; $before = $pdo->state();
            task32Reject(fn () => task32Service($pdo)->execute(7, 42, true), 'Injected insertion failure.');
            assertSameValue([$before, 1, 0], [$pdo->state(), $pdo->rollbacks, $pdo->commits], 'No halfset at insert ' . $failure);
        }
    },
    'Task32 every invalid dependency rejects all14 before any insert' => function (): void {
        foreach (['base_missing', 'base_inactive', 'base_level', 'base_rarity', 'base_slot', 'base_field', 'affix_missing', 'affix_inactive', 'affix_level', 'category', 'family', 'tier_missing', 'tier_level', 'bounds_min', 'bounds_max', 'operation', 'modifier'] as $bad) {
            $pdo = new Task32Pdo();
            switch ($bad) {
                case 'base_missing': unset($pdo->definitions['copper_charm']); break;
                case 'base_inactive': $pdo->definitions['copper_charm']['is_active'] = 0; break;
                case 'base_level': $pdo->definitions['copper_charm']['minimum_item_level'] = 11; break;
                case 'base_rarity': $pdo->definitions['copper_charm']['maximum_rarity'] = 'normal'; break;
                case 'base_slot': $pdo->definitions['copper_charm']['equipment_slot'] = 'invalid'; break;
                case 'base_field': unset($pdo->definitions['copper_charm']['base_toughness']); break;
                case 'affix_missing': unset($pdo->catalogueAffixes['shocking']); break;
                case 'affix_inactive': $pdo->catalogueAffixes['shocking']['is_active'] = 0; break;
                case 'affix_level': $pdo->catalogueAffixes['shocking']['minimum_item_level'] = 11; break;
                case 'category': $pdo->catalogueAffixes['shocking']['categories'] = ['weapon']; break;
                case 'family': $pdo->definitions['copper_charm']['allowed_affix_families'] = []; break;
                case 'tier_missing': $pdo->catalogueAffixes['shocking']['tiers'] = []; break;
                case 'tier_level': $pdo->catalogueAffixes['shocking']['tiers'][1]['minimum_item_level'] = 11; break;
                case 'bounds_min': $pdo->catalogueAffixes['shocking']['tiers'][1]['minimum_value'] = 8; break;
                case 'bounds_max': $pdo->catalogueAffixes['shocking']['tiers'][1]['maximum_value'] = 6; break;
                case 'operation': $pdo->catalogueAffixes['shocking']['modifier_operation'] = 'multiply'; break;
                case 'modifier': $pdo->catalogueAffixes['shocking']['modifier_type'] = 'unknown'; break;
            }
            $before = $pdo->state(); $r = task32Service($pdo)->execute(7, 42, true);
            assertSameValue(['failed', 0, [], $before, 1], [$r['result'], $r['created'], $pdo->writes, $pdo->state(), $pdo->rollbacks], 'All-or-nothing ' . $bad);
            assertSameValue(true, $r['invalid_dependencies'] !== [], 'Explicit dependency report.');
        }
    },
    'Task32 cleanup preview apply logical retirement retains affixes and repeat timestamps' => function (): void {
        [$pdo, $service] = task32Granted();
        $before = $pdo->state(); $originalItems = $pdo->items; $affixes = $pdo->affixes; $writes = count($pdo->writes);
        $r = $service->execute(7, 42, false, true);
        assertSameValue([14, 0, 14, $before, $writes], [$r['would_retire'], $r['retired'], $r['final_count'], $pdo->state(), count($pdo->writes)], 'Cleanup preview zero writes.');
        $r = $service->execute(7, 42, true, true);
        assertSameValue([14, 0, $affixes, ['weapon' => 901], [902, 904]], [$r['retired'], $r['final_count'], $pdo->affixes, $pdo->equipment, $pdo->drops], 'Only active Task32 retirement, affixes/equipment/drops preserved.');
        $timestamps = [];
        foreach ($originalItems as $id => $item) {
            $new = $pdo->items[$id];
            if ($id > 1000) {
                assertSameValue(true, is_string($new['retired_at']) && $new['retired_at'] !== '', 'Retired timestamp.');
                $timestamps[] = $new['retired_at']; $new['retired_at'] = null;
            }
            assertSameValue($item, $new, 'No snapshot, provenance, other owner or normal item edits.');
        }
        assertSameValue(1, count(array_unique($timestamps)), 'One common UTC retirement timestamp.');
        $before = $pdo->state(); $r = $service->execute(7, 42, true, true);
        assertSameValue(['already_clean', 0, 0, $before], [$r['result'], $r['retired'], $r['final_count'], $pdo->state()], 'Second cleanup idempotent without timestamp reset.');
        assertSameValue(0, preg_match('/\bDELETE\s+FROM\s+character_(?:items|item_affixes)\b/i', implode("\n", $pdo->queries)), 'No item/affix DELETE ever.');
    },
    'Task32 cleanup equipped or combat drop reference rejected in preview and apply' => function (): void {
        foreach (['equipment', 'drops'] as $reference) {
            foreach ([false, true] as $apply) {
                [$pdo, $service] = task32Granted(); $id = task32Generated($pdo, 'shock')['id'];
                $pdo->{$reference}[] = $id; $before = $pdo->state();
                task32Reject(fn () => $service->execute(7, 42, $apply, true), $reference === 'equipment' ? 'test_item_equipped' : 'test_item_referenced');
                assertSameValue($before, $pdo->state(), 'Entire cleanup rejected on any unsafe reference.');
            }
        }
    },
    'Task32 retired grant preview apply retain durable provenance no resurrection or source reuse' => function (): void {
        [$pdo, $service] = task32Granted(); $service->execute(7, 42, true, true);
        $before = $pdo->state(); $writes = count($pdo->writes);
        foreach ([false, true] as $apply) {
            $r = $service->execute(7, 42, $apply);
            assertSameValue([14, 0, 0, 0], [$r['retired_exists'], $r['already_exists'], $r['created'], $r['would_create']], 'Retired results are distinct.');
            assertSameValue(array_fill(0, 14, 'retired_exists'), array_column($r['items'], 'result'), 'Each retired identity reported.');
            assertSameValue([$before, $writes, 14], [$pdo->state(), count($pdo->writes), count($pdo->taskItems())], 'No duplicate/reset/repair.');
        }
        $id = task32Generated($pdo, 'accuracy')['id']; $pdo->items[$id]['definition_key'] = 'worn_dagger';
        task32Reject(fn () => $service->execute(7, 42, true), 'Task32 provenance identity mismatch.');
    },
    'Task32 historically referenced items retire without changing restrictive history FK references' => function (): void {
        [$pdo, $service] = task32Granted(); $item = task32Generated($pdo, 'accuracy');
        $pdo->actions = [['snapshot_weapon_item_id' => $item['id'], 'snapshot_weapon_key' => $item['definition_key'], 'snapshot_base_damage' => 11, 'state' => 'resolved'], ['snapshot_weapon_item_id' => 902]];
        $pdo->receipts = [['item_id' => $item['id'], 'result_payload' => '{"item_id":' . $item['id'] . '}', 'completed_at' => '2026-11-16 00:00:00.000000'], ['item_id' => 901]];
        $history = [$pdo->actions, $pdo->receipts, $pdo->affixes]; $service->execute(7, 42, true, true);
        assertSameValue($history, [$pdo->actions, $pdo->receipts, $pdo->affixes], 'All history bytes/IDs/snapshots retained including normal refs.');
        $row = (new EquipmentRepository($pdo))->lockOwnedItem(42, $item['id']);
        assertSameValue([$item['id'], $item['definition_key'], $item['display_name']], [$row['id'], $row['definition_key'], $row['display_name']], 'Historical ID lookup still possible.');
        assertSameValue(true, $row['retired_at'] !== null, 'Historical row retained with retirement.');
    },
    'Task32 retirement update failure rolls back entire set' => function (): void {
        foreach ([1, 7, 14] as $after) {
            [$pdo, $service] = task32Granted(); $pdo->failRetirementAfter = $after; $before = $pdo->state();
            task32Reject(fn () => $service->execute(7, 42, true, true), 'Injected retirement failure.');
            assertSameValue($before, $pdo->state(), 'No half retirement at ' . $after);
        }
    },
    'Task32 retired inventory excluded from real list count page and ordinary weapon selection' => function (): void {
        [$pdo, $service] = task32Granted(); $service->execute(7, 42, true, true);
        $reader = new ItemRepository($pdo);
        assertSameValue(3, $reader->countOwnedItems(7, 42), 'Retired excluded from count.');
        assertSameValue([902, 903, 904], array_column($reader->ownedItemsPage(7, 42, 25, 0), 'id'), 'Retired excluded from page.');
        $pdo->items = array_filter($pdo->items, static fn ($i) => $i['id'] > 1000); $pdo->equipment = [];
        assertSameValue(null, (new EquipmentRepository($pdo))->ownedUsableWeapon(42), 'Retired swords not ordinary selection candidates.');
    },
    'Task32 authoritative equipment service and raw mutation methods reject retired gear' => function (): void {
        [$pdo, $service] = task32Granted(); $item = task32Generated($pdo, 'accuracy'); $service->execute(7, 42, true, true);
        $item['retired_at'] = '2026-11-16 12:00:00.000000';
        foreach (['equip', 'unequip'] as $command) {
            $store = new FakeEquipmentStore(equipmentCharacter(), [$item]);
            $svc = equipmentService($store); $before = serialize([$store->items, $store->equipment, $store->requests, $store->characters]);
            task32Reject(fn () => $command === 'equip' ? $svc->equip(7, 42, $item['id'], 'weapon', equipmentToken(3202)) : $svc->unequip(7, 42, $item['id'], equipmentToken(3202)), 'Item retired.');
            assertSameValue($before, serialize([$store->items, $store->equipment, $store->requests, $store->characters]), 'No equipment/resource/receipt writes.');
        }
        $repo = new EquipmentRepository($pdo);
        foreach (['equipItem', 'unequipItem', 'equipStarterWeapon'] as $method) {
            task32Reject(fn () => $method === 'equipItem' ? $repo->$method(42, $item['id'], 'weapon') : $repo->$method(42, $item['id']), 'Item retired.');
        }
    },
    'Task32 mixed active retired provenance and partial cleanup never reset prior retirement' => function (): void {
        [$pdo, $service] = task32Granted(); $id = task32Generated($pdo, 'accuracy')['id'];
        $pdo->items[$id]['retired_at'] = '2026-11-01 01:02:03.000004';
        foreach ([false, true] as $apply) {
            $r = $service->execute(7, 42, $apply);
            assertSameValue([1, 13, 0, 13], [$r['retired_exists'], $r['already_exists'], $r['created'], $r['final_count']], 'Distinct active/retired counts.');
        }
        $r = $service->execute(7, 42, true, true);
        assertSameValue([13, '2026-11-01 01:02:03.000004'], [$r['retired'], $pdo->items[$id]['retired_at']], 'Only active targets retired, old timestamp unchanged.');
        $pdo->definitions = []; $pdo->catalogueAffixes = [];
        $r = $service->execute(7, 42, true);
        assertSameValue([14, 0], [$r['retired_exists'], $r['created']], 'Genuine immutable identities need no live catalogue to remain retired.');
    },
    'Task32 completed equip replay after retirement returns original success without reequipping' => function (): void {
        [$store, $service, $id, $original] = task32RetiredEquipmentHistory();
        assertSameValue('equipped', $original['result'], 'Actual successful equip, not a fabricated receipt.');
        $before = serialize($store);
        $replay = $service->equip(7, 42, $id, 'weapon', equipmentToken(3203));
        assertSameValue($original, $replay, 'Persisted semantic result survives subsequent unequip and retirement.');
        assertSameValue($before, serialize($store), 'No new receipt, resource/equipment write, or retirement reversal.');
        assertSameValue([], $store->equipment, 'Successful replay is not a new equip.');
    },
    'Task32 completed unequip replay after retirement returns original success without mutations' => function (): void {
        [$store, $service, $id, , $original] = task32RetiredEquipmentHistory();
        assertSameValue('unequipped', $original['result'], 'Actual successful unequip, not a fabricated receipt.');
        $before = serialize($store);
        assertSameValue($original, $service->unequip(7, 42, $id, equipmentToken(3204)), 'Original persisted unequip result.');
        assertSameValue($before, serialize($store), 'History, timestamps, items, resources and receipts byte-for-byte unchanged.');
    },
    'Task32 retired completed token collisions take precedence over retirement rejection' => function (): void {
        [$store, $service, $id] = task32RetiredEquipmentHistory();
        $before = serialize($store);
        foreach ([
            fn () => $service->equip(7, 42, $id, 'ring', equipmentToken(3203)),
            fn () => $service->equip(7, 42, $id + 1, 'weapon', equipmentToken(3203)),
            fn () => $service->unequip(7, 42, $id + 1, equipmentToken(3204)),
            fn () => $service->unequip(7, 42, $id, equipmentToken(3203)),
            fn () => $service->equip(7, 42, $id, 'weapon', equipmentToken(3204)),
        ] as $collision) {
            task32Reject($collision, 'Item request token collision.');
            assertSameValue($before, serialize($store), 'Different slot/item fingerprint or command cannot replay or mutate history.');
        }
    },
    'Task32 completed retired replays retain ownership authority but precede mutable state checks' => function (): void {
        foreach (['dead', 'active', 'victory_loot'] as $state) {
            [$store, $service, $id, $equip, $unequip] = task32RetiredEquipmentHistory();
            if ($state === 'dead') { $store->characters[42]['life_state'] = 'dead'; }
            else { $store->encounterStatus = $state; }
            $before = serialize($store);
            assertSameValue($equip, $service->equip(7, 42, $id, 'weapon', equipmentToken(3203)), 'Historical equip result, not a new mutation.');
            assertSameValue($unequip, $service->unequip(7, 42, $id, equipmentToken(3204)), 'Historical unequip result, not a new mutation.');
            assertSameValue($before, serialize($store), 'No mutable-state effect on completed replay.');
            task32Reject(fn () => $service->equip(8, 42, $id, 'weapon', equipmentToken(3203)), 'Champion unavailable.');
            task32Reject(fn () => $service->unequip(8, 42, $id, equipmentToken(3204)), 'Champion unavailable.');
            assertSameValue($before, serialize($store), 'Foreign owner cannot access durable result.');
            $error = $state === 'dead' ? 'Equipment is unavailable.' : 'Equipment is unavailable during combat.';
            task32Reject(fn () => $service->equip(7, 42, $id, 'weapon', equipmentToken(3210)), $error);
            task32Reject(fn () => $service->unequip(7, 42, $id, equipmentToken(3211)), $error);
            assertSameValue($before, serialize($store), 'New command validates mutable state before the retired item.');
        }
    },
    'Task32 retired fresh equip and unequip reject despite previous successful history' => function (): void {
        [$store, $service, $id] = task32RetiredEquipmentHistory();
        $before = serialize($store);
        task32Reject(fn () => $service->equip(7, 42, $id, 'weapon', equipmentToken(3210)), 'Item retired.');
        task32Reject(fn () => $service->unequip(7, 42, $id, equipmentToken(3211)), 'Item retired.');
        assertSameValue($before, serialize($store), 'New tokens cannot equip, unequip, recreate receipts, or unretire the item.');
    },
    'Task32 all ordinary selection SQL paths filter retirement while historical ID lookup retains it' => function (): void {
        $item = file_get_contents(__DIR__ . '/../ascii-quest/lib/ItemRepository.php');
        $equipment = file_get_contents(__DIR__ . '/../ascii-quest/lib/EquipmentRepository.php');
        foreach (['countOwnedItems', 'ownedItemsPage', 'equippedItems'] as $method) {
            preg_match('/public function ' . $method . '\([^;{]*\)[^{;]*\{.*?(?=\n    (?:public|private) function|\n})/s', $item, $m);
            assertSameValue(true, str_contains($m[0], 'ci.retired_at IS NULL'), 'Item reader filters ' . $method);
        }
        foreach (['equippedItemsQuery', 'equippedUsableWeapon', 'ownedUsableWeapon'] as $method) {
            preg_match('/(?:public|private) function ' . $method . '\(.*?(?=\n    (?:public|private) function|\n})/s', $equipment, $m);
            assertSameValue(true, str_contains($m[0], 'retired_at IS NULL'), 'Equipment selection filters ' . $method);
        }
        preg_match('/public function lockOwnedItem\(.*?(?=\n    public function)/s', $equipment, $m);
        assertSameValue(false, str_contains($m[0], 'retired_at IS NULL'), 'Historical ID lookup does not erase retired identity.');
        foreach (['ItemRepository.php', 'CombatRepository.php'] as $file) {
            $source = file_get_contents(__DIR__ . '/../ascii-quest/lib/' . $file);
            assertSameValue(1, preg_match('/UPDATE character_items SET character_id[^\n]+retired_at IS NULL/', $source), 'Claim UPDATE authority guarded ' . $file);
        }
        $repo = file_get_contents(__DIR__ . '/../scripts/lib/TestEquipmentGrantRepository.php');
        assertSameValue(0, preg_match('/\bDELETE\s+FROM\s+character_(?:items|item_affixes)/i', $repo), 'Maintenance never deletes items/affixes.');
        assertSameValue(0, preg_match('/UPDATE\s+(?:combat_actions|item_mutation_requests|combat_item_drops|character_equipment)/i', $repo), 'Maintenance never updates history/equipment.');
    },
    'Task32 starter authority never equips or recreates a retired identity' => function (): void {
        $store = new FakeStarterStore(equipmentCharacter(['class_key' => 'warrior']));
        $store->items[1] = inventoryItem(1, '2026-11-01 00:00:00.000000', ['retired_at' => '2026-11-02 00:00:00.000000']);
        $before = serialize([$store->items, $store->equipment, $store->characters]);
        task32Reject(fn () => starterService($store)->grantForNewLockedChampion($store->characters[42], 'warrior'), 'Item retired.');
        assertSameValue($before, serialize([$store->items, $store->equipment, $store->characters]), 'Starter history is not source-key reuse.');
    },
    'Task32 migration guard conditionals and original history constraints stay exact' => function (): void {
        $sql = file_get_contents(__DIR__ . '/../database/migrations/011_test_item_provenance.sql');
        $clean = preg_replace('~/\*.*?\*/|--[^\n]*~s', '', $sql);
        assertSameValue(1, preg_match("/migration_id = '010_combat_item_affix_completion';\s+IF v_count <> 1 THEN\s+SIGNAL/", $clean), 'Missing prerequisite fails closed.');
        assertSameValue(1, preg_match("/migration_id = '011_test_item_provenance';\s+IF v_count <> 0 THEN\s+SIGNAL/", $clean), 'Recorded rerun fails closed.');
        assertSameValue(1, preg_match("/COLUMN_NAME = 'retired_at';\s+IF v_count <> 0 THEN\s+SIGNAL/", $clean), 'Any partial/wrong retirement column fails closed.');
        assertSameValue(0, preg_match('/\b(?:UPDATE|DELETE|TRUNCATE)\s|\bREPLACE\s+INTO\b/i', $clean), 'No migration backfill, history or data mutation.');
        assertSameValue(1, substr_count($clean, 'ADD COLUMN'), 'Only retired_at column.');
        assertSameValue(0, preg_match('/(?:DROP|ADD)\s+FOREIGN\s+KEY/i', $clean), 'No FK changes.');
        foreach (['005_item_inventory_foundation.sql' => 'fk_item_mutation_requests_item', '007_character_equipment.sql' => 'fk_combat_actions_snapshot_weapon_item'] as $file => $fk) {
            $old = file_get_contents(__DIR__ . '/../database/migrations/' . $file);
            assertSameValue(1, preg_match('/' . $fk . '[^;]*?REFERENCES character_items \(id\) ON DELETE RESTRICT/s', $old), 'Original restrictive history FK stays unchanged.');
        }
        $cascade = file_get_contents(__DIR__ . '/../database/migrations/006_item_generation_and_drops.sql');
        assertSameValue(1, preg_match('/fk_character_item_affixes_item[^;]*?REFERENCES character_items \(id\) ON DELETE CASCADE/s', $cascade), 'Existing cascade unchanged but never triggered by retirement.');
    },
    'Task32 CLI parser exactly four modes explicit IDs and strict invalid combinations' => function (): void {
        foreach ([[], ['--apply'], ['--cleanup'], ['--cleanup', '--apply']] as $flags) {
            $r = TestEquipmentGrantArguments::parse(array_merge(['--user-id=7', '--character-id', '42'], $flags));
            assertSameValue([7, 42, in_array('--apply', $flags, true), in_array('--cleanup', $flags, true)], array_values($r), 'Exact modes.');
        }
        foreach ([[], ['--apply'], ['--user-id', '7'], ['--character-id', '42'], ['--user-id', '7', '--character-id'],
            ['--user-id=0', '--character-id=42'], ['--user-id=-1', '--character-id=42'], ['--user-id=01', '--character-id=42'],
            ['--user-id=1.0', '--character-id=42'], ['--user-id=1e2', '--character-id=42'], ['--user-id=+7', '--character-id=42'],
            ['--user-id=999999999999999999999999', '--character-id=42'], ['--user-id=7', '--character-id= 42'],
            ['--user-id=7', '--character-id=42', '--user-id=7'], ['--user-id=7', '--character-id=42', '--character-id=43'], ['--user-id=7', '--character-id=42', '--apply', '--apply'],
            ['--user-id=7', '--character-id=42', '--cleanup', '--cleanup'], ['--user-id=7', '--character-id=42', '--cleanup=false'],
            ['--user-id=7', '--character-id=42', '--apply=1'], ['--user-id=7', '--character-id=42', '--unequip'],
            ['--user-id=7', '--character-id=42', '--preview'], ['--user-id=7', '--character-id=42', '--all'],
            ['--user-id=7', '--character-id=42', 'extra']] as $args) {
            $rejected = false;
            try { TestEquipmentGrantArguments::parse($args); } catch (InvalidArgumentException $e) { $rejected = true; }
            assertSameValue(true, $rejected, 'Invalid CLI ' . json_encode($args));
        }
        $cli = file_get_contents(__DIR__ . '/../scripts/grant_test_equipment.php');
        assertSameValue(true, strpos($cli, "PHP_SAPI !== 'cli'") < strpos($cli, 'require_once'), 'Web guard before any loading.');
        assertSameValue(true, strpos($cli, 'TestEquipmentGrantArguments::parse') < strpos($cli, '/../ascii-quest/db.php'), 'Invalid arguments before DB bootstrap.');
        foreach (glob(__DIR__ . '/../ascii-quest/*.php') as $web) {
            assertSameValue(false, str_contains(file_get_contents($web), 'TestEquipmentGrant'), 'No maintenance browser endpoint.');
        }
        $output = []; $code = 0;
        exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../scripts/grant_test_equipment.php') . ' --user-id=7 --character-id=42 --unequip 2>&1', $output, $code);
        assertSameValue(2, $code, 'Real CLI invalid args exit before missing local db.php.');
        assertSameValue(true, str_starts_with(implode("\n", $output), 'Usage:'), 'Safe output, no bootstrap leak.');
    },
    'Task32 Migration011 exact guarded CHECK and retirement column only no historical mutations and read-only verification' => function (): void {
        $sql = file_get_contents(__DIR__ . '/../database/migrations/011_test_item_provenance.sql');
        $verify = file_get_contents(__DIR__ . '/../database/migrations/011_test_item_provenance_verify.sql');
        $clean = preg_replace('~/\*.*?\*/|--[^\n]*~s', '', $sql);
        $readOnly = preg_replace('~/\*.*?\*/|--[^\n]*~s', '', $verify);
        assertSameValue(1, substr_count($clean, 'ALTER TABLE'), 'Only one DDL change.');
        assertSameValue(1, preg_match("/ALTER TABLE character_items\s+DROP CONSTRAINT chk_character_items_source_type,\s+ADD CONSTRAINT chk_character_items_source_type\s+CHECK \(source_type IN \('starter', 'combat_drop', 'maintenance_test'\)\),\s+ADD COLUMN retired_at DATETIME\(6\) NULL;/", $clean), 'Exact replacement plus nullable retirement.');
        foreach (['information_schema.COLUMNS', "COLUMN_NAME = 'retired_at'", 'partial retirement column', 'DATETIME_PRECISION', 'IS_NULLABLE', 'fk_item_mutation_requests_item', 'fk_combat_actions_snapshot_weapon_item', "DELETE_RULE = 'RESTRICT'"] as $check) {
            assertSameValue(true, str_contains($sql . $verify, $check), 'Retirement schema guard/verification ' . $check);
        }
        foreach (['010_combat_item_affix_completion', '011_test_item_provenance', 'information_schema.CHECK_CONSTRAINTS', "TABLE_NAME = 'character_items'", "CONSTRAINT_NAME = 'chk_character_items_source_type'", 'BINARY v_clause NOT IN', "source_typein(''starter'',''combat_drop'')", 'partial install', 'GET_LOCK', 'RESIGNAL', 'implicitly commits', 'manual recovery'] as $guard) {
            assertSameValue(true, str_contains($sql, $guard), 'Guard/DDL limitation ' . $guard);
        }
        assertSameValue(true, strpos($sql, 'requires migration 010') < strpos($sql, 'ALTER TABLE character_items'), 'Prereq before DDL.');
        assertSameValue(true, strpos($sql, 'already applied') < strpos($sql, 'ALTER TABLE character_items'), 'Rerun fails before DDL.');
        assertSameValue(0, preg_match('/\b(?:UPDATE|DELETE|REPLACE|TRUNCATE)\s+(?:INTO\s+|FROM\s+)?(?:character_items|character_item_affixes|item_definitions|item_affix_definitions)\b/i', $clean), 'No history/catalogue writes.');
        assertSameValue(1, preg_match_all('/\bINSERT\s+INTO\s+schema_migrations\b/i', $clean), 'Only marker insert.');
        assertSameValue(0, preg_match('/\b(?:ALTER|INSERT|UPDATE|DELETE|DROP|CREATE|TRUNCATE|CALL)\b|\bREPLACE\s+INTO\b/i', $readOnly), 'Verify read-only.');
        assertSameValue(true, str_contains($verify, "source_typein(''starter'',''combat_drop'',''maintenance_test'')"), 'Verify exact three-source shape.');
    },
];

foreach (['grant', 'cleanup'] as $mode) {
    foreach ([false, true] as $apply) {
        $tests['Task32 ' . $mode . ($apply ? ' apply' : ' preview') . ' ownership dead unresolved combat closed guards'] = function () use ($mode, $apply): void {
            foreach (['wrong_owner', 'unavailable', 'dead', 'active', 'victory_loot', 'closed', 'none'] as $state) {
                [$pdo, $service] = $mode === 'cleanup' ? task32Granted() : [new Task32Pdo(), null];
                $service ??= task32Service($pdo);
                if ($state === 'wrong_owner') { $pdo->characters[42]['user_id'] = 8; }
                elseif ($state === 'unavailable') { unset($pdo->characters[42]); }
                elseif ($state === 'dead') { $pdo->characters[42]['life_state'] = 'dead'; }
                elseif ($state !== 'none') { $pdo->encounter = $state; }
                $before = $pdo->state();
                if (in_array($state, ['wrong_owner', 'unavailable'], true)) {
                    task32Reject(fn () => $service->execute(7, 42, $apply, $mode === 'cleanup'), 'Champion unavailable.');
                } else {
                    $r = $service->execute(7, 42, $apply, $mode === 'cleanup');
                    $expected = $state === 'dead' ? 'skipped_dead' : (in_array($state, ['active', 'victory_loot'], true) ? 'deferred_combat' : ($mode === 'cleanup' ? ($apply ? 'retired' : 'would_cleanup') : ($apply ? 'created' : 'preview')));
                    assertSameValue($expected, $r['result'], 'Guard ' . $state);
                }
                if (!in_array($state, ['closed', 'none'], true) || !$apply) { assertSameValue($before, $pdo->state(), 'No forbidden mutation.'); }
            }
        };
    }
}

foreach (task32Expected() as $key => [$base, $affix, $value, $name, $line, $section, $stat]) {
    $tests['Task32 real ItemRepository projector EquipmentRepository aggregator CharacterStats provider ' . $key] = function () use ($key, $base, $affix, $value, $name, $line, $section, $stat): void {
        [$pdo] = task32Granted();
        $reader = new ItemRepository($pdo);
        $rows = $reader->ownedItemsPage(7, 42, 100, 0);
        assertSameValue(17, $reader->countOwnedItems(7, 42), '3 old inventory +14 new; equipped starter excluded.');
        $affixes = $reader->affixesForItems(array_column($rows, 'id'));
        $item = task32Generated($pdo, $key);
        $row = array_values(array_filter($rows, static fn ($r) => $r['id'] === $item['id']))[0];
        $public = (new ItemProjector())->projectItem($row, $affixes[$row['id']]);
        assertSameValue([$name, [$line], 'magic', 10], [$public['display_name'], $public['stat_lines'], $public['rarity'], $public['item_level']], 'Real inventory/projector exact public contract.');
        // Simulate deliberate browser equip ONE item, not impossible simultaneous weapons/amulets.
        $pdo->equipment = [$row['snapshot_equipment_slot'] => $row['id']];
        $equipment = new EquipmentRepository($pdo);
        $equipped = $equipment->readEquippedItems(42);
        $totals = (new EquipmentStatAggregator())->aggregate($equipped);
        assertSameValue($value, $totals[$item['affixes'][0]['modifier_type']], 'Canonical aggregator consumes saved affix.');
        $provider = new PersistentCombatEquipmentProvider($equipment, CombatDefinitionRegistry::fromDefaultConfig(), new EquipmentStatAggregator());
        $character = $pdo->characters[42]; $baseline = CharacterStats::calculate($character);
        $stats = $provider->characterStats($character);
        $expected = $stat === 'block_rate' ? 1.5 : $baseline[$section][$stat] + $value;
        assertFloatValue((float) $expected, (float) $stats[$section][$stat], 'No alternate production stat path.');
        if (in_array($stat, ['accuracy', 'critical_chance', 'critical_damage'], true)) {
            assertFloatValue((float) $expected, (float) $provider->offensiveSnapshot($character, 'prototype_weapon_attack')['snapshot_' . $stat], 'Offense snapshot.');
        } elseif ($section === 'utility' && $stat !== 'life_on_hit') {
            $snapshotKey = in_array($key, ['poison', 'bleed', 'burn', 'freeze', 'shock'], true) ? $key . '_status_damage' : $stat;
            assertSameValue(7, $provider->offensiveSnapshot($character, 'prototype_weapon_attack')['snapshot_' . $snapshotKey], 'Real immutable combat potency.');
        } elseif ($stat === 'dodging') { assertFloatValue((float) $expected, $provider->currentDefense($character)['dodging'], 'Current defense.'); }
        elseif ($stat === 'block_rate') { assertFloatValue(1.5, $provider->currentDefense($character)['block_rate'], 'Current defense factor.'); }
    };
}

foreach (['poison', 'bleed', 'burn', 'freeze', 'shock'] as $status) {
    $tests['Task32 real granted ' . $status . ' potency7 actual status service inclusive35 not36'] = function () use ($status): void {
        [$granted] = task32Granted(); $item = task32Generated($granted, $status);
        foreach ([[35, true], [36, false]] as [$roll, $success]) {
            [$pdo, $clock, $provider, $random, $service] = task31Combat([$item], [1, 100, 100, $roll]);
            assertFloatValue(35.0, $provider->characterStats($pdo->characters[42])['utility']['status_effect_chance'], 'Authoritative Task30 7*5 percent.');
            $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(3201)); task26Advance($clock); $service->state(7, 42);
            assertSameValue([7, $success ? [$status] : [], 4], [$pdo->actions[1]['snapshot_' . $status . '_status_damage'], array_column(task29Active($pdo), 'status_key'), $random->integerCalls], 'Actual resolver inclusive35.');
            if ($success) { assertSameValue(7, task29Active($pdo)[0]['potency'], 'Unscaled tick/slow/shock potency.'); }
            if ($status === 'poison') { assertSameValue([7, 12], [$pdo->actions[1]['snapshot_poison_damage'], $pdo->actions[1]['resolved_damage']], 'Poison7 ALSO direct damage, hit5+7.'); }
        }
    };
}
foreach (['poison', 'bleed', 'burn'] as $status) {
    $tests['Task32 granted ' . $status . ' actual first tick is7 with retained direct damage'] = function () use ($status): void {
        [$granted] = task32Granted();
        [$pdo, $clock, , , $service] = task31Combat([task32Generated($granted, $status)], [1, 100, 100, 35]);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(3205)); task26Advance($clock); $service->state(7, 42);
        task29At($clock, 2); $service->state(7, 42);
        assertSameValue([ucfirst($status) . ' deals 7 damage.'], array_column(task29TickEvents($pdo), 'message'), 'Actual production tick uses potency7.');
        assertSameValue(100000 - ($status === 'poison' ? 12 : 5) - 7, $pdo->encounters[91]['enemy_current_hp'], 'Direct and tick kept independent.');
    };
}
$tests['Task32 granted freeze slows subsequent real enemy action duration exactly7 percent'] = function (): void {
    [$granted] = task32Granted();
    [$pdo, $clock, , , $service] = task31Combat([task32Generated($granted, 'freeze')], [1, 100, 100, 35, 100, 100]);
    $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(3206)); task26Advance($clock); $service->state(7, 42);
    $pdo->encounters[91]['next_enemy_decision_timeline_ms'] = 4000;
    $service->state(7, 42);
    $enemy = array_values(array_filter($pdo->actions, static fn ($a) => $a['actor'] === 'enemy'))[0];
    assertSameValue(['fire_slam', 4000, 6140, 10000], [$enemy['definition_key'], $enemy['started_timeline_ms'], $enemy['resolves_timeline_ms'], $enemy['cooldown_ready_timeline_ms']], '2000ms *1.07 =2140ms; cooldown unchanged.');
};
$tests['Task32 granted shock amplifies subsequent direct hit exactly7 percent not own hit'] = function (): void {
    [$granted] = task32Granted();
    [$pdo, $clock, , , $service] = task31Combat([task32Generated($granted, 'accuracy'), task32Generated($granted, 'shock')], [1, 100, 100, 35, 1, 100, 100, 36]);
    $pdo->encounters[91]['player_actions_remaining'] = 2;
    $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(3207)); task26Advance($clock); $service->state(7, 42);
    assertSameValue(11, $pdo->actions[1]['resolved_damage'], 'Own hit not amplified.');
    task29At($clock, 2.5); $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(3208));
    task29At($clock, 3.5); $service->state(7, 42);
    assertSameValue(12, $pdo->actions[2]['resolved_damage'], 'round(11*1.07)=12 subsequent direct damage.');
};
$tests['Task32 granted vampiric contact heals exactly7 via actual service and preserves Mana'] = function (): void {
    [$granted] = task32Granted();
    [$pdo, $clock, , , $service] = task31Combat([task32Generated($granted, 'life_on_hit')], [1, 100, 100]);
    $pdo->characters[42]['current_hp'] = 100; $mana = $pdo->characters[42]['current_mana'];
    $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(3209)); task26Advance($clock); $service->state(7, 42);
    assertSameValue([107, $mana, 'You recover 7 Life.'], [$pdo->characters[42]['current_hp'], $pdo->characters[42]['current_mana'], task26HealingEvents($pdo)[0]['message']], 'Real canonical life-on-hit.');
};
return $tests;
