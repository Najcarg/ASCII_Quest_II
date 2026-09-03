<?php
declare(strict_types=1);

require_once __DIR__ . '/CharacterStats.php';
require_once __DIR__ . '/CombatClock.php';
require_once __DIR__ . '/CombatDefinitionRegistry.php';
require_once __DIR__ . '/CombatEncounterTrigger.php';

final class CombatService
{
    public function __construct(
        private object $repository,
        private CombatDefinitionRegistry $definitions,
        private CombatClock $clock,
    ) {
    }

    public function movementDecision(
        array $lockedCharacter,
        int $requestedX,
        int $requestedY,
        bool $targetWalkable,
        array $encounterDefinition,
    ): array {
        $currentX = self::integer($lockedCharacter, 'pos_x');
        $currentY = self::integer($lockedCharacter, 'pos_y');
        $enemyX = self::integer($encounterDefinition, 'x');
        $enemyY = self::integer($encounterDefinition, 'y');
        $range = self::integer($encounterDefinition, 'fighting_range');
        $directContact = CombatEncounterTrigger::isDirectContact(
            $requestedX,
            $requestedY,
            $enemyX,
            $enemyY,
        );

        if ($directContact) {
            return [
                'final_x' => $currentX,
                'final_y' => $currentY,
                'start_combat' => CombatEncounterTrigger::isInOrthogonalRange(
                    $currentX,
                    $currentY,
                    $enemyX,
                    $enemyY,
                    $range,
                ),
                'direct_contact' => true,
            ];
        }

        $finalX = $targetWalkable ? $requestedX : $currentX;
        $finalY = $targetWalkable ? $requestedY : $currentY;

        return [
            'final_x' => $finalX,
            'final_y' => $finalY,
            'start_combat' => $targetWalkable &&
                CombatEncounterTrigger::isInOrthogonalRange(
                    $finalX,
                    $finalY,
                    $enemyX,
                    $enemyY,
                    $range,
                ),
            'direct_contact' => false,
        ];
    }

    public function startOrResumeForLockedMovement(
        int $userId,
        array $lockedCharacter,
        array $encounterDefinition,
    ): array {
        $characterId = self::integer($lockedCharacter, 'id');
        if (self::integer($lockedCharacter, 'user_id') !== $userId) {
            throw new OutOfBoundsException('Champion not found.');
        }
        if (($lockedCharacter['life_state'] ?? null) !== 'alive') {
            throw new DomainException('This Champion cannot enter combat.');
        }

        $existing = $this->repository->lockedActiveEncounter($characterId);
        if ($existing !== null) {
            return $this->project($lockedCharacter, $existing);
        }

        $enemyKey = (string) ($encounterDefinition['enemy_key'] ?? '');
        $enemy = $this->definitions->enemy($enemyKey);
        if ($enemy === null) {
            throw new DomainException('Combat enemy is unavailable.');
        }
        if (
            isset($lockedCharacter['current_map_key']) &&
            (string) $lockedCharacter['current_map_key'] !==
                (string) ($encounterDefinition['map_key'] ?? '')
        ) {
            throw new DomainException('Combat encounter is not on this map.');
        }

        $stats = CharacterStats::calculate($lockedCharacter);
        $playerActions = (int) $stats['rates']['action'];
        $potion = $this->definitions->potion('prototype_health_potion');
        if ($potion === null) {
            throw new RuntimeException('Combat potion definition is unavailable.');
        }

        $now = $this->clock->now();
        $encounter = $this->repository->createEncounter($characterId, [
            'enemy_key' => $enemyKey,
            'status' => 'active',
            'active_slot' => 1,
            'enemy_max_hp' => (int) $enemy['maximum_hp'],
            'enemy_current_hp' => (int) $enemy['maximum_hp'],
            'timeline_elapsed_ms' => 0,
            'last_synchronized_at' => $now->format('Y-m-d H:i:s.u'),
            'turn_number' => 1,
            'turn_started_timeline_ms' => 0,
            'next_enemy_decision_timeline_ms' => 0,
            'player_actions_remaining' => $playerActions,
            'enemy_actions_remaining' => (int) $enemy['action'],
            'potion_key' => (string) $potion['key'],
            'potion_charge_allowance' => (int) $potion['charges'],
            'potion_charges_remaining' => (int) $potion['charges'],
            'reward_gold' => (int) $enemy['server_only']['prototype_gold_reward'],
            'reward_experience' => (int) $enemy['server_only']['prototype_raw_exp_reward'],
            'version' => 1,
        ]);

        return $this->project($lockedCharacter, $encounter);
    }

