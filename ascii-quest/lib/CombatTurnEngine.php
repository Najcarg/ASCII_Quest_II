<?php
declare(strict_types=1);

final class CombatTurnEngine
{
    private int $turnDurationMilliseconds;

    public function __construct(float $turnDurationSeconds)
    {
        if (!is_finite($turnDurationSeconds) || $turnDurationSeconds <= 0) {
            throw new InvalidArgumentException('Turn duration must be positive.');
        }

        $this->turnDurationMilliseconds = (int) round($turnDurationSeconds * 1000);
        if ($this->turnDurationMilliseconds <= 0) {
            throw new InvalidArgumentException('Turn duration is too short.');
        }
    }

    public function synchronizeTurn(
        array $state,
        int $timelineMilliseconds,
        int $playerAction,
        int $enemyAction,
    ): array {
        self::assertTimeline($timelineMilliseconds);
        self::assertAllowance($playerAction, 'Player');
        self::assertAllowance($enemyAction, 'Enemy');

        if ($state === []) {
            return [
                'turn_number' => 1,
                'turn_started_timeline_ms' => $timelineMilliseconds,
                'turn_ends_timeline_ms' =>
                    $timelineMilliseconds + $this->turnDurationMilliseconds,
                'player_actions_remaining' => $playerAction,
                'enemy_actions_remaining' => $enemyAction,
                'player_busy_until_timeline_ms' => null,
                'enemy_busy_until_timeline_ms' => null,
            ];
        }

        $this->validateState($state);
        $turnStart = $state['turn_started_timeline_ms'];
        if ($timelineMilliseconds < $turnStart) {
            throw new DomainException('Logical combat timeline cannot move backward.');
        }

        $crossedBoundaries = intdiv(
            $timelineMilliseconds - $turnStart,
            $this->turnDurationMilliseconds,
        );
        if ($crossedBoundaries > 0) {
            $state['turn_number'] += $crossedBoundaries;
            $state['turn_started_timeline_ms'] +=
                $crossedBoundaries * $this->turnDurationMilliseconds;
            $state['player_actions_remaining'] = $playerAction;
            $state['enemy_actions_remaining'] = $enemyAction;
        }

        $state['turn_ends_timeline_ms'] =
            $state['turn_started_timeline_ms'] + $this->turnDurationMilliseconds;
        foreach (['player', 'enemy'] as $actor) {
            $busyKey = $actor . '_busy_until_timeline_ms';
            if ($state[$busyKey] !== null && $state[$busyKey] <= $timelineMilliseconds) {
                $state[$busyKey] = null;
            }
        }

        return $state;
    }

    public function canStartAction(
        array $state,
        string $actor,
        int $timelineMilliseconds,
        int $durationMilliseconds,
    ): bool {
        $this->validateState($state);
        $actor = self::validateActor($actor);
        self::assertTimeline($timelineMilliseconds);
        if ($durationMilliseconds <= 0) {
            throw new InvalidArgumentException('Action duration must be positive.');
        }
        if ($timelineMilliseconds < $state['turn_started_timeline_ms']) {
            throw new DomainException('Logical combat timeline cannot move backward.');
        }

        $remainingKey = $actor . '_actions_remaining';
        if ($state[$remainingKey] <= 0) {
            return false;
        }

        $busyUntil = $state[$actor . '_busy_until_timeline_ms'];
        if ($busyUntil !== null && $busyUntil > $timelineMilliseconds) {
            return false;
        }

        return $timelineMilliseconds + $durationMilliseconds <=
            $state['turn_ends_timeline_ms'];
    }

    public function consumeAction(
        array $state,
        string $actor,
        int $timelineMilliseconds,
        int $durationMilliseconds,
    ): array {
        $actor = self::validateActor($actor);
        if (!$this->canStartAction(
            $state,
            $actor,
            $timelineMilliseconds,
            $durationMilliseconds,
        )) {
            throw new DomainException('Combat action cannot start.');
        }

        $remainingKey = $actor . '_actions_remaining';
        $state[$remainingKey]--;
        $state[$actor . '_busy_until_timeline_ms'] =
            $timelineMilliseconds + $durationMilliseconds;

        return $state;
    }

    private function validateState(array $state): void
    {
        foreach ([
            'turn_number',
            'turn_started_timeline_ms',
            'turn_ends_timeline_ms',
            'player_actions_remaining',
            'enemy_actions_remaining',
        ] as $key) {
            if (!is_int($state[$key] ?? null)) {
                throw new InvalidArgumentException("Turn state {$key} must be an integer.");
            }
        }

        if ($state['turn_number'] <= 0 || $state['turn_started_timeline_ms'] < 0) {
            throw new InvalidArgumentException('Turn identity is invalid.');
        }
        if (
            $state['turn_ends_timeline_ms'] !==
            $state['turn_started_timeline_ms'] + $this->turnDurationMilliseconds
        ) {
            throw new InvalidArgumentException('Turn end does not match configured duration.');
        }
        foreach (['player_actions_remaining', 'enemy_actions_remaining'] as $key) {
            if ($state[$key] < 0) {
                throw new InvalidArgumentException('Remaining Actions cannot be negative.');
            }
        }
        foreach (['player', 'enemy'] as $actor) {
            $busyKey = $actor . '_busy_until_timeline_ms';
            if (!array_key_exists($busyKey, $state)) {
                throw new InvalidArgumentException("Turn state {$busyKey} is required.");
            }
            if ($state[$busyKey] !== null && !is_int($state[$busyKey])) {
                throw new InvalidArgumentException("Turn state {$busyKey} must be an integer or null.");
            }
        }
    }

    private static function validateActor(string $actor): string
    {
        if ($actor !== 'player' && $actor !== 'enemy') {
            throw new InvalidArgumentException('Combat actor must be player or enemy.');
        }

        return $actor;
    }

    private static function assertTimeline(int $timelineMilliseconds): void
    {
        if ($timelineMilliseconds < 0) {
            throw new InvalidArgumentException('Logical combat timeline cannot be negative.');
        }
    }

    private static function assertAllowance(int $allowance, string $label): void
    {
        if ($allowance <= 0) {
            throw new InvalidArgumentException("{$label} Action allowance must be positive.");
        }
    }
}
