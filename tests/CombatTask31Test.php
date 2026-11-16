<?php
declare(strict_types=1);
require_once __DIR__ . '/Task31Fixtures.php';

$tests = [
    'Task31 exact new key sets counts mappings and preserved legacy generation' => function (): void {
        $file = '010_combat_item_affix_completion.sql';
        assertSameValue(array_keys(task31Spec()),array_column(task31InsertRows($file,'item_affix_definitions'),'affix_key'),'No additional/duplicate affix keys.');
        assertSameValue(32,count(task31InsertRows($file,'item_affix_tiers')),'Exactly32 new tiers.');
        assertSameValue(33,count(task31InsertRows($file,'item_affix_category_rules')),'Exactly33 category pairs.');
        [$ds,$as] = task31Catalogue();
        assertSameValue(339,array_sum(array_map(static fn ($d) => count($d['allowed_affix_families']),$ds)),'Exact all-loot family pairs:80 existing +124 inherited old families +135 new families.');
        $oldKeys = ['hunter','brutal','stout','focused','strong','swift','vital','energetic','fated','armoured','of_health','of_mana','of_flame_warding','of_frost_warding','of_haste','of_channeling'];
        assertSameValue($oldKeys,array_column(array_slice($as,0,16),'affix_key'),'Legacy16 untouched.');
        foreach (array_slice($as,0,16) as $a) {
            foreach ($ds as $d) {
                if (in_array($d['category'],$a['categories'],true)) {
                    $item = task31Generate($d['definition_key'],[$a['affix_key']]);
                    assertSameValue(['magic',[$a['affix_key']]],[$item['rarity'],array_column($item['affixes'],'affix_key')],'Legacy generation retained: ' . $a['affix_key'] . '/' . $d['definition_key']);
                }
            }
        }
    },
    'Task31 migration prerequisite rerun partial guards precede atomic catalogue-only inserts' => function (): void {
        $sql = equipmentMigrationSql('010_combat_item_affix_completion.sql');
        foreach (["migration_id = '009_combat_status_effects'", "migration_id = '010_combat_item_affix_completion'", 'if v_count <> 1 then', 'if v_count <> 0 then', 'signal sqlstate', 'partial install', 'declare exit handler for sqlexception', 'rollback;', 'resignal;', 'start transaction;', 'commit;'] as $fragment) {
            assertEquipmentSqlContains($sql, $fragment, 'Safety contract.');
        }
        assertSameValue(true, strpos($sql, 'partial install') < strpos($sql, 'start transaction;'), 'Guard before writes.');
        assertSameValue(true, strpos($sql, 'start transaction;') < strpos($sql, 'insert into item_definitions'), 'Atomic writes.');
        assertSameValue(0, preg_match('/\b(update|delete|replace|truncate|alter)\b/', $sql), 'Additive only, no schema changes.');
        preg_match_all('/insert into (\w+)/', $sql, $m);
        assertSameValue(['item_definitions', 'item_affix_definitions', 'item_affix_tiers', 'item_affix_category_rules', 'item_definition_affix_families', 'schema_migrations'], $m[1], 'Only catalogue/rule inserts and migration identity.');
        foreach (['character_items', 'character_item_affixes', 'character_equipment', 'basic_sword', 'basic_wand', 'basic_dagger', 'basic_mace', 'combat_actions'] as $forbidden) {
            assertSameValue(false, str_contains($sql, $forbidden), 'No historical/starter mutation: ' . $forbidden);
        }
        foreach (['item_definitions where definition_key in', 'item_affix_definitions where affix_key in', 'item_affix_tiers where affix_key in', 'item_affix_category_rules where affix_key in', 'item_definition_affix_families where family_key in'] as $fragment) {
            assertEquipmentSqlContains($sql, $fragment, 'Detect partial catalogue.');
        }
    },
    'Task31 read-only verify checks exact definitions affixes tiers categories mappings and identity' => function (): void {
        $verify = equipmentMigrationSql('010_combat_item_affix_completion_verify.sql');
        assertSameValue(0, preg_match('/\b(insert|update|delete|alter|create|drop|truncate|call|replace)\b/', $verify), 'Read-only verification.');
        foreach (['expected_item_definitions', 'expected_item_affix_definitions', 'expected_item_affix_tiers', 'expected_item_affix_category_rules', 'missing family:', 'invalid new family:', 'unexpected item_affix_tiers', 'unexpected item_affix_category_rules', 'actual.is_active = 1', '009_combat_status_effects', '010_combat_item_affix_completion'] as $fragment) {
            assertEquipmentSqlContains($verify, $fragment, 'Verify completeness.');
        }
        foreach (task31Spec() as $key => $spec) {
            foreach ([$key, $spec[0], $spec[1], $spec[2], $spec[4]] as $value) { assertEquipmentSqlContains($verify, "'" . $value . "'", 'Exact key vocabulary.'); }
        }
        // Every literal inserted catalogue row must also have an exact read-only expectation.
        $raw = file_get_contents(__DIR__ . '/../database/migrations/010_combat_item_affix_completion_verify.sql');
        foreach (['item_definitions', 'item_affix_definitions', 'item_affix_tiers', 'item_affix_category_rules'] as $table) {
            foreach (task31InsertRows('010_combat_item_affix_completion.sql', $table) as $row) {
                $values = array_map(static fn ($v) => $v === null ? 'NULL' : (is_int($v) ? (string) $v : "'{$v}'"), array_values($row));
                // Strip first-row aliases only, retaining literal expectations for independent comparison.
                $normalized = preg_replace('/ AS [a-z_]+/', '', $raw);
                assertSameValue(true, str_contains($normalized, 'SELECT ' . implode(', ', $values)), 'Verifier exact row for ' . $table);
            }
        }
    },
    'Task31 exact nine definitions values slots six weapons and starter exclusion' => function (): void {
        $rows = task31InsertRows('010_combat_item_affix_completion.sql', 'item_definitions');
        $expected = [
            ['worn_sword','Sword','weapon','sword','weapon','physical',4,7,0],
            ['worn_dagger','Dagger','weapon','dagger','weapon','physical',3,5,0],
            ['worn_mace','Mace','weapon','mace','weapon','physical',5,7,0],
            ['ash_wand','Wand','weapon','wand','weapon','fire',3,6,0],
            ['hide_gloves','Hide Gloves','armour','gloves','gloves',null,0,0,2],
            ['hide_boots','Hide Boots','armour','boots','boots',null,0,0,2],
            ['hide_belt','Hide Belt','armour','belt','belt',null,0,0,3],
            ['copper_amulet','Copper Amulet','jewellery','amulet','amulet',null,0,0,0],
            ['copper_charm','Copper Charm','jewellery','charm','charm',null,0,0,0],
        ];
        assertSameValue($expected, array_map(static fn ($d) => array_slice(array_values($d), 0, 9), $rows), 'Exact keys and base values.');
        foreach ($rows as $row) {
            assertSameValue([1, 'rare', 100], [$row['minimum_item_level'], $row['maximum_rarity'], $row['loot_weight']], 'New level/rarity/weight.');
        }
        [$definitions] = task31Catalogue();
        $slots = array_values(array_unique(array_column($definitions, 'equipment_slot'))); sort($slots);
        $wanted = ['helm', 'gloves', 'chest', 'ring', 'weapon', 'off-hand', 'amulet', 'belt', 'charm', 'boots']; sort($wanted);
        assertSameValue($wanted, $slots, 'Complete ten-slot catalogue.');
        $weapons = array_column(array_filter($definitions, static fn ($d) => $d['category'] === 'weapon'), 'subtype'); sort($weapons);
        assertSameValue(['axe', 'dagger', 'mace', 'staff', 'sword', 'wand'], $weapons, 'All six weapon types.');
        assertSameValue(15, count($definitions), 'Six old + nine new loot definitions.');
        foreach (['basic_sword','basic_wand','basic_mace','basic_dagger'] as $key) {
            assertSameValue(false, in_array($key, array_column($definitions,'definition_key'), true), 'No starter in loot catalogue.');
        }
    },
    'Task31 mapping SQL covers ALL loot by category without overwriting old rules' => function (): void {
        $sql = equipmentMigrationSql('010_combat_item_affix_completion.sql');
        foreach (['select distinct definitions.definition_key, affixes.family_key', 'inner join item_affix_category_rules as rules on rules.category = definitions.category', 'inner join item_affix_definitions as affixes on affixes.affix_key = rules.affix_key', 'where definitions.loot_weight > 0', 'and not exists', 'existing.definition_key = definitions.definition_key', 'existing.family_key = affixes.family_key'] as $fragment) {
            assertEquipmentSqlContains($sql, $fragment, 'All existing/new loot mappings; no key-limited mapping.');
        }
        [$definitions, $affixes] = task31Catalogue();
        $registry = task31Registry();
        foreach ($definitions as $d) {
            $actual = array_merge($registry->eligibleAffixes($d,'prefix',1,[]),$registry->eligibleAffixes($d,'suffix',1,[]));
            $expected = array_column(array_filter($affixes, static fn ($a) => in_array($d['category'], $a['categories'], true)), 'affix_key');
            $actual = array_column($actual, 'affix_key'); sort($actual); sort($expected);
            assertSameValue($expected, $actual, 'All existing/new eligibility: ' . $d['definition_key']);
        }
        assertSameValue(32, count($affixes), 'Original16 + new16; no duplicate poison status.');
        assertSameValue(1, count(array_filter($affixes, static fn ($a) => $a['modifier_type'] === 'poison_damage')), 'Poison is shared direct/status potency.');
    },
    'Task31 unknown vocabulary family category excluded families and minimum levels remain enforced' => function (): void {
        [$ds, $as] = task31Catalogue();
        $a = $as[0]; $a['affix_key'] = 'unknown'; $a['family_key'] = 'unknown'; $a['modifier_type'] = 'god_mode';
        $d = $ds[0]; $d['allowed_affix_families'][] = 'unknown';
        $r = task31Registry([$d], [$a]);
        assertSameValue([], $r->eligibleAffixes($d,'prefix',100,[]), 'Unknown silently filtered.');
        $r = task31Registry();
        assertSameValue([], $r->eligibleDefinitions(0), 'Definition minimum level.');
        assertSameValue([], $r->eligibleAffixes($d,'prefix',0,[]), 'Affix minimum level.');
        $d['allowed_affix_families'] = [];
        assertSameValue([], $r->eligibleAffixes($d,'prefix',10,[]), 'Family allowlist enforced.');
        $d = $ds[0];
        assertSameValue([], $r->eligibleAffixes($d,'prefix',10,$d['allowed_affix_families']), 'Family uniqueness exclusions.');
        $a = $as[0]; $a['minimum_item_level'] = 10;
        $r = task31Registry([$d],[$a]);
        assertSameValue([], $r->eligibleAffixes($d,'prefix',9,[]), 'Raised affix level.');
        assertSameValue(1, count($r->eligibleAffixes($d,'prefix',10,[])), 'Raised affix level inclusive.');
    },
    'Task31 Rare family uniqueness and invalid category combinations cannot generate' => function (): void {
        foreach ([['worn_sword','of_guarding'], ['hide_chest','keen'], ['hide_boots','of_guarding'], ['copper_ring','of_guarding'], ['wooden_shield','of_evasion'], ['worn_sword','of_storm_warding']] as [$d,$a]) {
            $failed = false;
            try { task31Generate($d,[$a]); } catch (DomainException) { $failed = true; }
            assertSameValue(true, $failed, 'Ineligible combo ' . $d . '/' . $a);
        }
        [$ds,$as] = task31Catalogue(); $d = $ds[0];
        $prefix = $as[0]; $suffix = $as[10]; $suffix['family_key'] = $prefix['family_key'];
        $item = (new ItemGenerator(task31Registry([$d],[$prefix,$suffix])))->generate(1,new QueuedItemRandomSource([1,91,1,1,3]));
        assertSameValue(['magic',1], [$item['rarity'],count($item['affixes'])], 'Impossible unique-family Rare downgrades, never duplicate.');
    },
];

