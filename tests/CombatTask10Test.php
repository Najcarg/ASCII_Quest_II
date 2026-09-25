<?php
declare(strict_types=1);

function task10SkillAction(array $overrides = []): array
{
    return array_replace(
        combatActionFixture('10101010-1010-4010-8010-101010101010'),
        [
            'id' => 101,
            'encounter_id' => 91,
            'actor' => 'player',
            'action_kind' => 'skill',
            'definition_key' => 'prototype_flame_strike',
            'active_slot' => null,
            'state' => 'resolved',
            'started_timeline_ms' => 1000,
            'resolves_timeline_ms' => 2500,
            'cooldown_ready_timeline_ms' => 6000,
            'completed_timeline_ms' => 2500,
            'snapshot_weapon_key' => null,
            'snapshot_damage_type' => 'fire',
            'snapshot_base_damage' => 24,
            'snapshot_accuracy' => null,
            'snapshot_critical_chance' => null,
            'snapshot_critical_damage' => null,
        ],
        $overrides,
    );
}

function task10EffectAction(int $id, int $parentActionId, array $overrides = []): array
{
    return array_replace(
        combatActionFixture('20202020-2020-4020-8020-202020202020'),
        [
            'id' => $id,
            'encounter_id' => 91,
            'parent_action_id' => $parentActionId,
            'actor' => 'system',
            'action_kind' => 'skill',
            'definition_key' => 'prototype_burning',
            'request_token' => null,
            'active_slot' => null,
            'state' => 'resolved',
            'started_timeline_ms' => 2500,
            'resolves_timeline_ms' => 6500,
            'cooldown_ready_timeline_ms' => null,
            'completed_timeline_ms' => 2500,
            'snapshot_weapon_key' => null,
            'snapshot_damage_type' => null,
            'snapshot_base_damage' => null,
            'snapshot_accuracy' => null,
            'snapshot_critical_chance' => null,
            'snapshot_critical_damage' => null,
        ],
        $overrides,
    );
}

function task10Projection(
    array $encounterOverrides = [],
    array $actions = [],
    array $characterOverrides = [],
): array {
    $pdo = new FakeCombatPdo();
    $pdo->encounters[91] = task4Encounter(array_replace([
        'timeline_elapsed_ms' => 3000,
        'turn_started_timeline_ms' => 0,
        'player_actions_remaining' => 1,
        'enemy_actions_remaining' => 1,
        'potion_key' => 'prototype_health_potion',
        'potion_charge_allowance' => 1,
        'potion_charges_remaining' => 1,
    ], $encounterOverrides));
    $pdo->characters[42] = array_replace($pdo->characters[42], $characterOverrides);
    foreach ($actions as $action) {
        $pdo->actions[(int) $action['id']] = $action;
    }

    return (new CombatStateProjector(
        new CombatRepository($pdo),
        CombatDefinitionRegistry::fromDefaultConfig(),
    ))->project($pdo->characters[42], $pdo->encounters[91]);
}

function task10AssertSkillState(
    array $encounterOverrides,
    array $actions,
    array $characterOverrides,
    ?string $disabledReason,
    string $message,
): void {
    $state = task10Projection($encounterOverrides, $actions, $characterOverrides);
    $skill = $state['player_skills'][0] ?? null;
    assertSameValue($disabledReason === null, $skill['available'] ?? null, $message . ' availability.');
    assertSameValue($disabledReason, $skill['disabled_reason'] ?? null, $message . ' reason.');
}

