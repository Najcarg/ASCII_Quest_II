<?php
declare(strict_types=1);

function task26Ring(int $amount = 5, int $id = 96): array
{
    return equipmentItem($id, 'ring', [['modifier_type' => 'life_on_hit',
        'modifier_operation' => 'flat', 'rolled_value' => $amount]]) + ['equipment_slot' => 'ring'];
}

function task26Advance(Task4MutableCombatClock $clock, float $seconds = 1): void
{
    $clock->set(new DateTimeImmutable($seconds === 1.5
        ? '2026-09-01 12:00:01.500000' : '2026-09-01 12:00:01.000000', new DateTimeZone('UTC')));
}

function task26HealingEvents(FakeCombatPdo $pdo): array
{
    return array_values(array_filter($pdo->events,
        static fn (array $event): bool => $event['event_type'] === 'player_life_on_hit'));
}

function task26StoredState(FakeCombatPdo $pdo): array
{
    return [$pdo->characters, $pdo->encounters, $pdo->actions, $pdo->events];
}

return [
    'Task26 zero or negative Life on Hit never heals or logs recovery' => function (): void {
        foreach ([0, -5] as $amount) {
            [$pdo, $clock, , , , $service] = task25Fixture([task26Ring($amount)], [1, 100, 100]);
            $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2600));
            task26Advance($clock);
            $state = $service->state(7, 42);
            assertSameValue([145, 145, []], [$state['champion']['current_hp'], $pdo->characters[42]['current_hp'], task26HealingEvents($pdo)], 'Nonnegative shared utility, no noise.');
        }
    },
    'Task26 flat five heals exactly five and changes no Mana maximum or other character fields' => function (): void {
        [$pdo, $clock, , $provider, , $service] = task25Fixture([task26Ring()], [1, 100, 100]);
        $before = $pdo->characters[42];
        $stats = $provider->characterStats($before);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2601));
        task26Advance($clock);
        $state = $service->state(7, 42);
        assertSameValue(array_replace($before, ['current_hp' => 150]), $pdo->characters[42], 'Only HP changes, never Mana.');
        assertSameValue($stats, $provider->characterStats($pdo->characters[42]), 'No maximum or derived stats change.');
        assertSameValue(150, $state['champion']['current_hp'], 'Immediate authoritative projection.');
        assertSameValue('You recover 5 Life.', task26HealingEvents($pdo)[0]['message'] ?? null, 'Server actual-heal feedback.');
        assertSameValue('You recover 5 Life.', $state['battle_events'][1]['message'] ?? null, 'Existing safe event projection.');
    },
    'Task26 caps actual healing at current maximum and full or overcap HP cannot decrease' => function (): void {
        foreach ([[198, 200, 2], [200, 200, 0], [205, 205, 0]] as [$hp, $expected, $healed]) {
            [$pdo, $clock, , , , $service] = task25Fixture([task26Ring()], [1, 100, 100], ['current_hp' => $hp]);
            $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2602));
            task26Advance($clock);
            $state = $service->state(7, 42);
            assertSameValue([$expected, $expected, $healed > 0 ? 1 : 0], [$pdo->characters[42]['current_hp'], $state['champion']['current_hp'], count(task26HealingEvents($pdo))], 'Cap never deals damage.');
            if ($healed > 0) {
                assertSameValue('You recover 2 Life.', task26HealingEvents($pdo)[0]['message'], 'Actual gain not nominal affix amount.');
            }
        }
    },
    'Task26 explicit HIT CRITICAL partial BLOCK and CRITICAL BLOCK each heal once' => function (): void {
        foreach ([['HIT', [1, 100, 100], 5], ['CRITICAL', [1, 100, 1], 6],
            ['BLOCK', [1, 1, 100], 2], ['CRITICAL', [1, 1, 1], 2]] as [$outcome, $rolls, $damage]) {
            [$pdo, $clock, , , $random, $service] = task25Fixture([task26Ring()], $rolls);
            $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2603));
            task26Advance($clock);
            $state = $service->state(7, 42);
            assertSameValue([$outcome, $damage, 150, 1, 3], [$state['battle_events'][0]['outcome'], $pdo->actions[1]['resolved_damage'], $pdo->characters[42]['current_hp'], count(task26HealingEvents($pdo)), $random->integerCalls], 'Explicit contact, no critical double heal or extra roll.');
        }
    },
    'Task26 MISS never heals despite equipped Life on Hit' => function (): void {
        [$pdo, $clock, , , $random, $service] = task25Fixture([task26Ring()], [66]);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2604));
        task26Advance($clock);
        $state = $service->state(7, 42);
        assertSameValue(['MISS', 145, [], 1], [$state['battle_events'][0]['outcome'], $pdo->characters[42]['current_hp'], task26HealingEvents($pdo), $random->integerCalls], 'No damage/contact inference.');
    },
    'Task26 zero HIT CRITICAL BLOCK and rounded one damage Block are landed contact' => function (): void {
        foreach ([[0, [1, 100, 100], 'HIT'], [0, [1, 100, 1], 'CRITICAL'],
            [0, [1, 1, 100], 'BLOCK'], [1, [1, 1, 100], 'BLOCK'], [1, [1, 1, 1], 'CRITICAL']] as [$strength, $rolls, $outcome]) {
            [$pdo, $clock, , , , $service] = task25Fixture([task26Ring()], $rolls, ['strength' => $strength]);
            $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2605));
            task26Advance($clock);
            $state = $service->state(7, 42);
            assertSameValue([$outcome, 0, 150, 1], [$state['battle_events'][0]['outcome'], $pdo->actions[1]['resolved_damage'], $pdo->characters[42]['current_hp'], count(task26HealingEvents($pdo))], 'Current percentage Block is not contact denial even if rounding prevents all numeric damage.');
        }
    },
    'Task26 both real weapon and explicit unarmed normal attacks qualify without changing Task24 offense' => function (): void {
        foreach ([[], [task25Mace()]] as $weapons) {
            [$pdo, $clock, , , , $service] = task25Fixture(array_merge($weapons, [task26Ring()]), [1, 100, 100]);
            $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2606));
            $snapshot = task25Snapshot($pdo->actions[1]);
            assertSameValue($weapons === [] ? [5, null] : [11, 77], [$snapshot['snapshot_base_damage'], $snapshot['snapshot_weapon_item_id']], 'Strength5 unarmed5 / mace10–12 midpoint11.');
            task26Advance($clock);
            $service->state(7, 42);
            assertSameValue([150, $snapshot], [$pdo->characters[42]['current_hp'], task25Snapshot($pdo->actions[1])], 'Heal without changing immutable offense.');
        }
    },
    'Task26 Flame Strike hit critical and blocked skill never heal or alter skill effect lifecycle' => function (): void {
        foreach ([[1, 100, 100], [1, 100, 1], [1, 1, 100]] as $rolls) {
            [$pdo, $clock, , , , $service] = task25Fixture([task26Ring()], $rolls);
            $service->startPlayerAction(7, 42, 'prototype_flame_strike', equipmentToken(2607));
            $mana = $pdo->characters[42]['current_mana'];
            task26Advance($clock, 1.5);
            $service->state(7, 42);
            assertSameValue([145, $mana, [], 2], [$pdo->characters[42]['current_hp'], $pdo->characters[42]['current_mana'], task26HealingEvents($pdo), count($pdo->actions)], 'Skill excluded, normal Mana cost and effect unchanged.');
        }
    },
    'Task26 enemy HIT DODGE and BLOCK cannot trigger Champion Life on Hit' => function (): void {
        foreach ([[100, null, 18], [1, null, 0], [100, 500, 9]] as [$roll, $attempted, $incoming]) {
            [$pdo, $clock, , , , $service] = task25Fixture([task26Ring()], $attempted === null ? [$roll] : [$roll, 1], ['strength' => 0]);
            $pdo->actions[71] = task6EnemyAction('smash', ['id' => 71, 'encounter_id' => 91, 'resolves_timeline_ms' => 4000, 'block_attempted_timeline_ms' => $attempted]);
            task26Advance($clock);
            $service->state(7, 42);
            assertSameValue([145 - $incoming, []], [$pdo->characters[42]['current_hp'], task26HealingEvents($pdo)], 'Enemy path never heals.');
        }
    },
    'Task26 pending cancelled resolved and failed-start actions do not heal' => function (): void {
        foreach (['pending', 'cancelled', 'resolved'] as $state) {
            [$pdo, , , , , $service] = task25Fixture([task26Ring()], []);
            $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2608));
            $pdo->actions[1]['state'] = $state;
            $service->state(7, 42);
            assertSameValue([145, []], [$pdo->characters[42]['current_hp'], task26HealingEvents($pdo)], 'Not resolving a new due contact.');
        }
        [$pdo, , , , , $service] = task25Fixture([task26Ring()], []);
        $pdo->encounters[91]['player_actions_remaining'] = 0;
        task7AssertDomainRejected(fn () => $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2609)), 'Failed start.');
        assertSameValue([145, [], []], [$pdo->characters[42]['current_hp'], $pdo->actions, task26HealingEvents($pdo)], 'No failed-start healing.');
    },
    'Task26 duplicate token repeated sync and fresh-service refresh heal exactly once' => function (): void {
        [$pdo, $clock, , $provider, $random, $service] = task25Fixture([task26Ring()], [1, 100, 100]);
        $token = equipmentToken(2610);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', $token);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', $token);
        assertSameValue(145, $pdo->characters[42]['current_hp'], 'Pending duplicate does not heal early.');
        task26Advance($clock);
        $state = $service->state(7, 42);
        $before = [$pdo->characters, $pdo->actions, $pdo->events];
        for ($i = 0; $i < 3; $i++) { $service->state(7, 42); }
        $fresh = CombatBootstrap::serviceForRepository(new CombatRepository($pdo), $clock, $provider, new Task6SequenceRandomSource([]));
        $replay = $fresh->startPlayerAction(7, 42, 'prototype_weapon_attack', $token);
        assertSameValue([150, 150, 3], [$state['champion']['current_hp'], $replay['champion']['current_hp'], $random->integerCalls], 'Persisted HP refresh, no rerolls.');
        assertSameValue($before, [$pdo->characters, $pdo->actions, $pdo->events], 'No second heal/event/result.');
    },
    'Task26 killing blow heals before victory rewards and close remain once only' => function (): void {
        [$pdo, $clock, , , , $service] = task25Fixture([task26Ring()], [1, 100, 100]);
        $pdo->encounters[91]['enemy_current_hp'] = 5;
        $token = equipmentToken(2611);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', $token);
        task26Advance($clock);
        $state = $service->state(7, 42);
        assertSameValue(['victory_loot', 150, 0], [$pdo->encounters[91]['status'], $state['champion']['current_hp'], $state['enemy']['current_hp']], 'Killing contact heals before terminal return.');
        assertSameValue(['player_attack_hit', 'player_life_on_hit', 'victory_rewards'], array_column(array_values($pdo->events), 'event_type'), 'One heal before terminal rewards.');
        $character = $pdo->characters[42];
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', $token);
        $service->state(7, 42);
        assertSameValue(['closed' => true], $service->closeVictory(7, 42), 'Close unchanged.');
        assertSameValue(['closed' => true], $service->closeVictory(7, 42), 'Close replay unchanged.');
        assertSameValue($character, $pdo->characters[42], 'Rewards and heal are not repeated by close.');
        assertSameValue(3, count($pdo->events), 'No duplicate terminal events.');
    },
    'Task26 death wins before due player contact and never revives a dead Champion' => function (): void {
        [$pdo, $clock, , , , $service] = task25Fixture([task26Ring(999)], [100], ['current_hp' => 1]);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2612));
        $pdo->actions[71] = task6EnemyAction('smash', ['id' => 71, 'encounter_id' => 91, 'resolves_timeline_ms' => 3500]);
        task26Advance($clock);
        $service->state(7, 42);
        assertSameValue([0, 'dead', 'defeated', 'cancelled', []], [$pdo->characters[42]['current_hp'], $pdo->characters[42]['life_state'], $pdo->encounters[91]['status'], $pdo->actions[1]['state'], task26HealingEvents($pdo)], 'Earlier lethal enemy attack preserves permadeath and cancels contact.');
        $before = [$pdo->characters, $pdo->actions, $pdo->events];
        $service->state(7, 42);
        assertSameValue($before, [$pdo->characters, $pdo->actions, $pdo->events], 'Dead refresh cannot revive or rerun death.');
    },
    'Task26 zero HP at synchronization cancels due weapon rather than healing' => function (): void {
        [$pdo, $clock, , , , $service] = task25Fixture([task26Ring(999)], []);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2613));
        $pdo->characters[42]['current_hp'] = 0;
        task26Advance($clock);
        $service->state(7, 42);
        assertSameValue([0, 'dead', 'cancelled', []], [$pdo->characters[42]['current_hp'], $pdo->characters[42]['life_state'], $pdo->actions[1]['state'], task26HealingEvents($pdo)], 'No zero-HP revival.');
    },
    'Task26 multiple affixes aggregate flat Life on Hit without changing unrelated stats' => function (): void {
        [$pdo, $clock, $reader, $provider, , $service] = task25Fixture([], [1, 100, 100]);
        $before = $provider->characterStats($pdo->characters[42]);
        $ring = task26Ring(2);
        $ring['affixes'][] = ['modifier_type' => 'life_on_hit', 'modifier_operation' => 'flat', 'rolled_value' => '1'];
        $reader->items = [$ring, task26Ring(3, 97)];
        $expected = $before;
        $expected['utility']['life_on_hit'] = 6;
        assertSameValue($expected, $provider->characterStats($pdo->characters[42]), 'Authoritative aggregation; no unrelated changes.');
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2614));
        task26Advance($clock);
        $service->state(7, 42);
        assertSameValue(151, $pdo->characters[42]['current_hp'], 'Sum once, not per item or affix.');
    },
    'Task26 uses resolution-time shared utility and max Life while offense remains immutable' => function (): void {
        [$pdo, $clock, $reader, $provider, , $service] = task25Fixture([task26Ring(2)], [1, 100, 100], ['current_hp' => 199]);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2615));
        $before = task25Snapshot($pdo->actions[1]);
        // Injected reader mutation exercises the boundary; real equipment commands are combat-blocked.
        $ring = task26Ring(5);
        $ring['affixes'][] = ['modifier_type' => 'maximum_life', 'modifier_operation' => 'flat', 'rolled_value' => 2];
        $reader->items = [$ring];
        task26Advance($clock);
        $state = $service->state(7, 42);
        assertSameValue([202, 202, $before], [$state['champion']['current_hp'], $provider->characterStats($pdo->characters[42])['resources']['max_life'], task25Snapshot($pdo->actions[1])], 'Current max cap, actual recovery3, no offensive snapshot mutation.');
        assertSameValue('You recover 3 Life.', task26HealingEvents($pdo)[0]['message'] ?? null, 'Current shared utility capped by current maximum.');
    },
    'Task26 real equipment equip and unequip remain blocked throughout unresolved combat' => function (): void {
        foreach (['active', 'victory_loot'] as $status) {
            $store = new FakeEquipmentStore(equipmentCharacter(), [task26Ring()]);
            $equipment = equipmentService($store);
            $equipment->equip(7, 42, 96, 'ring', equipmentToken(2617));
            $before = [$store->characters, $store->items, $store->equipment, $store->requests];
            $store->encounterStatus = $status;
            task7AssertDomainRejected(fn () => $equipment->unequip(7, 42, 96, equipmentToken(2618)), 'Unresolved combat freezes utility.');
            task7AssertDomainRejected(fn () => $equipment->equip(7, 42, 96, 'ring', equipmentToken(2619)), 'No equip workaround.');
            assertSameValue($before, [$store->characters, $store->items, $store->equipment, $store->requests], 'No changes or receipts.');
        }
    },
    'Task26 healing and later incoming damage use the same updated locked character in one sync' => function (): void {
        [$pdo, $clock, , , , $service] = task25Fixture([task26Ring()], [1, 100, 100, 100]);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2620));
        $pdo->actions[71] = task6EnemyAction('smash', ['id' => 71, 'encounter_id' => 91, 'resolves_timeline_ms' => 4500]);
        $before = task26StoredState($pdo);
        $pdo->failSynchronizationUpdate = true;
        task26Advance($clock, 1.5);
        $threw = false;
        try { $service->state(7, 42); } catch (RuntimeException) { $threw = true; }
        assertSameValue(true, $threw, 'Fail after both contacts.');
        assertSameValue($before, task26StoredState($pdo), 'Both attacks and healing roll back together.');
        $pdo->failSynchronizationUpdate = false;
        $fresh = CombatBootstrap::serviceForRepository(new CombatRepository($pdo), $clock,
            new PersistentCombatEquipmentProvider(new FakeCombatEquipmentReader([task26Ring()]), CombatDefinitionRegistry::fromDefaultConfig(), new EquipmentStatAggregator()),
            new Task6SequenceRandomSource([1, 100, 100, 100]));
        $state = $fresh->state(7, 42);
        $expectedHp = 150 - $pdo->actions[71]['resolved_damage'];
        assertSameValue([$expectedHp, $expectedHp, 80, 1], [$pdo->characters[42]['current_hp'], $state['champion']['current_hp'], $pdo->characters[42]['current_mana'], count(task26HealingEvents($pdo))], 'Incoming HP compare-and-swap starts from healed HP, Mana untouched.');
        assertSameValue(['player_attack_hit', 'player_life_on_hit', 'enemy_attack_hit'], array_column(array_values($pdo->events), 'event_type'), 'Chronology unchanged.');
    },
    'Task26 already dead life state cannot heal even with inconsistent positive HP' => function (): void {
        [$pdo, , , $provider] = task25Fixture([task26Ring(999)], []);
        $pdo->characters[42]['life_state'] = 'dead';
        $repository = new CombatRepository($pdo);
        $repository->beginTransaction();
        try {
            $character = $repository->lockOwnedCharacter(7, 42);
            $encounter = $repository->lockActiveEncounter(42);
            $action = array_replace(task8PendingWeapon(61), task25Offense());
            $pdo->actions[61] = $action;
            $repository->lockPendingActionsForEncounter(91);
            $random = new Task6SequenceRandomSource([1, 100, 100]);
            $resolver = new CombatActionResolver($repository, CombatDefinitionRegistry::fromDefaultConfig(), $provider,
                new PrototypeEnemyDefenseResolver($random), new Task7RecordingBlockResolver());
            $result = $resolver->resolvePending($encounter, $character, $action);
            assertSameValue([145, 'dead', []], [$result['character']['current_hp'], $result['character']['life_state'], task26HealingEvents($pdo)], 'Explicit alive requirement protects resolver boundary.');
        } finally {
            $repository->rollBack();
        }
    },
    'Task26 HP action event synchronization and victory failures roll back healing and retry once' => function (): void {
        foreach (['hp', 'action', 'outcome', 'heal_event', 'sync', 'victory'] as $failure) {
            [$pdo, $clock, , , , $service] = task25Fixture([task26Ring()], [1, 100, 100, 1, 100, 100]);
            if ($failure === 'victory') { $pdo->encounters[91]['enemy_current_hp'] = 5; }
            $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2616));
            $before = task26StoredState($pdo);
            $pdo->failCharacterHpUpdate = $failure === 'hp';
            $pdo->failActionResolution = $failure === 'action';
            $pdo->failSynchronizationUpdate = $failure === 'sync';
            $pdo->failEventInsert = in_array($failure, ['outcome', 'heal_event', 'victory'], true);
            $pdo->failEventType = match ($failure) { 'outcome' => 'player_attack_hit', 'heal_event' => 'player_life_on_hit', 'victory' => 'victory_rewards', default => null };
            task26Advance($clock);
            $threw = false;
            try { $service->state(7, 42); } catch (RuntimeException) { $threw = true; }
            assertSameValue(true, $threw, $failure . ' must fail atomically.');
            assertSameValue($before, task26StoredState($pdo), $failure . ' restores HP enemyHP snapshots lifecycle events rewards.');
            $pdo->failCharacterHpUpdate = $pdo->failActionResolution = $pdo->failSynchronizationUpdate = $pdo->failEventInsert = false;
            $service->state(7, 42);
            assertSameValue([150, 1, 'resolved'], [$pdo->characters[42]['current_hp'], count(task26HealingEvents($pdo)), $pdo->actions[1]['state']], 'Retry commits one gain.');
        }
    },
];
