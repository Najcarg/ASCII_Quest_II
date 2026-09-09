<?php
declare(strict_types=1);

require_once __DIR__ . '/CombatClock.php';
require_once __DIR__ . '/CombatTurnEngine.php';

final class CombatSynchronizer
{
    private int $maxCatchupMilliseconds;
    private Closure $dueEventProcessor;

    public function __construct(
        private CombatClock $clock,
        private CombatTurnEngine $turnEngine,
        float $maxCatchupSeconds,
        ?Closure $dueEventProcessor = null,
    ) {
        if (!is_finite($maxCatchupSeconds) || $maxCatchupSeconds <= 0) {
            throw new InvalidArgumentException('Combat catch-up limit must be positive.');
        }

        $this->maxCatchupMilliseconds = (int) round($maxCatchupSeconds * 1000);
        if ($this->maxCatchupMilliseconds <= 0) {
            throw new InvalidArgumentException('Combat catch-up limit is too short.');
        }

        $this->dueEventProcessor = $dueEventProcessor ??
            static fn (array $encounter, int $throughTimelineMs): array => $encounter;
    }

    public function synchronize(
        array $encounter,
        int $playerActionAllowance,
        int $enemyActionAllowance,
    ): array {
        $timeline = self::integer($encounter, 'timeline_elapsed_ms');
        $lastSynchronizedAt = self::utcTimestamp($encounter, 'last_synchronized_at');
        $serverNow = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        $actualGapMilliseconds = max(
            0,
            self::epochMilliseconds($serverNow) - self::epochMilliseconds($lastSynchronizedAt),
        );
        $appliedGapMilliseconds = min(
            $actualGapMilliseconds,
            $this->maxCatchupMilliseconds,
        );

        $targetTimeline = $timeline + $appliedGapMilliseconds;
        $encounter['last_synchronized_at'] = $serverNow->format('Y-m-d H:i:s.u');

        if (($encounter['status'] ?? null) !== 'active') {
            return $encounter;
        }

        $turnStart = self::integer($encounter, 'turn_started_timeline_ms');
        $turnState = $this->turnEngine->synchronizeTurn(
            [],
            $turnStart,
            $playerActionAllowance,
            $enemyActionAllowance,
        );
        $turnState['turn_number'] = self::positiveInteger($encounter, 'turn_number');
        $turnState['player_actions_remaining'] = self::integer(
            $encounter,
            'player_actions_remaining',
        );
        $turnState['enemy_actions_remaining'] = self::integer(
            $encounter,
            'enemy_actions_remaining',
        );

        $cursor = $timeline;
        while ($cursor < $targetTimeline) {
            $nextTimeline = min(
                $targetTimeline,
                $turnState['turn_ends_timeline_ms'],
            );
            if ($nextTimeline <= $cursor) {
                throw new DomainException('Stored combat Turn is behind its logical timeline.');
            }

            $encounter['timeline_elapsed_ms'] = $nextTimeline;

            // This seam deliberately runs before a same-position Turn rollover.
            // Later tasks can resolve persisted due actions here in chronological
            // order without changing the authoritative timeline architecture.
            $encounter = ($this->dueEventProcessor)($encounter, $nextTimeline);
            $processedTimeline = self::integer($encounter, 'timeline_elapsed_ms');
            if ($processedTimeline <= $cursor || $processedTimeline > $nextTimeline) {
                throw new DomainException(
                    'Combat due-event processing returned an invalid logical timeline.',
                );
            }

            $turnState['player_actions_remaining'] = self::integer(
                $encounter,
                'player_actions_remaining',
            );
            $turnState['enemy_actions_remaining'] = self::integer(
                $encounter,
                'enemy_actions_remaining',
            );
            if (($encounter['status'] ?? null) !== 'active') {
                $targetTimeline = $processedTimeline;
                $cursor = $processedTimeline;
                break;
            }

            $turnState = $this->turnEngine->synchronizeTurn(
                $turnState,
                $processedTimeline,
                $playerActionAllowance,
                $enemyActionAllowance,
            );
            self::copyTurnState($turnState, $encounter);
            $cursor = $processedTimeline;
        }

        $encounter['timeline_elapsed_ms'] = $targetTimeline;
        $encounter['next_enemy_decision_timeline_ms'] = max(
            self::integer($encounter, 'next_enemy_decision_timeline_ms'),
            self::integer($encounter, 'turn_started_timeline_ms'),
        );

        return $encounter;
    }

    private static function epochMilliseconds(DateTimeImmutable $time): int
    {
        return ((int) $time->format('U') * 1000) + intdiv((int) $time->format('u'), 1000);
    }

    private static function integer(array $values, string $key): int
    {
        $value = $values[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A\d+\z/D', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException('Invalid combat integer: ' . $key);
    }

    private static function positiveInteger(array $values, string $key): int
    {
        $value = self::integer($values, $key);
        if ($value <= 0) {
            throw new InvalidArgumentException('Invalid positive combat integer: ' . $key);
        }

        return $value;
    }

    private static function copyTurnState(array $turnState, array &$encounter): void
    {
        foreach ([
            'turn_number',
            'turn_started_timeline_ms',
            'player_actions_remaining',
            'enemy_actions_remaining',
        ] as $key) {
            $encounter[$key] = $turnState[$key];
        }
    }

    private static function utcTimestamp(array $values, string $key): DateTimeImmutable
    {
        $value = $values[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Invalid combat timestamp: ' . $key);
        }

        $timestamp = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            $value,
            new DateTimeZone('UTC'),
        );
        if ($timestamp === false || $timestamp->format('Y-m-d H:i:s.u') !== $value) {
            throw new InvalidArgumentException('Invalid combat timestamp: ' . $key);
        }

        return $timestamp;
    }
}