foreach (task31Spec() as $key => [$family,$modifier,$position,$fragment,$operation,$categories,$range,$label]) {
    $tests["Task31 exact catalogue {$key} family modifier operation position categories tiers"] = function () use ($key,$family,$modifier,$position,$fragment,$operation,$categories,$range): void {
        $rows = array_column(task31InsertRows('010_combat_item_affix_completion.sql','item_affix_definitions'),null,'affix_key');
        assertSameValue([$key,$family,$position,$fragment,$modifier,$operation,1,100], array_values($rows[$key]), 'Exact affix row.');
        $rules = task31InsertRows('010_combat_item_affix_completion.sql','item_affix_category_rules');
        assertSameValue($categories, array_column(array_filter($rules,static fn ($r) => $r['affix_key'] === $key),'category'), 'Exact categories.');
        $tiers = array_values(array_filter(task31InsertRows('010_combat_item_affix_completion.sql','item_affix_tiers'),static fn ($r) => $r['affix_key'] === $key));
        assertSameValue([
            ['affix_key'=>$key,'tier'=>1,'minimum_item_level'=>1,'minimum_value'=>$range[0],'maximum_value'=>$range[1]],
            ['affix_key'=>$key,'tier'=>2,'minimum_item_level'=>10,'minimum_value'=>$range[2],'maximum_value'=>$range[3]],
        ],$tiers,'Exact level and range.');
    };
    $tests["Task31 {$key} registry activation category family tiers and exclusion"] = function () use ($key,$family,$position,$categories): void {
        [$definitions] = task31Catalogue(); $r = task31Registry();
        foreach ($definitions as $d) {
            $keys = array_column($r->eligibleAffixes($d,$position,1,[]),'affix_key');
            assertSameValue(in_array($d['category'],$categories,true),in_array($key,$keys,true),'Category ' . $d['definition_key']);
            assertSameValue(false,in_array($key,array_column($r->eligibleAffixes($d,$position,1,[$family]),'affix_key'),true),'Excluded family.');
            $d['allowed_affix_families'] = [];
            assertSameValue([], $r->eligibleAffixes($d,$position,1,[]),'Definition family mandatory.');
        }
        [, $as] = task31Catalogue(); $a = array_column($as,null,'affix_key')[$key];
        assertSameValue([1],array_column($r->eligibleTiers($a,1),'tier'),'Only tier1 at1.');
        assertSameValue([1],array_column($r->eligibleTiers($a,9),'tier'),'Only tier1 at9.');
        assertSameValue([1,2],array_column($r->eligibleTiers($a,10),'tier'),'Tier2 at10.');
    };
    foreach ([1,2] as $tier) {
        $tests["Task31 deterministic {$key} tier{$tier} inclusive min max Magic and exact public label"] = function () use ($key,$position,$modifier,$operation,$categories,$range,$label,$tier): void {
            $definition = in_array('weapon',$categories,true) ? 'worn_sword' : (in_array('armour',$categories,true) ? 'hide_gloves' : 'wooden_shield');
            foreach ([true,false] as $minimum) {
                $value = $range[($tier-1)*2+($minimum ? 0 : 1)];
                $item = task31Generate($definition,[$key],$tier===1?1:10,$tier,$minimum);
                assertSameValue(['magic',1,$key,$tier,$value],[$item['rarity'],count($item['affixes']),$item['affixes'][0]['affix_key'],$item['affixes'][0]['tier'],$item['affixes'][0]['rolled_value']],'Deterministic inclusive roll.');
                $projected = (new ItemProjector())->projectItem(['id'=>1]+$item,$item['affixes']);
                $expected = $operation === 'flat' ? '+' . $value . ' ' . $label : sprintf('+%d.%02d%% %s',intdiv($value,100),$value%100,$label);
                assertSameValue([$expected],$projected['stat_lines'],'Exact visible raw stat.');
                assertSameValue(['position','display_fragment','modifier_type','operation','value','tier'],array_keys($projected['affixes'][0]),'Existing public contract only.');
                foreach (['chance_roll','selection_weight','family_key','affix_key','proc_chance','minimum_value','maximum_value'] as $hidden) {
                    assertSameValue(false,str_contains(json_encode($projected), '"'.$hidden.'"'),'No hidden generation or proc info.');
                }
            }
        };
    }
    foreach ($categories as $category) {
        $tests["Task31 deterministic {$key} every {$category} definition Magic and legal Rare"] = function () use ($key,$category,$position): void {
            [$ds] = task31Catalogue();
            foreach (array_filter($ds,static fn ($d) => $d['category'] === $category) as $d) {
                $magic = task31Generate($d['definition_key'],[$key]);
                assertSameValue([$key],array_column($magic['affixes'],'affix_key'),'Full catalogue selection ' . $d['definition_key']);
                $pair = $position === 'prefix' ? [$key,'of_health'] : ['strong',$key];
                $rare = task31Generate($d['definition_key'],$pair);
                assertSameValue(['rare',['prefix','suffix'],$pair],[$rare['rarity'],array_column($rare['affixes'],'position'),array_column($rare['affixes'],'affix_key')],'Rare has valid positions.');
                assertSameValue(2,count(array_unique(array_column($rare['affixes'],'family_key'))),'Rare family uniqueness.');
                assertSameValue(2,count(array_unique(array_column($rare['affixes'],'affix_key'))),'Rare key uniqueness.');
            }
        };
    }
}

