<?php
declare(strict_types=1);

function task9aProjection(
    array $encounterOverrides = [],
    array $actions = [],
    array $characterOverrides = [],
    ?CombatDefinitionRegistry $definitions = null,
    ?CombatEquipmentProvider $equipment = null,
): array {
    $pdo = new FakeCombatPdo();
    $pdo->encounters[91] = task4Encounter(array_replace([
        'timeline_elapsed_ms' => 3000,
        'turn_number' => 1,
        'turn_started_timeline_ms' => 0,
        'player_actions_remaining' => 1,
        'enemy_actions_remaining' => 1,
        'potion_key' => 'prototype_health_potion',
        'potion_charge_allowance' => 1,
        'potion_charges_remaining' => 1,
    ], $encounterOverrides));
    $pdo->characters[42] = array_replace(
        $pdo->characters[42],
        $characterOverrides,
    );
    foreach ($actions as $action) {
        $pdo->actions[(int) $action['id']] = $action;
    }

    return (new CombatStateProjector(
        new CombatRepository($pdo),
        $definitions ?? CombatDefinitionRegistry::fromDefaultConfig(),
        null,
        null,
        $equipment,
    ))->project($pdo->characters[42], $pdo->encounters[91]);
}

final class Task20ProjectionEquipment implements CombatEquipmentProvider
{
    public function __construct(private bool $hasWeapon = true) {}
    public function offensiveSnapshot(array $lockedCharacter, string $attackKey): array
    {
        if (!$this->hasWeapon) { throw new DomainException('No equipped weapon is available.'); }
        return [];
    }
    public function currentDefense(array $lockedCharacter): array { return []; }
    public function effectiveDurationMs(array $character, string $actionKey, int $base): int
    {
        if ($actionKey === CombatPlayerActionEvaluator::WEAPON_ACTION_KEY && !$this->hasWeapon) {
            throw new DomainException('No equipped weapon is available.');
        }
        return $actionKey === CombatPlayerActionEvaluator::WEAPON_ACTION_KEY ? 943 : 900;
    }
}

function task9aPlayerWeaponAction(array $overrides = []): array
{
    return array_replace(
        combatActionFixture('92929292-9292-4292-8292-929292929292'),
        [
            'id' => 92,
            'encounter_id' => 91,
            'actor' => 'player',
            'action_kind' => 'weapon',
            'definition_key' => 'prototype_weapon_attack',
            'state' => 'resolved',
            'active_slot' => null,
            'started_timeline_ms' => 1000,
            'resolves_timeline_ms' => 2000,
            'cooldown_ready_timeline_ms' => 3500,
            'completed_timeline_ms' => 2000,
        ],
        $overrides,
    );
}

function task9aAssertAttackState(
    array $encounterOverrides,
    array $actions,
    array $characterOverrides,
    bool $available,
    ?string $disabledReason,
    string $message,
): void {
    $state = task9aProjection(
        $encounterOverrides,
        $actions,
        $characterOverrides,
    );
    assertSameValue($available, $state['player_attack']['available'] ?? null, $message . ' availability.');
    assertSameValue($disabledReason, $state['player_attack']['disabled_reason'] ?? null, $message . ' reason.');
}