return [
    'Task 10 projects one configured skill with authoritative availability' => function (): void {
        $definitions = CombatDefinitionRegistry::fromDefaultConfig();
        $definition = $definitions->playerAction('prototype_flame_strike');
        assertSameValue('skill', $definition['kind'] ?? null, 'Prototype action is a skill.');
        assertSameValue('prototype_burning', $definition['effect']['key'] ?? null, 'Prototype skill owns its effect definition.');
        assertSameValue('Burning', $definition['effect']['name'] ?? null, 'Prototype effect has a configured presentation name.');

        $state = task10Projection();
        assertSameValue([[
            'slot' => 'skill_1',
            'key' => 'prototype_flame_strike',
            'name' => 'Flame Strike',
            'duration_ms' => 1500,
            'cooldown_started_timeline_ms' => null,
            'cooldown_ready_timeline_ms' => null,
            'available' => true,
            'disabled_reason' => null,
        ]], $state['player_skills'] ?? null, 'Skill projection is a safe ordered array.');

        task10AssertSkillState(['status' => 'victory_loot'], [], [], 'encounter_inactive', 'Inactive encounter');
        task10AssertSkillState([], [], ['current_hp' => 0], 'actor_unavailable', 'Unavailable actor');
        task10AssertSkillState(['enemy_current_hp' => 0], [], [], 'target_unavailable', 'Unavailable target');
        task10AssertSkillState([], [task10SkillAction([
            'state' => 'pending',
            'active_slot' => 1,
            'completed_timeline_ms' => null,
        ])], [], 'actor_busy', 'Busy actor');
        task10AssertSkillState([], [task10SkillAction()], [], 'cooldown', 'Cooling skill');
        task10AssertSkillState(['player_actions_remaining' => 0], [], [], 'no_actions', 'No Actions');
        task10AssertSkillState(['timeline_elapsed_ms' => 9000], [], [], 'insufficient_turn_time', 'Insufficient Turn time');
    },

    'Task 10 starts skill cooldown immediately and consumes one Action' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter([
            'enemy_current_hp' => 120,
            'timeline_elapsed_ms' => 1000,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'turn_started_timeline_ms' => 0,
            'next_enemy_decision_timeline_ms' => 10000,
            'enemy_ai_initialized_timeline_ms' => 0,
            'player_actions_remaining' => 2,
            'enemy_actions_remaining' => 2,
        ]);
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:00.000000',
            new DateTimeZone('UTC'),
        ));
        $service = task6ChronologicalService(
            $pdo,
            $clock,
            null,
            new Task6SequenceRandomSource([21]),
        );

        $state = $service->startPlayerAction(
            7,
            42,
            'prototype_flame_strike',
            '30303030-3030-4030-8030-303030303030',
        );
        $skillActions = array_values(array_filter(
            $pdo->actions,
            static fn (array $action): bool =>
                ($action['actor'] ?? null) === 'player' &&
                ($action['definition_key'] ?? null) === 'prototype_flame_strike',
        ));

        assertSameValue(1, count($skillActions), 'One skill action is created.');
        assertSameValue([
            'skill', 1000, 2500, 6000, 'fire', 24,
        ], [
            $skillActions[0]['action_kind'] ?? null,
            $skillActions[0]['started_timeline_ms'] ?? null,
            $skillActions[0]['resolves_timeline_ms'] ?? null,
            $skillActions[0]['cooldown_ready_timeline_ms'] ?? null,
            $skillActions[0]['snapshot_damage_type'] ?? null,
            $skillActions[0]['snapshot_base_damage'] ?? null,
        ], 'Duration, cooldown, and offense come from the authoritative definition.');
        assertSameValue(1, $pdo->encounters[91]['player_actions_remaining'], 'Skill consumes exactly one Action.');
        assertSameValue('actor_busy', $state['player_skills'][0]['disabled_reason'] ?? null, 'Pending skill makes the actor busy.');

        $service->startPlayerAction(
            7,
            42,
            'prototype_flame_strike',
            '30303030-3030-4030-8030-303030303030',
        );
        assertSameValue(1, count($pdo->actions), 'Exact request replay creates no second skill action.');
        assertSameValue(1, $pdo->encounters[91]['player_actions_remaining'], 'Exact replay consumes no second Action.');
    },

    'Task 10 skill resolution starts a separate authoritative effect window' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter([
            'enemy_current_hp' => 120,
            'timeline_elapsed_ms' => 1000,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'turn_started_timeline_ms' => 0,
            'next_enemy_decision_timeline_ms' => 10000,
            'enemy_ai_initialized_timeline_ms' => 0,
            'player_actions_remaining' => 2,
            'enemy_actions_remaining' => 2,
        ]);
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:00.000000',
            new DateTimeZone('UTC'),
        ));
        $random = new Task6SequenceRandomSource([21]);
        $service = task6ChronologicalService($pdo, $clock, null, $random);
        $service->startPlayerAction(
            7,
            42,
            'prototype_flame_strike',
            '40404040-4040-4040-8040-404040404040',
        );

        $clock->set(new DateTimeImmutable(
            '2026-09-01 12:00:01.500000',
            new DateTimeZone('UTC'),
        ));
        $state = $service->state(7, 42);
        $skill = array_values(array_filter(
            $pdo->actions,
            static fn (array $action): bool => ($action['actor'] ?? null) === 'player',
        ))[0];
        $effects = array_values(array_filter(
            $pdo->actions,
            static fn (array $action): bool => ($action['actor'] ?? null) === 'system',
        ));

        assertSameValue('resolved', $skill['state'], 'Skill resolves once at its action endpoint.');
        assertSameValue(2500, $skill['completed_timeline_ms'], 'Skill completion uses the resolution cursor.');
        assertSameValue(96, $pdo->encounters[91]['enemy_current_hp'], 'Skill applies its immutable offensive snapshot once.');
        assertSameValue(1, count($effects), 'Resolution creates one persisted effect row.');
        assertSameValue([
            $skill['id'], 'system', 'skill', 'prototype_burning', 'resolved',
            2500, 6500, 2500,
        ], [
            $effects[0]['parent_action_id'] ?? null,
            $effects[0]['actor'] ?? null,
            $effects[0]['action_kind'] ?? null,
            $effects[0]['definition_key'] ?? null,
            $effects[0]['state'] ?? null,
            $effects[0]['started_timeline_ms'] ?? null,
            $effects[0]['resolves_timeline_ms'] ?? null,
            $effects[0]['completed_timeline_ms'] ?? null,
        ], 'Effect duration begins at skill resolution and persists independently.');
        assertSameValue([[
            'key' => 'prototype_burning',
            'name' => 'Burning',
            'started_timeline_ms' => 2500,
            'ends_timeline_ms' => 6500,
        ]], $state['active_effects'] ?? null, 'Active effect projection is an allowlisted array.');
        assertSameValue(6000, $state['player_skills'][0]['cooldown_ready_timeline_ms'] ?? null, 'Cooldown endpoint remains independent from effect endpoint.');

        $service->state(7, 42);
        assertSameValue(1, count(array_filter(
            $pdo->actions,
            static fn (array $action): bool => ($action['actor'] ?? null) === 'system',
        )), 'Repeated synchronization creates no duplicate effect.');
        assertSameValue(96, $pdo->encounters[91]['enemy_current_hp'], 'Repeated synchronization applies no duplicate damage.');
        assertSameValue(1, $random->integerCalls, 'Repeated synchronization performs no duplicate defensive roll.');
    },

    'Task 10 active effect projection supports multiple windows and expiry' => function (): void {
        $skillOne = task10SkillAction(['id' => 101]);
        $skillTwo = task10SkillAction([
            'id' => 102,
            'request_token' => '50505050-5050-4050-8050-505050505050',
        ]);
        $state = task10Projection([], [
            $skillOne,
            $skillTwo,
            task10EffectAction(201, 101),
            task10EffectAction(202, 102, [
                'started_timeline_ms' => 2750,
                'resolves_timeline_ms' => 6750,
                'completed_timeline_ms' => 2750,
            ]),
        ]);
        assertSameValue(2, count($state['active_effects'] ?? []), 'Projection retains multiple simultaneous effects.');
        foreach ($state['active_effects'] as $effect) {
            assertSameValue([
                'key', 'name', 'started_timeline_ms', 'ends_timeline_ms',
            ], array_keys($effect), 'Effect projection exposes only presentation fields.');
        }

        $expired = task10Projection(['timeline_elapsed_ms' => 7000], [
            $skillOne,
            task10EffectAction(201, 101),
        ]);
        assertSameValue([], $expired['active_effects'] ?? null, 'Expired effect history is no longer active.');
    },
];