// Literal combat-path expectations prove real generated rows, not synthetic stat injection.
foreach (['keen'=>['accuracy',18.0], 'deadly'=>['critical_chance',18.0], 'savage'=>['critical_damage',30]] as $key => [$stat,$expected]) {
    $tests["Task31 generated {$key} persists through repository aggregator CharacterStats offense combat"] = function () use ($key,$stat,$expected): void {
        $generated = task31Generate('worn_sword',[$key]);
        [$pdo,$clock,$provider,$random,$service,$repository,$equipmentPdo] = task31Combat([$generated],[1,100,1]);
        $stats = $provider->characterStats($pdo->characters[42]);
        assertSameValue($expected,$stats['combat'][$stat],'Authoritative stats.');
        $snapshot = $provider->offensiveSnapshot($pdo->characters[42],'prototype_weapon_attack');
        assertSameValue($expected,$snapshot['snapshot_'.$stat],'Offense provider.');
        $service->startPlayerAction(7,42,'prototype_weapon_attack',equipmentToken(3101));
        task26Advance($clock); $state = $service->state(7,42);
        assertSameValue($expected,$pdo->actions[1]['snapshot_'.$stat],'Immutable action snapshot.');
        assertSameValue('CRITICAL', $state['battle_events'][0]['outcome'], 'Actual resolver uses chance.');
        assertSameValue($key === 'savage' ? 14 : 13,$pdo->actions[1]['resolved_damage'],'Real critical multiplier uses flat bonus, never bp.');
        assertSameValue(true,(bool) array_filter($equipmentPdo->queries,static fn ($q) => str_contains($q,'FROM character_item_affixes')),'Real repository affix read.');
        assertSameValue($generated['affixes'][0]['rolled_value'], $repository->lockEquippedItems(42)[0]['affixes'][0]['rolled_value'],'No synthetic stat bypass.');
    };
}
$tests['Task31 generated Accuracy shifts inclusive hit boundary not only snapshot'] = function (): void {
    foreach ([[68,'HIT',3],[69,'MISS',1]] as [$roll,$outcome,$calls]) {
        [$pdo,$clock,, $random,$service] = task31Combat([task31Generate('worn_sword',['keen'])],$outcome==='HIT'?[$roll,100,100]:[$roll]);
        $service->startPlayerAction(7,42,'prototype_weapon_attack',equipmentToken(3102)); task26Advance($clock); $state = $service->state(7,42);
        assertSameValue([$outcome,$calls],[$state['battle_events'][0]['outcome'],$random->integerCalls],'Accuracy18 -> hit68 inclusive.');
    }
};
$tests['Task31 generated Vampiric heals on real combat contact through repository'] = function (): void {
    [$pdo,$clock,$provider,,$service] = task31Combat([task31Generate('copper_ring',['vampiric'])],[1,100,100]);
    assertSameValue(3,$provider->characterStats($pdo->characters[42])['utility']['life_on_hit'],'Shared utility.');
    $service->startPlayerAction(7,42,'prototype_weapon_attack',equipmentToken(3103)); task26Advance($clock); $state = $service->state(7,42);
    assertSameValue([148,'You recover 3 Life.'],[$pdo->characters[42]['current_hp'],task26HealingEvents($pdo)[0]['message']],'Actual service healing.');
};
foreach (['flaming'=>'fire','stormcharged'=>'lightning','frosted'=>'cold','venomous'=>'poison'] as $key=>$element) {
    $tests["Task31 generated {$key} reaches actual elemental resolver via canonical persistence"] = function () use ($key,$element): void {
        [$pdo,$clock,$provider,,$service] = task31Combat([task31Generate('copper_amulet',[$key])],$element==='poison'?[1,100,100,16]:[1,100,100]);
        assertSameValue(3,$provider->characterStats($pdo->characters[42])['utility'][$element.'_damage'],'Shared elemental utility.');
        $service->startPlayerAction(7,42,'prototype_weapon_attack',equipmentToken(3104)); task26Advance($clock); $state = $service->state(7,42);
        assertSameValue([3,8],[$pdo->actions[1]['snapshot_'.$element.'_damage'],$pdo->actions[1]['resolved_damage']],'Unarmed physical5 + elemental3.');
        if ($element==='poison') { assertSameValue([],task29Active($pdo),'Failed16 status leaves direct poison3.'); }
    };
}
foreach (['venomous'=>'poison','bleeding'=>'bleed','scorching'=>'burn','freezing'=>'freeze','shocking'=>'shock'] as $key=>$status) {
    $tests["Task31 generated {$key} potency3 status chance15 actual provider service resolver"] = function () use ($key,$status): void {
        foreach ([[15,true],[16,false]] as [$roll,$success]) {
            [$pdo,$clock,$provider,$random,$service] = task31Combat([task31Generate('copper_charm',[$key])],[1,100,100,$roll]);
            assertFloatValue(15.0,$provider->characterStats($pdo->characters[42])['utility']['status_effect_chance'],'Task30 authority unchanged.');
            $service->startPlayerAction(7,42,'prototype_weapon_attack',equipmentToken(3105)); task26Advance($clock); $state = $service->state(7,42);
            assertSameValue([3,$success?[$status]:[],4],[$pdo->actions[1]['snapshot_'.$status.'_status_damage'],array_column(task29Active($pdo),'status_key'),$random->integerCalls],'Per-status immutable potency reaches real application.');
            if ($success) { assertSameValue(3,task29Active($pdo)[0]['potency'],'Potency not rescaled to chance.'); }
        }
    };
}
$tests['Task31 generated evasion guarding resistances reach currentDefense and actual defense provider'] = function (): void {
    $items = [task31Generate('hide_gloves',['of_evasion']),task31Generate('wooden_shield',['of_guarding']),task31Generate('hide_boots',['of_storm_warding']),task31Generate('hide_belt',['of_poison_warding'])];
    [$provider] = task31Provider($items);
    $character = combatEquipmentCharacter();
    $defense = $provider->currentDefense($character);
    assertFloatValue(15.0,$defense['dodging'],'Dexterity12 +3 Dodging.');
    assertFloatValue(1.25,$defense['block_rate'],'Base1 *1.25 not +25 points.');
    assertFloatValue(8.0,$defense['resistances']['lightning'],'Existing5 +3.');
    assertFloatValue(8.0,$defense['resistances']['poison'],'Existing5 +3.');
    $resolver = new PrototypeBlockResolver(new PrototypeChampionDamageResolver(), new Task6SequenceRandomSource([15]), CombatDefinitionRegistry::fromDefaultConfig()->playerReaction('basic_block'));
    $dodged = $resolver->resolve(task6EnemyAction('smash'),$defense,false);
    assertSameValue('DODGE',$dodged['outcome'],'Actual defense uses generated Dodging.');
};

