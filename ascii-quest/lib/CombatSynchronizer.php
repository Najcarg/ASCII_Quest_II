<?php
declare(strict_types=1);

require_once __DIR__ . '/CombatClock.php';
require_once __DIR__ . '/CaveBrutePolicy.php';
require_once __DIR__ . '/CombatDefinitionRegistry.php';
require_once __DIR__ . '/CombatTurnEngine.php';

final class CombatSynchronizer
{
    private int $maxCatchupMilliseconds;
    private Closure $dueEventProcessor;
    private ?object $repository;
    private ?CombatDefinitionRegistry $definitions;
    private ?CaveBrutePolicy $caveBrutePolicy;

    public function __construct(
        private CombatClock $clock,
        private CombatTurnEngine $turnEngine,
        float $maxCatchupSeconds,
        ?Closure $dueEventProcessor = null,
        ?object $repository = null,
        ?CombatDefinitionRegistry $definitions = null,
        ?CaveBrutePolicy $caveBrutePolicy = null,
    ) {
        if (!is_finite($maxCatchupSeconds) || $maxCatchupSeconds <= 0) {
            throw new InvalidArgumentException('Combat catch-up limit must be positive.');
        }

        $this->maxCatchupMilliseconds = (int) round($maxCatchupSeconds * 1000);
        if ($this->maxCatchupMilliseconds <= 0) {
            throw new InvalidArgumentException('Combat catch-up limit is too short.');
        }

        $this->repository = $repository;
        $this->definitions = $definitions;
        $this->caveBrutePolicy = $caveBrutePolicy;

        $this->dueEventProcessor = $dueEventProcessor ?? ($repository === null
            ? static fn (array $encounter, int $throughTimelineMs): array => $encounter
            : static function (array $encounter, int $throughTimelineMs) use ($repository): array {
                $encounterId = self::integer($encounter, 'id');
                foreach ($repository->lockActionsForEncounter($encounterId) as $action) {
                    if (
                        ($action['actor'] ?? null) !== 'player' ||
                        ($action['action_kind'] ?? null) !== 'weapon' ||
                        ($action['state'] ?? null) !== 'pending'
                    ) {
                        continue;
                    }
                    $resolvesAt = self::integer($action, 'resolves_timeline_ms');
                    if ($resolvesAt > $throughTimelineMs) {
                        continue;
                    }
                    if (!$repository->resolveLockedAction(
                        $encounterId,
                        self::positiveInteger($action, 'id'),
                        $resolvesAt,
                    )) {
                        throw new RuntimeException('Combat action resolution changed concurrently.');
                    }
                }

                return $encounter;
            });
    }