    public function state(int $userId, int $characterId): array
    {
        $character = $this->repository->findOwnedCharacter($userId, $characterId);
        if ($character === null) {
            throw new OutOfBoundsException('Champion not found.');
        }

        $encounter = $this->repository->findActiveEncounter($characterId);
        if ($encounter === null) {
            return [];
        }

        return $this->project($character, $encounter);
    }

    private function project(array $character, array $encounter): array
    {
        $encounterId = self::integer($encounter, 'id');
        $enemy = $this->definitions->enemy((string) ($encounter['enemy_key'] ?? ''));
        if ($enemy === null) {
            throw new RuntimeException('Stored combat enemy is unavailable.');
        }

        $playerActions = [];
        foreach ($this->repository->actionsForEncounter($encounterId) as $action) {
            if (($action['actor'] ?? null) !== 'player') {
                continue;
            }
            $playerActions[] = self::allowlist($action, [
                'id',
                'action_kind',
                'definition_key',
                'state',
                'started_timeline_ms',
                'resolves_timeline_ms',
                'cooldown_ready_timeline_ms',
                'completed_timeline_ms',
            ]);
        }

        $battleEvents = [];
        foreach ($this->repository->eventsForEncounter($encounterId) as $event) {
            $battleEvents[] = self::allowlist($event, [
                'sequence_number',
                'event_type',
                'message',
                'emphasis',
            ]);
        }

        return [
            'encounter_id' => $encounterId,
            'status' => (string) $encounter['status'],
            'server_observed_at' => $this->clock->now()->format(DATE_ATOM),
            'timeline' => [
                'elapsed_ms' => self::integer($encounter, 'timeline_elapsed_ms'),
            ],
            'version' => self::integer($encounter, 'version'),
            'turn' => [
                'number' => self::integer($encounter, 'turn_number'),
                'started_timeline_ms' => self::integer($encounter, 'turn_started_timeline_ms'),
                'player_actions_remaining' => self::integer($encounter, 'player_actions_remaining'),
                'enemy_actions_remaining' => self::integer($encounter, 'enemy_actions_remaining'),
            ],
            'champion' => [
                'id' => self::integer($character, 'id'),
                'current_hp' => self::integer($character, 'current_hp'),
                'current_mana' => self::integer($character, 'current_mana'),
            ],
            'enemy' => [
                'key' => (string) $enemy['key'],
                'name' => (string) $enemy['name'],
                'glyph' => (string) $enemy['glyph'],
                'current_hp' => self::integer($encounter, 'enemy_current_hp'),
                'maximum_hp' => self::integer($encounter, 'enemy_max_hp'),
            ],
            'player_actions' => $playerActions,
            'active_effects' => [],
            'reaction_prompt' => null,
            'potion' => [
                'key' => (string) ($encounter['potion_key'] ?? ''),
                'charge_allowance' => self::integer($encounter, 'potion_charge_allowance'),
                'charges_remaining' => self::integer($encounter, 'potion_charges_remaining'),
            ],
            'battle_events' => $battleEvents,
            'loot_phase' => null,
        ];
    }

    private static function integer(array $values, string $key): int
    {
        $value = $values[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A-?\d+\z/D', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException('Invalid combat integer: ' . $key);
    }

    private static function allowlist(array $values, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                $result[$key] = $values[$key];
            }
        }

        return $result;
    }
}
