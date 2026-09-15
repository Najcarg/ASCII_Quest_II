<?php
declare(strict_types=1);

require_once __DIR__ . '/CharacterStats.php';
require_once __DIR__ . '/CaveBrutePolicy.php';
require_once __DIR__ . '/CombatAccessGuard.php';
require_once __DIR__ . '/CombatClock.php';
require_once __DIR__ . '/CombatDefinitionRegistry.php';
require_once __DIR__ . '/CombatEncounterTrigger.php';
require_once __DIR__ . '/CombatEquipmentProvider.php';
require_once __DIR__ . '/CombatStateProjector.php';
require_once __DIR__ . '/CombatSynchronizer.php';
require_once __DIR__ . '/CombatTurnEngine.php';
require_once __DIR__ . '/PrototypeCombatEquipmentProvider.php';

final class CombatService
{
    private CombatSynchronizer $synchronizer;
    private CombatTurnEngine $turnEngine;
    private CombatEquipmentProvider $equipmentProvider;
    private CombatStateProjector $projector;

    public function __construct(
        private object $repository,
        private CombatDefinitionRegistry $definitions,
        private CombatClock $clock,
        ?CombatSynchronizer $synchronizer = null,
        ?CombatEquipmentProvider $equipmentProvider = null,
        ?CombatStateProjector $projector = null,
    ) {
        $this->turnEngine = new CombatTurnEngine($definitions->turnDurationSeconds());
        $this->synchronizer = $synchronizer ?? new CombatSynchronizer(
            $clock,
            $this->turnEngine,
            $definitions->maxDisconnectedCatchupSeconds(),
            null,
            $repository,
            $definitions,
            new CaveBrutePolicy($this->turnEngine),
        );
        $this->equipmentProvider = $equipmentProvider ?? new PrototypeCombatEquipmentProvider($definitions);
        $this->projector = $projector ?? new CombatStateProjector($repository, $definitions);
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
            return $this->projector->project($lockedCharacter, $existing);
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
            'enemy_ai_initialized_timeline_ms' => 0,
            'player_actions_remaining' => $playerActions,
            'enemy_actions_remaining' => (int) $enemy['action'],
            'potion_key' => (string) $potion['key'],
            'potion_charge_allowance' => (int) $potion['charges'],
            'potion_charges_remaining' => (int) $potion['charges'],
            'reward_gold' => (int) $enemy['server_only']['prototype_gold_reward'],
            'reward_experience' => (int) $enemy['server_only']['prototype_raw_exp_reward'],
            'version' => 1,
        ]);

        return $this->projector->project($lockedCharacter, $encounter);
    }

    public function state(int $userId, int $characterId): array
    {
        $guard = new CombatAccessGuard($this->repository);

        try {
            $decision = $guard->beginAtomic(
                CombatAccessGuard::GAME_LOAD,
                $userId,
                $characterId,
            );
            $character = $decision['character'];
            $encounter = $decision['active_encounter'];
            if ($encounter === null) {
                $guard->commit();

                return [];
            }

            $enemy = $this->definitions->enemy((string) ($encounter['enemy_key'] ?? ''));
            if ($enemy === null) {
                throw new RuntimeException('Stored combat enemy is unavailable.');
            }
            $stats = CharacterStats::calculate($character);
            $synchronization = $this->synchronizer->synchronize(
                $encounter,
                $character,
                (int) $stats['rates']['action'],
                (int) $enemy['action'],
            );
            $synchronized = $synchronization['encounter'];
            $character = $synchronization['character'];
            $encounterId = self::integer($encounter, 'id');
            $expectedVersion = self::integer($encounter, 'version');
            $synchronizationSaved = $this->repository->updateLockedEncounterSynchronization(
                $encounterId,
                $synchronized,
                $expectedVersion,
            );
            if (!$synchronizationSaved) {
                throw new RuntimeException('Combat state changed concurrently. Please retry.');
            }
            $synchronized['version'] = $expectedVersion + 1;

            $state = $this->projector->project($character, $synchronized);
            $guard->commit();

            return $state;
        } catch (Throwable $exception) {
            $guard->rollBack();
            throw $exception;
        }
    }

    public function startPlayerAction(
        int $userId,
        int $characterId,
        string $actionKey,
        string $requestToken,
    ): array
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $requestToken) !== 1) {
            throw new InvalidArgumentException('Invalid combat request token.');
        }

        $guard = new CombatAccessGuard($this->repository);
        $synchronizationPersisted = false;
        try {
            $decision = $guard->beginAtomic(CombatAccessGuard::GAME_LOAD, $userId, $characterId);
            $character = $decision['character'];
            $encounter = $decision['active_encounter'];
            if ($encounter === null) {
                throw new DomainException('No active combat encounter was found.');
            }

            $enemy = $this->definitions->enemy((string) ($encounter['enemy_key'] ?? ''));
            if ($enemy === null) {
                throw new RuntimeException('Stored combat enemy is unavailable.');
            }
            $stats = CharacterStats::calculate($character);
            $synchronization = $this->synchronizer->synchronize(
                $encounter,
                $character,
                (int) $stats['rates']['action'],
                (int) $enemy['action'],
            );
            $synchronized = $synchronization['encounter'];
            $character = $synchronization['character'];
            $encounterId = self::integer($encounter, 'id');
            $this->persistSynchronization(
                $encounterId,
                $synchronized,
                self::integer($encounter, 'version'),
            );
            $synchronizationPersisted = true;
            if (($synchronized['status'] ?? null) !== 'active') {
                throw new DomainException('Combat is no longer active.');
            }

            $replay = $this->repository->lockActionByRequestToken($encounterId, $requestToken);
            if ($replay !== null) {
                if (
                    ($replay['actor'] ?? null) !== 'player' ||
                    ($replay['action_kind'] ?? null) !== 'weapon' ||
                    ($replay['definition_key'] ?? null) !== $actionKey
                ) {
                    throw new DomainException('Combat request token collides with another action.');
                }
                $state = $this->projector->project($character, $synchronized);
                $guard->commit();

                return $state;
            }

            if (self::integer($character, 'current_hp') <= 0) {
                throw new DomainException('The Champion cannot begin another action.');
            }
            if (self::integer($synchronized, 'enemy_current_hp') <= 0) {
                throw new DomainException('The enemy cannot receive another action.');
            }

            $definition = $this->definitions->playerAction($actionKey);
            if ($definition === null || ($definition['key'] ?? null) !== $actionKey || ($definition['kind'] ?? null) !== 'weapon') {
                throw new DomainException('Combat weapon action is unavailable.');
            }

            $timeline = self::integer($synchronized, 'timeline_elapsed_ms');
            foreach ($this->repository->lockActionsForEncounter($encounterId) as $action) {
                if (($action['actor'] ?? null) !== 'player') {
                    continue;
                }
                if (($action['state'] ?? null) === 'pending') {
                    throw new DomainException('The Champion is already executing an action.');
                }
                if (($action['definition_key'] ?? null) === $actionKey && self::integer($action, 'cooldown_ready_timeline_ms') > $timeline) {
                    throw new DomainException('That weapon action is cooling down.');
                }
            }

            $durationMs = (int) round((float) $definition['duration_seconds'] * 1000);
            $cooldownMs = (int) round((float) $definition['cooldown_seconds'] * 1000);
            $turnState = $this->turnEngine->synchronizeTurn(
                [],
                self::integer($synchronized, 'turn_started_timeline_ms'),
                (int) $stats['rates']['action'],
                (int) $enemy['action'],
            );
            $turnState['turn_number'] = self::integer($synchronized, 'turn_number');
            $turnState['player_actions_remaining'] = self::integer($synchronized, 'player_actions_remaining');
            $turnState['enemy_actions_remaining'] = self::integer($synchronized, 'enemy_actions_remaining');
            if (!$this->turnEngine->canStartAction($turnState, 'player', $timeline, $durationMs)) {
                throw new DomainException('Combat action cannot start.');
            }

            $snapshot = $this->equipmentProvider->offensiveSnapshot($character, $actionKey);
            $turnState = $this->turnEngine->consumeAction($turnState, 'player', $timeline, $durationMs);
            $synchronized['player_actions_remaining'] = $turnState['player_actions_remaining'];
            $this->repository->createAction($encounterId, [
                'actor' => 'player',
                'action_kind' => (string) $definition['kind'],
                'definition_key' => (string) $definition['key'],
                'request_token' => $requestToken,
                'active_slot' => 1,
                'state' => 'pending',
                'started_timeline_ms' => $timeline,
                'resolves_timeline_ms' => $timeline + $durationMs,
                'cooldown_ready_timeline_ms' => $timeline + $cooldownMs,
            ] + $snapshot);
            $this->persistSynchronization(
                $encounterId,
                $synchronized,
                self::integer($synchronized, 'version'),
            );
            $state = $this->projector->project($character, $synchronized);
            $guard->commit();

            return $state;
        } catch (DomainException $exception) {
            if ($synchronizationPersisted) {
                try {
                    $guard->commit();
                } catch (Throwable $commitException) {
                    $guard->rollBack();
                    throw $commitException;
                }
            } else {
                $guard->rollBack();
            }
            throw $exception;
        } catch (Throwable $exception) {
            $guard->rollBack();
            throw $exception;
        }
    }

    public function attemptBlock(
        int $userId,
        int $characterId,
        int $enemyActionId,
        string $blockToken,
        string $requestToken,
    ): array {
        if ($enemyActionId <= 0) {
            throw new InvalidArgumentException('Invalid enemy combat action.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/D', $blockToken) !== 1) {
            throw new InvalidArgumentException('Invalid combat Block token.');
        }
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $requestToken) !== 1) {
            throw new InvalidArgumentException('Invalid combat request token.');
        }

        $guard = new CombatAccessGuard($this->repository);
        $synchronizationPersisted = false;
        try {
            $decision = $guard->beginAtomic(
                CombatAccessGuard::GAME_LOAD,
                $userId,
                $characterId,
            );
            $character = $decision['character'];
            $encounter = $decision['active_encounter'];
            if ($encounter === null) {
                throw new DomainException('No active combat encounter was found.');
            }

            $enemy = $this->definitions->enemy((string) ($encounter['enemy_key'] ?? ''));
            if ($enemy === null) {
                throw new RuntimeException('Stored combat enemy is unavailable.');
            }
            $blockDefinition = $this->definitions->playerReaction('basic_block');
            if ($blockDefinition === null) {
                throw new RuntimeException('Player Block reaction is unavailable.');
            }
            $blockDefinitionKey = (string) ($blockDefinition['key'] ?? '');
            $stats = CharacterStats::calculate($character);
            $synchronization = $this->synchronizer->synchronize(
                $encounter,
                $character,
                (int) $stats['rates']['action'],
                (int) $enemy['action'],
            );
            $synchronized = $synchronization['encounter'];
            $character = $synchronization['character'];
            $encounterId = self::integer($encounter, 'id');
            $this->persistSynchronization(
                $encounterId,
                $synchronized,
                self::integer($encounter, 'version'),
            );
            $synchronizationPersisted = true;
            if (($synchronized['status'] ?? null) !== 'active') {
                throw new DomainException('Combat is no longer active.');
            }

            $replay = $this->repository->lockActionByRequestToken(
                $encounterId,
                $requestToken,
            );
            if ($replay !== null) {
                if (
                    ($replay['actor'] ?? null) !== 'player' ||
                    ($replay['action_kind'] ?? null) !== 'block' ||
                    ($replay['definition_key'] ?? null) !== $blockDefinitionKey ||
                    self::integer($replay, 'parent_action_id') !== $enemyActionId ||
                    ($replay['state'] ?? null) !== 'resolved'
                ) {
                    throw new DomainException('Combat request token collides with another action.');
                }
                $state = $this->projector->project($character, $synchronized);
                $guard->commit();

                return $state;
            }

            $incomingAction = $this->repository->lockEnemyActionForBlock(
                $encounterId,
                $enemyActionId,
            );
            if (
                $incomingAction === null ||
                ($incomingAction['state'] ?? null) !== 'pending' ||
                ($incomingAction['block_attempted_timeline_ms'] ?? null) !== null
            ) {
                throw new DomainException('The Block opportunity is unavailable.');
            }
            $storedBlockToken = $incomingAction['block_token'] ?? null;
            if (!is_string($storedBlockToken) || !hash_equals($storedBlockToken, $blockToken)) {
                throw new DomainException('The Block opportunity is unavailable.');
            }
            $timeline = self::integer($synchronized, 'timeline_elapsed_ms');
            if ($timeline >= self::integer($incomingAction, 'block_expires_timeline_ms')) {
                throw new DomainException('The Block opportunity has expired.');
            }

            $command = $this->repository->createResolvedBlockCommand(
                $encounterId,
                $enemyActionId,
                $blockDefinitionKey,
                $requestToken,
                $timeline,
            );
            if (
                ($command['actor'] ?? null) !== 'player' ||
                ($command['action_kind'] ?? null) !== 'block' ||
                self::integer($command, 'parent_action_id') !== $enemyActionId
            ) {
                throw new RuntimeException('Block command persistence returned invalid state.');
            }
            if (!$this->repository->markLockedEnemyActionBlockAttempted(
                $encounterId,
                $enemyActionId,
                $blockToken,
                $timeline,
            )) {
                throw new RuntimeException('Block opportunity changed concurrently.');
            }

            $state = $this->projector->project($character, $synchronized);
            $guard->commit();

            return $state;
        } catch (DomainException $exception) {
            if ($synchronizationPersisted) {
                try {
                    $guard->commit();
                } catch (Throwable $commitException) {
                    $guard->rollBack();
                    throw $commitException;
                }
            } else {
                $guard->rollBack();
            }
            throw $exception;
        } catch (Throwable $exception) {
            $guard->rollBack();
            throw $exception;
        }
    }

    public function usePotion(
        int $userId,
        int $characterId,
        string $requestToken,
    ): array {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $requestToken) !== 1) {
            throw new InvalidArgumentException('Invalid combat request token.');
        }

        $guard = new CombatAccessGuard($this->repository);
        $synchronizationPersisted = false;
        try {
            $decision = $guard->beginAtomic(
                CombatAccessGuard::GAME_LOAD,
                $userId,
                $characterId,
            );
            $character = $decision['character'];
            $encounter = $decision['active_encounter'];
            if ($encounter === null) {
                throw new DomainException('No active combat encounter was found.');
            }

            $enemy = $this->definitions->enemy((string) ($encounter['enemy_key'] ?? ''));
            if ($enemy === null) {
                throw new RuntimeException('Stored combat enemy is unavailable.');
            }
            $stats = CharacterStats::calculate($character);
            $synchronization = $this->synchronizer->synchronize(
                $encounter,
                $character,
                (int) $stats['rates']['action'],
                (int) $enemy['action'],
            );
            $synchronized = $synchronization['encounter'];
            $character = $synchronization['character'];
            $encounterId = self::integer($encounter, 'id');
            $this->persistSynchronization(
                $encounterId,
                $synchronized,
                self::integer($encounter, 'version'),
            );
            $synchronizationPersisted = true;
            if (($synchronized['status'] ?? null) !== 'active') {
                throw new DomainException('Combat is no longer active.');
            }

            $potionKey = (string) ($synchronized['potion_key'] ?? '');
            $replay = $this->repository->lockActionByRequestToken(
                $encounterId,
                $requestToken,
            );
            if ($replay !== null) {
                if (
                    ($replay['actor'] ?? null) !== 'player' ||
                    ($replay['action_kind'] ?? null) !== 'potion' ||
                    ($replay['definition_key'] ?? null) !== $potionKey ||
                    ($replay['state'] ?? null) !== 'resolved'
                ) {
                    throw new DomainException('Combat request token collides with another action.');
                }
                $state = $this->projector->project($character, $synchronized);
                $guard->commit();

                return $state;
            }

            if (self::integer($character, 'current_hp') <= 0) {
                throw new DomainException('The Champion cannot use a Potion.');
            }
            $potion = $this->definitions->potion($potionKey);
            if ($potion === null || ($potion['key'] ?? null) !== $potionKey) {
                throw new DomainException('The combat Potion is unavailable.');
            }
            $chargesRemaining = self::integer($synchronized, 'potion_charges_remaining');
            if ($chargesRemaining <= 0) {
                throw new DomainException('No combat Potion charges remain.');
            }

            $stats = CharacterStats::calculate($character);
            $maximumLife = (int) $stats['resources']['max_life'];
            $currentHp = self::integer($character, 'current_hp');
            if ($currentHp >= $maximumLife) {
                throw new DomainException('The Champion is already at Maximum Life.');
            }
            $healingApplied = min(
                (int) $potion['prototype_healing'],
                $maximumLife - $currentHp,
            );
            $newCurrentHp = $currentHp + $healingApplied;
            if (!$this->repository->updateLockedCharacterCurrentHp(
                $userId,
                $characterId,
                $currentHp,
                $newCurrentHp,
            )) {
                throw new RuntimeException('Champion Life changed concurrently. Please retry.');
            }

            $expectedVersion = self::integer($synchronized, 'version');
            if (!$this->repository->consumeLockedEncounterPotionCharge(
                $encounterId,
                $chargesRemaining,
                $expectedVersion,
            )) {
                throw new RuntimeException('Combat Potion charges changed concurrently. Please retry.');
            }
            $synchronized['potion_charges_remaining'] = $chargesRemaining - 1;
            $synchronized['version'] = $expectedVersion + 1;
            $character['current_hp'] = $newCurrentHp;

            $timeline = self::integer($synchronized, 'timeline_elapsed_ms');
            $command = $this->repository->createResolvedPotionCommand(
                $encounterId,
                $potionKey,
                $requestToken,
                $timeline,
                $healingApplied,
            );
            if (
                ($command['actor'] ?? null) !== 'player' ||
                ($command['action_kind'] ?? null) !== 'potion' ||
                ($command['definition_key'] ?? null) !== $potionKey ||
                ($command['request_token'] ?? null) !== $requestToken ||
                ($command['state'] ?? null) !== 'resolved' ||
                self::integer($command, 'healing_applied') !== $healingApplied
            ) {
                throw new RuntimeException('Potion command persistence returned invalid state.');
            }

            $this->repository->appendEvent(
                $encounterId,
                'potion_used',
                sprintf(
                    'You recover %d HP with %s.',
                    $healingApplied,
                    (string) $potion['name'],
                ),
                null,
            );

            $state = $this->projector->project($character, $synchronized);
            $guard->commit();

            return $state;
        } catch (DomainException $exception) {
            if ($synchronizationPersisted) {
                try {
                    $guard->commit();
                } catch (Throwable $commitException) {
                    $guard->rollBack();
                    throw $commitException;
                }
            } else {
                $guard->rollBack();
            }
            throw $exception;
        } catch (Throwable $exception) {
            $guard->rollBack();
            throw $exception;
        }
    }

    private function persistSynchronization(int $encounterId, array &$encounter, int $expectedVersion): void
    {
        if (!$this->repository->updateLockedEncounterSynchronization($encounterId, $encounter, $expectedVersion)) {
            throw new RuntimeException('Combat state changed concurrently. Please retry.');
        }
        $encounter['version'] = $expectedVersion + 1;
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