$tests['Task31 generated critical chance changes inclusive critical boundary'] = function (): void {
    foreach ([[18,'CRITICAL',13],[19,'HIT',11]] as [$roll,$outcome,$damage]) {
        [$pdo,$clock,,,$service] = task31Combat([task31Generate('worn_sword',['deadly'])],[1,100,$roll]);
        $service->startPlayerAction(7,42,'prototype_weapon_attack',equipmentToken(3106)); task26Advance($clock); $state = $service->state(7,42);
        assertSameValue([$outcome,$damage],[$state['battle_events'][0]['outcome'],$pdo->actions[1]['resolved_damage']],'Critical18 inclusive and actual damage.');
    }
};
$tests['Task31 generated offhand guarding changes real Block boundary at tiers1 and2'] = function (): void {
    foreach ([[1,1,25],[2,10,30]] as [$tier,$level,$threshold]) {
        [$provider] = task31Provider([task31Generate('wooden_shield',['of_guarding'],$level,$tier)]);
        $defense = $provider->currentDefense(combatEquipmentCharacter());
        foreach ([[$threshold,'BLOCK'],[$threshold+1,'HIT']] as [$roll,$outcome]) {
            $random = new Task6SequenceRandomSource([100,$roll]);
            $resolver = new PrototypeBlockResolver(new PrototypeChampionDamageResolver(),$random,CombatDefinitionRegistry::fromDefaultConfig()->playerReaction('basic_block'));
            $result = $resolver->resolve(task6EnemyAction('smash'),$defense,true);
            assertSameValue([$outcome,2],[$result['outcome'],$random->integerCalls],'20 percent base * factor1.25/1.50, not additive points.');
        }
    }
};
$tests['Task31 generated armour dodge read through repository during actual enemy action service'] = function (): void {
    foreach ([[15,'DODGE'],[16,'HIT']] as [$roll,$expected]) {
        [$pdo,$clock,,,$service] = task31Combat([task31Generate('hide_boots',['of_evasion'])],[$roll]);
        $pdo->actions[71] = task6EnemyAction('smash',['id'=>71,'encounter_id'=>91,'resolves_timeline_ms'=>4000]);
        task26Advance($clock); $state = $service->state(7,42);
        assertSameValue($expected,$state['battle_events'][0]['outcome'],'Dodge15 inclusive read at resolution.');
        assertSameValue($expected === 'DODGE' ? 145 : 129,$pdo->characters[42]['current_hp'],'Generated boots toughness also participates.');
    }
};
return $tests;