    public function synchronize(
        array $encounter,
        int $playerActionAllowance,
        int $enemyActionAllowance,
        ?array $lockedCharacter = null,
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
        if (
            ($encounter['enemy_ai_initialized_timeline_ms'] ?? null) !== null &&
            self::integer($encounter, 'next_enemy_decision_timeline_ms') <= $cursor
        ) {
            if (
                $lockedCharacter === null ||
                $this->repository === null ||
                $this->definitions === null ||
                $this->caveBrutePolicy === null
            ) {
                throw new LogicException(
                    'Enemy decision processing requires locked combat dependencies.',
                );
            }
            $decisionResult = $this->processEnemyDecisionAtCursor(
                $encounter,
                $lockedCharacter,
                $turnState,
                $cursor,
            );
            $encounter = $decisionResult['encounter'];
            $turnState = $decisionResult['turn_state'];
            // The one-shot Task 5 hook has no later enemy-decision candidate.
            // Task 6 will reuse the returned invocation-local suppression flag.
        }

        if (($encounter['status'] ?? null) !== 'active') {
            return $encounter;
        }

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

    private function processEnemyDecisionAtCursor(
        array $encounter,
        array $lockedCharacter,
        array $turnState,
        int $cursorMs,
    ): array {
        $encounterId = self::positiveInteger($encounter, 'id');
        $enemyDefinition = $this->definitions?->enemy(
            (string) ($encounter['enemy_key'] ?? ''),
        );
        if ($enemyDefinition === null) {
            throw new DomainException('Combat enemy definition is unavailable.');
        }

        $history = $this->repository->lockActionsForEncounter($encounterId);
        $decision = $this->caveBrutePolicy->decide(
            $encounter,
            self::integer($lockedCharacter, 'current_hp'),
            $enemyDefinition,
            $history,
            $turnState,
            $cursorMs,
        );

        return match ($decision['decision'] ?? null) {
            'start' => $this->startEnemyActionAtCursor(
                $encounter,
                $turnState,
                $enemyDefinition,
                (string) ($decision['definition_key'] ?? ''),
                $cursorMs,
            ),
            'wait' => $this->waitForEnemyDecision(
                $encounter,
                $turnState,
                self::integer($decision, 'next_timeline_ms'),
                $cursorMs,
            ),
            'stop' => [
                'encounter' => $encounter,
                'turn_state' => $turnState,
                'started_action' => null,
                'suppress_enemy_decisions' => true,
            ],
            default => throw new DomainException('Enemy decision is invalid.'),
        };
    }

    private function startEnemyActionAtCursor(
        array $encounter,
        array $turnState,
        array $enemyDefinition,
        string $definitionKey,
        int $cursorMs,
    ): array {
        $definition = $enemyDefinition['actions'][$definitionKey] ?? null;
        if (!is_array($definition)) {
            throw new DomainException('Enemy action definition is unavailable.');
        }

        $durationSeconds = $definition['duration_seconds'] ?? null;
        $serverOnly = $definition['server_only'] ?? null;
        if (
            (!is_int($durationSeconds) && !is_float($durationSeconds)) ||
            !is_array($serverOnly) ||
            (!is_int($serverOnly['cooldown_seconds'] ?? null) &&
                !is_float($serverOnly['cooldown_seconds'] ?? null)) ||
            !is_int($serverOnly['prototype_damage'] ?? null) ||
            !is_string($definition['damage_type'] ?? null) ||
            !is_string($definition['kind'] ?? null)
        ) {
            throw new DomainException('Enemy action definition is invalid.');
        }

        $durationMs = (int) round($durationSeconds * 1000);
        $cooldownMs = (int) round($serverOnly['cooldown_seconds'] * 1000);
        if (!$this->turnEngine->canStartAction(
            $turnState,
            'enemy',
            $cursorMs,
            $durationMs,
        )) {
            throw new DomainException('Enemy combat action cannot start.');
        }
        if ($cooldownMs <= 0) {
            throw new DomainException('Enemy action cooldown is invalid.');
        }

        $turnState = $this->turnEngine->consumeAction(
            $turnState,
            'enemy',
            $cursorMs,
            $durationMs,
        );
        $action = $this->repository->createAction(
            self::positiveInteger($encounter, 'id'),
            [
                'actor' => 'enemy',
                'action_kind' => (string) ($definition['kind'] ?? ''),
                'definition_key' => $definitionKey,
                'request_token' => null,
                'active_slot' => 1,
                'state' => 'pending',
                'started_timeline_ms' => $cursorMs,
                'resolves_timeline_ms' => $cursorMs + $durationMs,
                'cooldown_ready_timeline_ms' => $cursorMs + $cooldownMs,
                'snapshot_weapon_key' => null,
                'snapshot_damage_type' => $definition['damage_type'],
                'snapshot_base_damage' => $serverOnly['prototype_damage'],
                'snapshot_accuracy' => null,
                'snapshot_critical_chance' => null,
                'snapshot_critical_damage' => null,
            ],
        );

        $encounter['enemy_actions_remaining'] = $turnState['enemy_actions_remaining'];
        $encounter['next_enemy_decision_timeline_ms'] =
            self::integer($action, 'resolves_timeline_ms');

        return [
            'encounter' => $encounter,
            'turn_state' => $turnState,
            'started_action' => $action,
            'suppress_enemy_decisions' => false,
        ];
    }

    private function waitForEnemyDecision(
        array $encounter,
        array $turnState,
        int $nextTimelineMs,
        int $cursorMs,
    ): array {
        if ($nextTimelineMs <= $cursorMs) {
            throw new DomainException('Enemy wait position must be in the future.');
        }
        $encounter['next_enemy_decision_timeline_ms'] = $nextTimelineMs;

        return [
            'encounter' => $encounter,
            'turn_state' => $turnState,
            'started_action' => null,
            'suppress_enemy_decisions' => false,
        ];
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
