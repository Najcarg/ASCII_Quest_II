<?php
declare(strict_types=1);

require_once __DIR__ . '/CombatDefinitionRegistry.php';

final class CombatStateProjector
{
    public function __construct(
        private object $repository,
        private CombatDefinitionRegistry $definitions,
    ) {
    }

    public function project(array $character, array $encounter): array
    {
        $encounterId = self::integer($encounter, 'id');
        $enemy = $this->definitions->enemy((string) ($encounter['enemy_key'] ?? ''));
        if ($enemy === null) {
            throw new RuntimeException('Stored combat enemy is unavailable.');
        }

        $lockedActions = $this->repository->actionsForEncounter($encounterId);
        $playerActions = [];
        $activeEnemyAction = null;
        $reactionPrompt = null;
        $timelineMs = self::integer($encounter, 'timeline_elapsed_ms');
        foreach ($lockedActions as $action) {
            if (($action['actor'] ?? null) === 'player') {
                $playerActions[] = self::allowlist($action, [
                    'id', 'action_kind', 'definition_key', 'state',
                    'started_timeline_ms', 'resolves_timeline_ms',
                    'cooldown_ready_timeline_ms', 'completed_timeline_ms',
                ]);
            }
            if (
                $activeEnemyAction !== null ||
                ($action['actor'] ?? null) !== 'enemy' ||
                ($action['state'] ?? null) !== 'pending'
            ) {
                continue;
            }

            $definitionKey = (string) ($action['definition_key'] ?? '');
            $definition = $enemy['actions'][$definitionKey] ?? null;
            if (!is_array($definition)) {
                throw new RuntimeException('Stored enemy combat action is unavailable.');
            }
            $activeEnemyAction = [
                'id' => self::integer($action, 'id'),
                'action_kind' => (string) $action['action_kind'],
                'definition_key' => $definitionKey,
                'name' => (string) $definition['name'],
                'damage_type' => (string) $definition['damage_type'],
                'state' => 'pending',
                'started_timeline_ms' => self::integer($action, 'started_timeline_ms'),
                'resolves_timeline_ms' => self::integer($action, 'resolves_timeline_ms'),
            ];

            if ($reactionPrompt === null) {
                $reactionPrompt = self::reactionPrompt(
                    $action,
                    $definition,
                    $timelineMs,
                );
            }
        }

        $events = [];
        foreach ($this->repository->eventsForEncounter($encounterId) as $event) {
            $events[] = self::allowlist($event, [
                'sequence_number', 'event_type', 'message', 'emphasis',
            ]);
        }

        return [
            'encounter_id' => $encounterId,
            'status' => (string) $encounter['status'],
            'server_observed_at' => (new DateTimeImmutable(
                (string) ($encounter['last_synchronized_at'] ?? ''),
                new DateTimeZone('UTC'),
            ))->format(DATE_ATOM),
            'timeline' => ['elapsed_ms' => self::integer($encounter, 'timeline_elapsed_ms')],
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
                'active_action' => $activeEnemyAction,
            ],
            'player_actions' => $playerActions,
            'active_effects' => [],
            'reaction_prompt' => $reactionPrompt,
            'potion' => [
                'key' => (string) ($encounter['potion_key'] ?? ''),
                'charge_allowance' => self::integer($encounter, 'potion_charge_allowance'),
                'charges_remaining' => self::integer($encounter, 'potion_charges_remaining'),
            ],
            'battle_events' => $events,
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

    private static function reactionPrompt(
        array $action,
        array $definition,
        int $timelineMs,
    ): ?array {
        $token = $action['block_token'] ?? null;
        $expiresTimelineMs = $action['block_expires_timeline_ms'] ?? null;
        $promptX = $action['block_prompt_x'] ?? null;
        $promptY = $action['block_prompt_y'] ?? null;
        if (
            ($action['block_attempted_timeline_ms'] ?? null) !== null ||
            !is_string($token) ||
            preg_match('/\A[0-9a-f]{64}\z/D', $token) !== 1 ||
            (!is_int($expiresTimelineMs) && !is_string($expiresTimelineMs)) ||
            preg_match('/\A\d+\z/D', (string) $expiresTimelineMs) !== 1 ||
            (int) $expiresTimelineMs <= $timelineMs ||
            !is_numeric($promptX) ||
            !is_numeric($promptY) ||
            (float) $promptX < 0.0 ||
            (float) $promptX > 1.0 ||
            (float) $promptY < 0.0 ||
            (float) $promptY > 1.0
        ) {
            return null;
        }

        return [
            'enemy_action_id' => self::integer($action, 'id'),
            'definition_key' => (string) $action['definition_key'],
            'name' => (string) $definition['name'],
            'damage_type' => (string) $definition['damage_type'],
            'resolves_timeline_ms' => self::integer($action, 'resolves_timeline_ms'),
            'expires_timeline_ms' => (int) $expiresTimelineMs,
            'block_token' => $token,
            'x' => (float) $promptX,
            'y' => (float) $promptY,
        ];
    }
}