return [
    'Task 9A projects authoritative Turn end and ready weapon presentation' => function (): void {
        $state = task9aProjection();

        assertSameValue(10000, $state['turn']['ends_timeline_ms'] ?? null, 'Turn end comes from configured Turn timing.');
        assertSameValue([
            'key' => 'prototype_weapon_attack',
            'name' => 'Weapon Attack',
            'duration_ms' => 1000,
            'cooldown_started_timeline_ms' => null,
            'cooldown_ready_timeline_ms' => null,
            'available' => true,
            'disabled_reason' => null,
        ], $state['player_attack'] ?? null, 'Ready weapon presentation uses the exact safe allowlist.');
        assertSameValue([
            'key' => 'prototype_health_potion',
            'charge_allowance' => 1,
            'charges_remaining' => 1,
        ], $state['potion'], 'Task 8 Potion projection remains unchanged.');
    },

    'Task 9A weapon and Turn metadata follow authoritative definitions' => function (): void {
        $config = require __DIR__ . '/../ascii-quest/config/combat.php';
        $config['turn_duration_seconds'] = 12.5;
        $config['prototype_balance']['player_actions']['prototype_weapon_attack']['name'] = 'Test Blade';
        $config['prototype_balance']['player_actions']['prototype_weapon_attack']['duration_seconds'] = 1.25;

        $state = task9aProjection(
            ['turn_started_timeline_ms' => 2000],
            [],
            [],
            new CombatDefinitionRegistry($config),
        );

        assertSameValue(14500, $state['turn']['ends_timeline_ms'] ?? null, 'Configured Turn duration controls its end.');
        assertSameValue('Test Blade', $state['player_attack']['name'] ?? null, 'Configured weapon name is authoritative.');
        assertSameValue(1250, $state['player_attack']['duration_ms'] ?? null, 'Configured weapon duration is authoritative.');
    },

    'Task 9A projects the existing authoritative weapon rejection reasons' => function (): void {
        task9aAssertAttackState(
            ['status' => 'victory_loot'],
            [],
            [],
            false,
            'encounter_inactive',
            'Inactive encounter',
        );
        task9aAssertAttackState(
            [],
            [],
            ['current_hp' => 0],
            false,
            'actor_unavailable',
            'Zero-HP Champion',
        );
        task9aAssertAttackState(
            ['enemy_current_hp' => 0],
            [],
            [],
            false,
            'target_unavailable',
            'Zero-HP enemy',
        );
        task9aAssertAttackState(
            [],
            [task9aPlayerWeaponAction([
                'state' => 'pending',
                'active_slot' => 1,
                'resolves_timeline_ms' => 4000,
                'completed_timeline_ms' => null,
            ])],
            [],
            false,
            'actor_busy',
            'Pending player action',
        );
        task9aAssertAttackState(
            [],
            [task9aPlayerWeaponAction()],
            [],
            false,
            'cooldown',
            'Stored weapon cooldown',
        );
        task9aAssertAttackState(
            ['player_actions_remaining' => 0],
            [],
            [],
            false,
            'no_actions',
            'Exhausted player Actions',
        );
        task9aAssertAttackState(
            ['timeline_elapsed_ms' => 9500],
            [],
            [],
            false,
            'insufficient_turn_time',
            'Insufficient Turn time',
        );
    },

    'Task 9A exposes cooldown presentation without weakening combat privacy' => function (): void {
        $action = task9aPlayerWeaponAction();
        $state = task9aProjection([], [$action]);

        assertSameValue(1000, $state['player_attack']['cooldown_started_timeline_ms'] ?? null, 'Cooldown presentation uses its authoritative start.');
        assertSameValue(3500, $state['player_attack']['cooldown_ready_timeline_ms'] ?? null, 'Cooldown presentation uses its authoritative ready position.');
        assertSameValue([
            'key',
            'name',
            'duration_ms',
            'cooldown_started_timeline_ms',
            'cooldown_ready_timeline_ms',
            'available',
            'disabled_reason',
        ], array_keys($state['player_attack'] ?? []), 'No private attack configuration is exposed.');
        assertSameValue([
            'id', 'action_kind', 'definition_key', 'state', 'started_timeline_ms',
            'resolves_timeline_ms', 'cooldown_ready_timeline_ms', 'completed_timeline_ms',
        ], array_keys($state['player_actions'][0]), 'Existing player action history remains compatible.');
        foreach ([
            'next_enemy_decision_timeline_ms',
            'enemy_ai_initialized_timeline_ms',
            'cooldown_ready_timeline_ms',
            'snapshot_base_damage',
            'snapshot_accuracy',
            'request_token',
        ] as $privateKey) {
            assertSameValue(
                false,
                array_key_exists($privateKey, $state['enemy']),
                $privateKey . ' remains absent from the enemy projection.',
            );
        }
    },

    'Task 20 projection shares equipment-aware weapon and skill timing and readiness' => function (): void {
        $provider = new Task20ProjectionEquipment();
        $state = task9aProjection(['timeline_elapsed_ms' => 9050], [], [], null, $provider);
        assertSameValue(943, $state['player_attack']['duration_ms'], 'Projected weapon duration is equipment-adjusted.');
        assertSameValue(true, $state['player_attack']['available'], 'Adjusted weapon fitting 950ms remains available.');
        assertSameValue(900, $state['player_skills'][0]['duration_ms'], 'Projected skill duration is equipment-adjusted.');
        assertSameValue(true, $state['player_skills'][0]['available'], 'Adjusted skill fitting 950ms remains available.');

        $missing = task9aProjection([], [], [], null, new Task20ProjectionEquipment(false));
        assertSameValue(false, $missing['player_attack']['available'], 'Missing weapon is projected unavailable.');
        assertSameValue('weapon_unavailable', $missing['player_attack']['disabled_reason'], 'Missing weapon has controlled public reason.');
    },
];
