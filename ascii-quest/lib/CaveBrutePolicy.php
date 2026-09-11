<?php
declare(strict_types=1);

require_once __DIR__ . '/CombatTurnEngine.php';

final class CaveBrutePolicy
{
    public function __construct(private CombatTurnEngine $turnEngine)
    {
    }

    public function decide(
        array $encounter,
        int $championCurrentHp,
        array $enemyDefinition,
        array $lockedActionHistory,
        array $turnState,
        int $timelineMilliseconds,
    ): array {
        if ($timelineMilliseconds < 0 || $championCurrentHp < 0) {
            throw new InvalidArgumentException('Combat state values cannot be negative.');
        }

        $enemyCurrentHp = self::requiredNonNegativeInteger(
            $encounter,
            'enemy_current_hp',
            'Enemy current HP',
        );
        if (($encounter['status'] ?? null) !== 'active'
            || $enemyCurrentHp === 0
            || $championCurrentHp === 0) {
            return ['decision' => 'stop'];
        }

        $pendingEnemyAction = $this->pendingEnemyAction(
            $lockedActionHistory,
            $timelineMilliseconds,
        );
        if ($pendingEnemyAction !== null) {
            return [
                'decision' => 'wait',
                'next_timeline_ms' => $pendingEnemyAction['resolves_timeline_ms'],
            ];
        }

        $turnEnd = self::requiredInteger(
            $turnState,
            'turn_ends_timeline_ms',
            'Turn end',
        );
        if ($turnEnd <= $timelineMilliseconds) {
            throw new DomainException('Enemy decision requires a future Turn boundary.');
        }
        if (self::requiredNonNegativeInteger(
            $turnState,
            'enemy_actions_remaining',
            'Enemy Actions remaining',
        ) === 0) {
            return ['decision' => 'wait', 'next_timeline_ms' => $turnEnd];
        }

        $futurePositions = [$turnEnd];
        foreach (['fire_slam', 'smash'] as $definitionKey) {
            $definition = $enemyDefinition['actions'][$definitionKey] ?? null;
            if (!is_array($definition)) {
                throw new InvalidArgumentException("Enemy action {$definitionKey} is required.");
            }

            $durationSeconds = $definition['duration_seconds'] ?? null;
            if (!is_int($durationSeconds) && !is_float($durationSeconds)) {
                throw new InvalidArgumentException('Enemy action duration must be numeric.');
            }
            $durationMilliseconds = (int) round($durationSeconds * 1000);
            if ($durationMilliseconds <= 0) {
                throw new InvalidArgumentException('Enemy action duration must be positive.');
            }

            $cooldownReady = $this->latestCooldownReadyTimeline(
                $lockedActionHistory,
                $definitionKey,
            );
            if ($timelineMilliseconds >= $cooldownReady
                && $this->turnEngine->canStartAction(
                    $turnState,
                    'enemy',
                    $timelineMilliseconds,
                    $durationMilliseconds,
                )) {
                return ['decision' => 'start', 'definition_key' => $definitionKey];
            }
            if ($cooldownReady > $timelineMilliseconds) {
                $futurePositions[] = $cooldownReady;
            }
        }

        $nextTimeline = min($futurePositions);
        if ($nextTimeline <= $timelineMilliseconds) {
            throw new DomainException('Enemy wait position must be in the future.');
        }

        return ['decision' => 'wait', 'next_timeline_ms' => $nextTimeline];
    }

    private function pendingEnemyAction(array $actionHistory, int $timelineMilliseconds): ?array
    {
        $pending = null;
        foreach ($actionHistory as $action) {
            if (($action['actor'] ?? null) !== 'enemy'
                || ($action['state'] ?? null) !== 'pending') {
                continue;
            }

            $resolvesAt = self::requiredInteger(
                $action,
                'resolves_timeline_ms',
                'Pending enemy action resolution',
            );
            if ($resolvesAt <= $timelineMilliseconds) {
                throw new DomainException('Pending enemy action must be resolved before its next decision.');
            }
            if ($pending === null || $resolvesAt < $pending['resolves_timeline_ms']) {
                $pending = $action;
                $pending['resolves_timeline_ms'] = $resolvesAt;
            }
        }

        return $pending;
    }

    private function latestCooldownReadyTimeline(array $actionHistory, string $definitionKey): int
    {
        $latest = 0;
        foreach ($actionHistory as $action) {
            if (($action['actor'] ?? null) !== 'enemy'
                || ($action['definition_key'] ?? null) !== $definitionKey
                || !in_array($action['state'] ?? null, ['pending', 'resolved'], true)) {
                continue;
            }

            $latest = max(
                $latest,
                self::requiredNonNegativeInteger(
                    $action,
                    'cooldown_ready_timeline_ms',
                    'Enemy action cooldown',
                ),
            );
        }

        return $latest;
    }

    private static function requiredInteger(array $values, string $key, string $label): int
    {
        $value = $values[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException("{$label} must be an integer.");
    }

    private static function requiredNonNegativeInteger(
        array $values,
        string $key,
        string $label,
    ): int {
        $value = self::requiredInteger($values, $key, $label);
        if ($value < 0) {
            throw new InvalidArgumentException("{$label} cannot be negative.");
        }

        return $value;
    }
}
