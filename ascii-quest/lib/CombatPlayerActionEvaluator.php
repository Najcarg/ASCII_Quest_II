<?php
declare(strict_types=1);

require_once __DIR__ . '/CombatTurnEngine.php';

final class CombatPlayerActionEvaluator
{
    public const WEAPON_ACTION_KEY = 'prototype_weapon_attack';

    public function __construct(private CombatTurnEngine $turnEngine)
    {
    }

    public function turnEndsTimelineMs(array $encounter): int
    {
        return $this->turnState($encounter)['turn_ends_timeline_ms'];
    }

    public function evaluate(
        array $encounter,
        array $character,
        array $actions,
        array $definition,
    ): array {
        $definitionKey = (string) ($definition['key'] ?? '');
        if (
            $definitionKey === '' ||
            !in_array($definition['kind'] ?? null, ['weapon', 'skill'], true)
        ) {
            throw new InvalidArgumentException('Player combat action definition is invalid.');
        }

        $durationMs = (int) round((float) ($definition['duration_seconds'] ?? 0) * 1000);
        if ($durationMs <= 0) {
            throw new InvalidArgumentException('Player combat action duration is invalid.');
        }

        $timelineMs = self::integer($encounter, 'timeline_elapsed_ms');
        $cooldownStartedTimelineMs = null;
        $cooldownReadyTimelineMs = null;
        $playerBusy = false;
        foreach ($actions as $action) {
            if (($action['actor'] ?? null) !== 'player') {
                continue;
            }
            if (($action['state'] ?? null) === 'pending') {
                $playerBusy = true;
            }
            if (
                ($action['definition_key'] ?? null) !== $definitionKey ||
                ($action['cooldown_ready_timeline_ms'] ?? null) === null
            ) {
                continue;
            }

            $readyTimelineMs = self::integer($action, 'cooldown_ready_timeline_ms');
            if ($cooldownReadyTimelineMs === null || $readyTimelineMs > $cooldownReadyTimelineMs) {
                $cooldownStartedTimelineMs = self::integer($action, 'started_timeline_ms');
                $cooldownReadyTimelineMs = $readyTimelineMs;
            }
        }

        $result = [
            'duration_ms' => $durationMs,
            'cooldown_started_timeline_ms' => $cooldownStartedTimelineMs,
            'cooldown_ready_timeline_ms' => $cooldownReadyTimelineMs,
            'available' => false,
            'disabled_reason' => null,
        ];

        $disabledReason = match (true) {
            ($encounter['status'] ?? null) !== 'active' => 'encounter_inactive',
            self::integer($character, 'current_hp') <= 0 => 'actor_unavailable',
            self::integer($encounter, 'enemy_current_hp') <= 0 => 'target_unavailable',
            $playerBusy => 'actor_busy',
            $cooldownReadyTimelineMs !== null && $cooldownReadyTimelineMs > $timelineMs => 'cooldown',
            self::integer($encounter, 'player_actions_remaining') <= 0 => 'no_actions',
            !$this->turnEngine->canStartAction(
                $this->turnState($encounter),
                'player',
                $timelineMs,
                $durationMs,
            ) => 'insufficient_turn_time',
            default => null,
        };

        $result['available'] = $disabledReason === null;
        $result['disabled_reason'] = $disabledReason;

        return $result;
    }

    private function turnState(array $encounter): array
    {
        $state = $this->turnEngine->synchronizeTurn(
            [],
            self::integer($encounter, 'turn_started_timeline_ms'),
            1,
            1,
        );
        $state['turn_number'] = self::integer($encounter, 'turn_number');
        $state['player_actions_remaining'] = self::integer(
            $encounter,
            'player_actions_remaining',
        );
        $state['enemy_actions_remaining'] = self::integer(
            $encounter,
            'enemy_actions_remaining',
        );

        return $state;
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
}
