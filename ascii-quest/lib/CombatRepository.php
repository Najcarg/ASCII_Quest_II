<?php
declare(strict_types=1);

final class CombatRepository
{
    private ?int $lockedCharacterId = null;
    private bool $activeEncounterLockChecked = false;
    private ?int $lockedEncounterId = null;
    private bool $detailRowsTouched = false;

    public function __construct(private PDO $pdo)
    {
    }

    public function findOwnedCharacter(int $userId, int $characterId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT
                id,
                user_id,
                current_map_id,
                pos_x,
                pos_y,
                current_hp,
                current_mana,
                life_state
            FROM characters
            WHERE id = :character_id
              AND user_id = :user_id
            LIMIT 1');
        $stmt->execute([
            'character_id' => $characterId,
            'user_id' => $userId,
        ]);
        $character = $stmt->fetch();

        return is_array($character) ? $character : null;
    }

    public function beginTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Combat transaction is already active.');
        }

        $this->resetLockState();
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->requireTransaction();
        $this->pdo->commit();
        $this->resetLockState();
    }

    public function rollBack(): void
    {
        $this->requireTransaction();
        $this->pdo->rollBack();
        $this->resetLockState();
    }

    public function lockOwnedCharacter(int $userId, int $characterId): ?array
    {
        $this->requireTransaction();
        if ($this->activeEncounterLockChecked || $this->detailRowsTouched) {
            throw new LogicException('Champion must be locked before encounter, action, or event rows.');
        }
        if ($this->lockedCharacterId !== null && $this->lockedCharacterId !== $characterId) {
            throw new LogicException('A different Champion is already locked in this transaction.');
        }

        $stmt = $this->pdo->prepare('SELECT
                id,
                user_id,
                current_map_id,
                pos_x,
                pos_y,
                current_hp,
                current_mana,
                life_state
            FROM characters
            WHERE id = :character_id
              AND user_id = :user_id
            LIMIT 1
            FOR UPDATE');
        $stmt->execute([
            'character_id' => $characterId,
            'user_id' => $userId,
        ]);
        $character = $stmt->fetch();

        if (!is_array($character)) {
            return null;
        }

        $this->lockedCharacterId = $characterId;

        return $character;
    }

    public function lockActiveEncounter(int $characterId): ?array
    {
        $this->requireChampionLock($characterId);
        if ($this->detailRowsTouched) {
            throw new LogicException('Encounter must be locked before action or event rows.');
        }

        $stmt = $this->pdo->prepare('SELECT *
            FROM combat_encounters
            WHERE character_id = :character_id
              AND active_slot = 1
            LIMIT 1
            FOR UPDATE');
        $stmt->execute(['character_id' => $characterId]);
        $encounter = $stmt->fetch();

        $this->activeEncounterLockChecked = true;
        $this->lockedEncounterId = is_array($encounter) ? (int) $encounter['id'] : null;

        return is_array($encounter) ? $encounter : null;
    }

    public function createEncounter(int $characterId, array $encounter): array
    {
        $this->requireChampionLock($characterId);
        if (!$this->activeEncounterLockChecked || $this->detailRowsTouched) {
            throw new LogicException('Active encounter slot must be locked before encounter creation.');
        }
        if ($this->lockedEncounterId !== null) {
            $existing = $this->lockActiveEncounter($characterId);
            if ($existing !== null) {
                return $existing;
            }
        }

        $params = ['character_id' => $characterId] + $this->requireKeys($encounter, [
            'enemy_key',
            'status',
            'active_slot',
            'enemy_max_hp',
            'enemy_current_hp',
            'timeline_elapsed_ms',
            'last_synchronized_at',
            'turn_number',
            'turn_started_timeline_ms',
            'next_enemy_decision_timeline_ms',
            'player_actions_remaining',
            'enemy_actions_remaining',
            'potion_key',
            'potion_charge_allowance',
            'potion_charges_remaining',
            'reward_gold',
            'reward_experience',
            'version',
        ]);

        try {
            $stmt = $this->pdo->prepare('INSERT INTO combat_encounters (
                    character_id, enemy_key, status, active_slot,
                    enemy_max_hp, enemy_current_hp, timeline_elapsed_ms,
                    last_synchronized_at, turn_number, turn_started_timeline_ms,
                    next_enemy_decision_timeline_ms, player_actions_remaining,
                    enemy_actions_remaining, potion_key, potion_charge_allowance,
                    potion_charges_remaining, reward_gold, reward_experience, version
                ) VALUES (
                    :character_id, :enemy_key, :status, :active_slot,
                    :enemy_max_hp, :enemy_current_hp, :timeline_elapsed_ms,
                    :last_synchronized_at, :turn_number, :turn_started_timeline_ms,
                    :next_enemy_decision_timeline_ms, :player_actions_remaining,
                    :enemy_actions_remaining, :potion_key, :potion_charge_allowance,
                    :potion_charges_remaining, :reward_gold, :reward_experience, :version
                )');
            $stmt->execute($params);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            $existing = $this->lockActiveEncounter($characterId);
            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }

        $id = (int) $this->pdo->lastInsertId();
        $this->lockedEncounterId = $id;

        return ['id' => $id] + $params;
    }

    public function updateEncounterSynchronization(
        int $encounterId,
        int $timelineElapsedMs,
        string $lastSynchronizedAt,
        int $expectedVersion,
    ): bool {
        $this->requireEncounterLock($encounterId);

        $stmt = $this->pdo->prepare('UPDATE combat_encounters
            SET timeline_elapsed_ms = :timeline_elapsed_ms,
                last_synchronized_at = :last_synchronized_at,
                version = version + 1
            WHERE id = :encounter_id
              AND version = :expected_version');
        $stmt->execute([
            'timeline_elapsed_ms' => $timelineElapsedMs,
            'last_synchronized_at' => $lastSynchronizedAt,
            'encounter_id' => $encounterId,
            'expected_version' => $expectedVersion,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function createAction(int $encounterId, array $action): array
    {
        $this->requireEncounterLock($encounterId);
        $values = $this->requireKeys($action, [
            'actor',
            'action_kind',
            'definition_key',
            'request_token',
            'active_slot',
            'state',
            'started_timeline_ms',
            'resolves_timeline_ms',
            'cooldown_ready_timeline_ms',
        ]);

        if ($values['request_token'] !== null) {
            $existing = $this->lockActionByRequestToken($encounterId, (string) $values['request_token']);
            if ($existing !== null) {
                return $existing;
            }
        }

        $params = ['encounter_id' => $encounterId] + $values;
        try {
            $stmt = $this->pdo->prepare('INSERT INTO combat_actions (
                    encounter_id, actor, action_kind, definition_key, request_token,
                    active_slot, state, started_timeline_ms, resolves_timeline_ms,
                    cooldown_ready_timeline_ms
                ) VALUES (
                    :encounter_id, :actor, :action_kind, :definition_key, :request_token,
                    :active_slot, :state, :started_timeline_ms, :resolves_timeline_ms,
                    :cooldown_ready_timeline_ms
                )');
            $stmt->execute($params);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000' || $values['request_token'] === null) {
                throw $exception;
            }

            $existing = $this->lockActionByRequestToken($encounterId, (string) $values['request_token']);
            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }

        $id = (int) $this->pdo->lastInsertId();

        return ['id' => $id] + $params;
    }

    public function lockActionByRequestToken(int $encounterId, string $requestToken): ?array
    {
        $this->requireEncounterLock($encounterId);
        $this->detailRowsTouched = true;

        $stmt = $this->pdo->prepare('SELECT *
            FROM combat_actions
            WHERE encounter_id = :encounter_id
              AND request_token = :request_token
            LIMIT 1
            FOR UPDATE');
        $stmt->execute([
            'encounter_id' => $encounterId,
            'request_token' => $requestToken,
        ]);
        $action = $stmt->fetch();

        return is_array($action) ? $action : null;
    }

    public function appendEvent(
        int $encounterId,
        string $eventType,
        string $message,
        ?string $emphasis,
    ): array {
        $this->requireEncounterLock($encounterId);
        $this->detailRowsTouched = true;

        $stmt = $this->pdo->prepare('SELECT sequence_number
            FROM combat_events
            WHERE encounter_id = :encounter_id
            ORDER BY sequence_number DESC
            LIMIT 1
            FOR UPDATE');
        $stmt->execute(['encounter_id' => $encounterId]);
        $latest = $stmt->fetch();
        $sequence = is_array($latest) ? ((int) $latest['sequence_number'] + 1) : 1;

        $params = [
            'encounter_id' => $encounterId,
            'sequence_number' => $sequence,
            'event_type' => $eventType,
            'message' => $message,
            'emphasis' => $emphasis,
        ];
        $stmt = $this->pdo->prepare('INSERT INTO combat_events (
                encounter_id, sequence_number, event_type, message, emphasis
            ) VALUES (
                :encounter_id, :sequence_number, :event_type, :message, :emphasis
            )');
        $stmt->execute($params);
        $id = (int) $this->pdo->lastInsertId();

        return ['id' => $id] + $params;
    }

    public function eventsForEncounter(int $encounterId): array
    {
        $stmt = $this->pdo->prepare('SELECT
                id,
                encounter_id,
                sequence_number,
                event_type,
                message,
                emphasis,
                created_at
            FROM combat_events
            WHERE encounter_id = :encounter_id
            ORDER BY sequence_number ASC');
        $stmt->execute(['encounter_id' => $encounterId]);

        return $stmt->fetchAll();
    }

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('An active combat transaction is required.');
        }
    }

    private function requireChampionLock(int $characterId): void
    {
        $this->requireTransaction();
        if ($this->lockedCharacterId !== $characterId) {
            throw new LogicException('The selected owned Champion must be locked first.');
        }
    }

    private function requireEncounterLock(int $encounterId): void
    {
        $this->requireTransaction();
        if (!$this->activeEncounterLockChecked || $this->lockedEncounterId !== $encounterId) {
            throw new LogicException('The active encounter must be locked after its Champion.');
        }
    }

    private function requireKeys(array $values, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $values)) {
                throw new InvalidArgumentException('Missing repository value: ' . $key);
            }
            $result[$key] = $values[$key];
        }

        return $result;
    }

    private function resetLockState(): void
    {
        $this->lockedCharacterId = null;
        $this->activeEncounterLockChecked = false;
        $this->lockedEncounterId = null;
        $this->detailRowsTouched = false;
    }
}
