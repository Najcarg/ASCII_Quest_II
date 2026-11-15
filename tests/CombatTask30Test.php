<?php
declare(strict_types=1);

$tests = [];
// Literal expected percentages, not a copy of the production calculation.
$boundaries = [0 => 0, 1 => 5, 2 => 10, 3 => 15, 4 => 20, 5 => 25, 7 => 35, 10 => 50, 18 => 90, 100 => 90];
foreach (['poison', 'bleed', 'burn', 'freeze', 'shock'] as $key) {
    foreach ($boundaries as $potency => $percent) {
        $tests["Task30 authority {$key} potency {$potency} gives {$percent} percent"] = function () use ($key, $potency, $percent): void {
            $values = array_fill_keys(['poison', 'bleed', 'burn', 'freeze', 'shock'], 0);
            $values[$key] = $potency;
            assertFloatValue((float) $percent, CharacterStats::statusEffectChance($values), 'Sole chance authority with other statuses zero.');
            $stats = CharacterStats::calculate(combatEquipmentCharacter(), [$key . '_damage' => $potency]);
            assertFloatValue((float) $percent, $stats['utility']['status_effect_chance'], 'Shared utility projection uses the same authority.');
            assertSameValue($potency, $stats['utility'][$key . '_damage'], 'Chance normalization never rescales potency.');
        };
        $rollCases = $potency === 0 ? [[null, false]] : [[$percent, true], [$percent + 1, false]];
        foreach ($rollCases as [$roll, $success]) {
            $label = $roll === null ? 'no roll' : "roll {$roll} " . ($success ? 'passes' : 'fails');
            $tests["Task30 application {$key} potency {$potency} {$label}"] = function () use ($key, $potency, $roll, $success): void {
                $rolls = [1, 100, 100]; // Landed HIT, no Block or critical.
                if ($roll !== null) { $rolls[] = $roll; }
                [$pdo, , , , $random] = task29Resolve([$key => $potency], $rolls);
                $active = task29Active($pdo);
                assertSameValue($success ? [$key] : [], array_column($active, 'status_key'), 'Only the selected status can apply at its own inclusive boundary.');
                assertSameValue($potency === 0 ? 3 : 4, $random->integerCalls, 'Zero skips; positive potency draws exactly once.');
                assertSameValue($potency, $pdo->actions[1]['snapshot_' . $key . '_status_damage'], 'Snapshot potency unchanged on either outcome.');
                if ($success) {
                    assertSameValue([$potency, 4000, 8000, in_array($key, ['poison', 'bleed', 'burn'], true) ? 5000 : null],
                        [$active[0]['potency'], $active[0]['applied_timeline_ms'], $active[0]['expires_timeline_ms'], $active[0]['next_tick_timeline_ms']], 'Original potency and four-second schedule.');
                }
            };
        }
    }
}

