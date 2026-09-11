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

        $playerActions = [];
        foreach ($this->repository->actionsForEncounter($encounterId) as $action) {
            if (($action['actor'] ?? null) === 'player') {
                $playerActions[] = self::allowlist($action, [
                    'id', 'action_kind', 'definition_key', 'state',
                    'started_timeline_ms', 'resolves_timeline_ms',
                    'cooldown_ready_timeline_ms', 'completed_timeline_ms',
                ]);
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
            ],
            'player_actions' => $playerActions,
            'active_effects' => [],
            'reaction_prompt' => null,
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
}