foreach (['poison', 'bleed', 'burn'] as $key) {
    $tests["Task30 {$key} potency5 procs at25 and deals four unchanged five-damage ticks"] = function () use ($key): void {
        [$pdo, $clock, , $provider, $random, $service] = task29Resolve([$key => 5], [1, 100, 100, 25]);
        $direct = $key === 'poison' ? 10 : 5;
        assertSameValue([$key], array_column(task29Active($pdo), 'status_key'), 'Roll25 must apply potency5.');
        assertSameValue([$direct, 5, 4], [$pdo->actions[1]['resolved_damage'], task29Active($pdo)[0]['potency'], $random->integerCalls], 'Poison retains Task28 direct +5 as well as status potency5; burn/bleed add no direct component.');
        task29At($clock, 5);
        $state = $service->state(7, 42);
        assertSameValue([100000 - $direct - 20, array_fill(0, 4, ucfirst($key) . ' deals 5 damage.'), [], 4],
            [$state['enemy']['current_hp'], array_column(task29TickEvents($pdo), 'message'), task29Active($pdo), $random->integerCalls], 'Four ticks, not scaled to25 damage; no extra rolls.');
        $saved = [$pdo->statuses, $pdo->events, $pdo->actions, task29Encounters($pdo)];
        CombatBootstrap::serviceForRepository(new CombatRepository($pdo), $clock, $provider, $random)->state(7, 42);
        assertSameValue($saved, [$pdo->statuses, $pdo->events, $pdo->actions, task29Encounters($pdo)], 'Durable replay cannot redamage or reroll.');
    };
}
$tests['Task30 poison5 failed26 roll keeps direct plus5 but never creates ticks'] = function (): void {
    [$pdo, $clock, , , $random, $service] = task29Resolve(['poison' => 5], [1, 100, 100, 26]);
    task29At($clock, 5); $state = $service->state(7, 42);
    assertSameValue([10, 99990, [], [], 4], [$pdo->actions[1]['resolved_damage'], $state['enemy']['current_hp'], task29Active($pdo), task29TickEvents($pdo), $random->integerCalls], 'Probability gates only poison status, not Task28 direct poison.');
};
$tests['Task30 freeze5 chance25 leaves slow at five percent'] = function (): void {
    [$pdo, $clock, , , $random, $service] = task29Fixture(['freeze' => 5], [1, 100, 100, 25, 100, 100]);
    $pdo->encounters[91]['next_enemy_decision_timeline_ms'] = 4000;
    $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(3001));
    task29At($clock, 1); $service->state(7, 42);
    $enemy = array_values(array_filter($pdo->actions, static fn ($row) => $row['actor'] === 'enemy'))[0];
    assertSameValue(['freeze'], array_column(task29Active($pdo), 'status_key'), 'Roll25 must apply Freeze5.');
    assertSameValue(['fire_slam', 4000, 6100, 10000, 5, 6],
        [$enemy['definition_key'], $enemy['started_timeline_ms'], $enemy['resolves_timeline_ms'], $enemy['cooldown_ready_timeline_ms'], task29Active($pdo)[0]['potency'], $random->integerCalls], '2000ms action slowed to2100ms, not2500ms; cooldown unchanged.');
    assertSameValue([], task29TickEvents($pdo), 'Freeze never ticks.');
};
$tests['Task30 shock5 chance25 leaves amplification at five percent and never self amplifies'] = function (): void {
    [$pdo, $clock, , $provider] = task29Resolve(['shock' => 5], [1, 100, 100, 25]);
    assertSameValue(['shock'], array_column(task29Active($pdo), 'status_key'), 'Roll25 must apply Shock5.');
    assertSameValue([5, 5], [$pdo->actions[1]['resolved_damage'], task29Active($pdo)[0]['potency']], 'New Shock cannot amplify its own contact.');
    $pdo->actions[2] = task6PendingAction(['id' => 2, 'encounter_id' => 91, 'started_timeline_ms' => 4000, 'resolves_timeline_ms' => 5000, 'snapshot_base_damage' => 100]);
    $random = new Task6SequenceRandomSource([1, 100, 100]);
    task29At($clock, 2); task29Service($pdo, $clock, $provider, $random)->state(7, 42);
    assertSameValue([105, [], 3], [$pdo->actions[2]['resolved_damage'], task29TickEvents($pdo), $random->integerCalls], 'Later direct100 amplified to105, not125; no DoT or secondary status roll.');
};
$tests['Task30 mixed low potencies draw independently in poison bleed burn freeze shock order'] = function (): void {
    [$pdo, , , , $random] = task29Resolve(['poison' => 1, 'bleed' => 2, 'burn' => 3, 'freeze' => 4, 'shock' => 5], [1, 100, 100, 5, 11, 15, 21, 25]);
    assertSameValue([['poison', 'burn', 'shock'], [1, 3, 5], 8], [array_column(task29Active($pdo), 'status_key'), array_column(task29Active($pdo), 'potency'), $random->integerCalls], 'Separate per-status chances, never a highest/shared chance or draw.');
};
return $tests;
