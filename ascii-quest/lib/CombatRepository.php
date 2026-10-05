<?php
declare(strict_types=1);

require_once __DIR__ . '/CombatDeathResult.php';

final class CombatRepository
{
    private ?int $lockedCharacterId = null;
    private ?int $lockedUserId = null;
    private bool $activeEncounterLockChecked = false;
    private ?int $lockedEncounterId = null;
    private ?array $lockedEncounter = null;
    private bool $detailRowsTouched = false;
    private bool $actionRowsLocked = false;
    private ?int $lockedBlockActionId = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function findOwnedCharacter(int $userId, int $characterId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT
                c.*,
                gm.map_key AS current_map_key,
                gm.map_file AS current_map_file
            FROM characters c
            INNER JOIN game_maps gm ON gm.id = c.current_map_id
            WHERE c.id = :character_id
              AND c.user_id = :user_id
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
                c.*,
                gm.map_key AS current_map_key,
                gm.map_file AS current_map_file
            FROM characters c
            INNER JOIN game_maps gm ON gm.id = c.current_map_id
            WHERE c.id = :character_id
              AND c.user_id = :user_id
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
        $this->lockedUserId = $userId;

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
        $this->lockedEncounter = is_array($encounter) ? $encounter : null;
        $this->lockedEncounterId = $this->lockedEncounter !== null
            ? (int) $this->lockedEncounter['id']
            : null;

        return is_array($encounter) ? $encounter : null;
    }

    public function lockOwnedAccountActiveEncounter(int $userId, int $characterId): ?array
    {
        $this->requireChampionLock($characterId);
        if ($this->lockedUserId !== $userId) {
            throw new LogicException('The locked Champion does not belong to this account.');
        }
        if ($this->detailRowsTouched) {
            throw new LogicException('Account encounter must be locked before action or event rows.');
        }

        $account = $this->pdo->prepare('SELECT id
            FROM users
            WHERE id = :user_id
            LIMIT 1
            FOR UPDATE');
        $account->execute(['user_id' => $userId]);
        if (!is_array($account->fetch())) {
            throw new OutOfBoundsException('Account not found.');
        }

        $lookup = $this->pdo->prepare('SELECT ce.id
            FROM combat_encounters ce
            INNER JOIN characters c ON c.id = ce.character_id
            WHERE c.user_id = :user_id
              AND ce.active_slot = 1
            ORDER BY ce.id
            LIMIT 1');
        $lookup->execute(['user_id' => $userId]);
        $found = $lookup->fetch();

        $encounter = null;
        if (is_array($found)) {
            $stmt = $this->pdo->prepare('SELECT *
                FROM combat_encounters
                WHERE id = :encounter_id
                  AND active_slot = 1
                LIMIT 1
                FOR UPDATE');
            $stmt->execute(['encounter_id' => (int) $found['id']]);
            $locked = $stmt->fetch();
            $encounter = is_array($locked) ? $locked : null;
        }

        $this->activeEncounterLockChecked = true;
        $this->lockedEncounter = is_array($encounter) ? $encounter : null;
        $this->lockedEncounterId = $this->lockedEncounter !== null
            ? (int) $this->lockedEncounter['id']
            : null;

        return $this->lockedEncounter;
    }

    public function lockedActiveEncounter(int $characterId): ?array
    {
        $this->requireChampionLock($characterId);
        if (!$this->activeEncounterLockChecked) {
            throw new LogicException('The active encounter slot must be locked second.');
        }

        if (
            $this->lockedEncounter !== null &&
            (int) $this->lockedEncounter['character_id'] !== $characterId
        ) {
            throw new LogicException('Another Champion owns the locked active encounter.');
        }

        return $this->lockedEncounter;
    }

    public function lockLatestClosedVictoryEncounter(int $characterId): ?array
    {
        $this->requireChampionLock($characterId);
        if (!$this->activeEncounterLockChecked) {
            throw new LogicException('The active encounter slot must be locked before closed history.');
        }
        if ($this->lockedEncounter !== null) {
            throw new LogicException('Closed history cannot be locked while an active encounter is locked.');
        }
        if ($this->detailRowsTouched) {
            throw new LogicException('Encounter must be locked before action or event rows.');
        }

        $stmt = $this->pdo->prepare("SELECT *
            FROM combat_encounters
            WHERE character_id = :character_id
              AND status = 'closed'
              AND active_slot IS NULL
              AND rewards_issued_at IS NOT NULL
              AND completed_at IS NOT NULL
            ORDER BY completed_at DESC, id DESC
            LIMIT 1
            FOR UPDATE");
        $stmt->execute(['character_id' => $characterId]);
        $encounter = $stmt->fetch();

        $this->lockedEncounter = is_array($encounter) ? $encounter : null;
        $this->lockedEncounterId = $this->lockedEncounter !== null
            ? (int) $this->lockedEncounter['id']
            : null;

        return $this->lockedEncounter;
    }

    public function findActiveEncounter(int $characterId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT *
            FROM combat_encounters
            WHERE character_id = :character_id
              AND active_slot = 1
            LIMIT 1');
        $stmt->execute(['character_id' => $characterId]);
        $encounter = $stmt->fetch();

        return is_array($encounter) ? $encounter : null;
    }

    public function findOwnedActiveEncounterForUser(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ce.*
            FROM combat_encounters ce
            INNER JOIN characters c ON c.id = ce.character_id
            WHERE c.user_id = :user_id
              AND ce.active_slot = 1
            ORDER BY ce.id
            LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $encounter = $stmt->fetch();

        return is_array($encounter) ? $encounter : null;
    }

    public function findLatestDefeatedEncounter(int $characterId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT *
            FROM combat_encounters
            WHERE character_id = :character_id
              AND status = 'defeated'
              AND active_slot IS NULL
              AND death_processed_at IS NOT NULL
              AND killer_enemy_key IS NOT NULL
            ORDER BY death_processed_at DESC, id DESC
            LIMIT 1");
        $stmt->execute(['character_id' => $characterId]);
        $encounter = $stmt->fetch();

        return is_array($encounter) ? $encounter : null;
    }

    public function lockLatestDefeatedEncounter(int $characterId): ?array
    {
        $this->requireChampionLock($characterId);
        if (!$this->activeEncounterLockChecked || $this->lockedEncounter !== null) {
            throw new LogicException('Account encounter slot must be locked before defeat history.');
        }
        if ($this->detailRowsTouched) {
            throw new LogicException('Defeat history must be locked before action or event rows.');
        }

        $stmt = $this->pdo->prepare("SELECT *
            FROM combat_encounters
            WHERE character_id = :character_id
              AND status = 'defeated'
              AND active_slot IS NULL
              AND death_processed_at IS NOT NULL
              AND killer_enemy_key IS NOT NULL
            ORDER BY death_processed_at DESC, id DESC
            LIMIT 1
            FOR UPDATE");
        $stmt->execute(['character_id' => $characterId]);
        $encounter = $stmt->fetch();

        $this->lockedEncounter = is_array($encounter) ? $encounter : null;
        $this->lockedEncounterId = $this->lockedEncounter !== null
            ? (int) $this->lockedEncounter['id']
            : null;

        return $this->lockedEncounter;
    }

    public function findDeathResultForOwnedCharacter(
        int $userId,
        int $characterId,
    ): ?CombatDeathResult {
        $character = $this->findOwnedCharacter($userId, $characterId);
        if (
            $character === null ||
            ($character['life_state'] ?? null) !== 'dead' ||
            !is_string($character['died_at'] ?? null)
        ) {
            return null;
        }
        $encounter = $this->findLatestDefeatedEncounter($characterId);
        if ($encounter === null) {
            return null;
        }

        return new CombatDeathResult(
            $characterId,
            (int) $encounter['id'],
            (string) $encounter['killer_enemy_key'],
            (string) $character['died_at'],
        );
    }

    public function updateLockedCharacterPosition(
        int $userId,
        int $characterId,
        int $mapId,
        int $currentX,
        int $currentY,
        int $newX,
        int $newY,
    ): bool {
        $this->requireChampionLock($characterId);
        if (!$this->activeEncounterLockChecked) {
            throw new LogicException('The active encounter slot must be locked before movement.');
        }

        $stmt = $this->pdo->prepare('UPDATE characters
            SET pos_x = :pos_x,
                pos_y = :pos_y
            WHERE id = :character_id
              AND user_id = :user_id
              AND current_map_id = :current_map_id
              AND pos_x = :current_pos_x
              AND pos_y = :current_pos_y');
        $stmt->execute([
            'pos_x' => $newX,
            'pos_y' => $newY,
            'character_id' => $characterId,
            'user_id' => $userId,
            'current_map_id' => $mapId,
            'current_pos_x' => $currentX,
            'current_pos_y' => $currentY,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function updateLockedCharacterCurrentHp(
        int $userId,
        int $characterId,
        int $expectedCurrentHp,
        int $newCurrentHp,
    ): bool {
        $this->requireChampionLock($characterId);
        if ($newCurrentHp < 0) {
            throw new InvalidArgumentException('Champion current HP cannot be negative.');
        }

        $stmt = $this->pdo->prepare('UPDATE characters
            SET current_hp = :new_current_hp
            WHERE id = :character_id
              AND user_id = :user_id
              AND current_hp = :expected_current_hp');
        $stmt->execute([
            'new_current_hp' => $newCurrentHp,
            'character_id' => $characterId,
            'user_id' => $userId,
            'expected_current_hp' => $expectedCurrentHp,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function markLockedCharacterDead(
        int $userId,
        int $characterId,
        string $diedAt,
    ): bool {
        $this->requireChampionLock($characterId);

        $stmt = $this->pdo->prepare("UPDATE characters
            SET life_state = 'dead',
                died_at = :died_at
            WHERE id = :character_id
              AND user_id = :user_id
              AND current_hp = 0
              AND life_state = 'alive'
              AND died_at IS NULL");
        $stmt->execute([
            'died_at' => $diedAt,
            'character_id' => $characterId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function addLockedCharacterVictoryRewards(
        int $userId,
        int $characterId,
        int $gold,
        int $experience,
    ): bool {
        $this->requireChampionLock($characterId);
        if ($gold < 0 || $experience < 0) {
            throw new InvalidArgumentException('Combat rewards cannot be negative.');
        }

        $stmt = $this->pdo->prepare('UPDATE characters
            SET gold = gold + :gold,
                experience = experience + :experience
            WHERE id = :character_id
              AND user_id = :user_id');
        $stmt->execute([
            'gold' => $gold,
            'experience' => $experience,
            'character_id' => $characterId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function createEncounter(int $characterId, array $encounter): array
    {
        $this->requireChampionLock($characterId);
        if (!$this->activeEncounterLockChecked || $this->detailRowsTouched) {
            throw new LogicException('Active encounter slot must be locked before encounter creation.');
        }
        if ($this->lockedEncounterId !== null) {
            if ((int) $this->lockedEncounter['character_id'] !== $characterId) {
                throw new DomainException('Another Champion is already in combat.');
            }
            $existing = $this->lockActiveEncounter($characterId);
            if ($existing !== null) {
                return $existing;
            }
        }

        $encounter += ['loot_source_level' => null];
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
            'enemy_ai_initialized_timeline_ms',
            'player_actions_remaining',
            'enemy_actions_remaining',
            'potion_key',
            'potion_charge_allowance',
            'potion_charges_remaining',
            'reward_gold',
            'reward_experience',
            'loot_source_level',
            'version',
        ]);

        try {
            $stmt = $this->pdo->prepare('INSERT INTO combat_encounters (
                    character_id, enemy_key, status, active_slot,
                    enemy_max_hp, enemy_current_hp, timeline_elapsed_ms,
                    last_synchronized_at, turn_number, turn_started_timeline_ms,
                    next_enemy_decision_timeline_ms, enemy_ai_initialized_timeline_ms,
                    player_actions_remaining,
                    enemy_actions_remaining, potion_key, potion_charge_allowance,
                    potion_charges_remaining, reward_gold, reward_experience, loot_source_level, version
                ) VALUES (
                    :character_id, :enemy_key, :status, :active_slot,
                    :enemy_max_hp, :enemy_current_hp, :timeline_elapsed_ms,
                    :last_synchronized_at, :turn_number, :turn_started_timeline_ms,
                    :next_enemy_decision_timeline_ms, :enemy_ai_initialized_timeline_ms,
                    :player_actions_remaining,
                    :enemy_actions_remaining, :potion_key, :potion_charge_allowance,
                    :potion_charges_remaining, :reward_gold, :reward_experience, :loot_source_level, :version
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
        $this->lockedEncounter = ['id' => $id] + $params;

        return $this->lockedEncounter;
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

    public function updateLockedEncounterSynchronization(
        int $encounterId,
        array $encounter,
        int $expectedVersion,
    ): bool {
        $this->requireEncounterLock($encounterId);
        $values = $this->requireKeys($encounter, [
            'timeline_elapsed_ms',
            'last_synchronized_at',
            'turn_number',
            'turn_started_timeline_ms',
            'player_actions_remaining',
            'enemy_actions_remaining',
            'enemy_current_hp',
            'next_enemy_decision_timeline_ms',
            'enemy_ai_initialized_timeline_ms',
        ]);

        $stmt = $this->pdo->prepare('UPDATE combat_encounters
            SET timeline_elapsed_ms = :timeline_elapsed_ms,
                last_synchronized_at = :last_synchronized_at,
                turn_number = :turn_number,
                turn_started_timeline_ms = :turn_started_timeline_ms,
                player_actions_remaining = :player_actions_remaining,
                enemy_actions_remaining = :enemy_actions_remaining,
                enemy_current_hp = :enemy_current_hp,
                next_enemy_decision_timeline_ms = :next_enemy_decision_timeline_ms,
                enemy_ai_initialized_timeline_ms = :enemy_ai_initialized_timeline_ms,
                version = version + 1
            WHERE id = :encounter_id
              AND version = :expected_version');
        $stmt->execute($values + [
            'encounter_id' => $encounterId,
            'expected_version' => $expectedVersion,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function transitionLockedEncounterToVictory(
        int $encounterId,
        string $rewardsIssuedAt,
    ): bool {
        $this->requireEncounterLock($encounterId);

        $stmt = $this->pdo->prepare("UPDATE combat_encounters
            SET status = 'victory_loot',
                enemy_current_hp = 0,
                rewards_issued_at = :rewards_issued_at
            WHERE id = :encounter_id
              AND status = 'active'
              AND active_slot = 1
              AND rewards_issued_at IS NULL");
        $stmt->execute([
            'rewards_issued_at' => $rewardsIssuedAt,
            'encounter_id' => $encounterId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function itemGenerationCatalogue(): array
    {
        $definitions = $this->pdo->query("SELECT * FROM item_definitions WHERE is_active = 1 AND loot_weight > 0 ORDER BY definition_key")->fetchAll(PDO::FETCH_ASSOC);
        $families = $this->pdo->query('SELECT definition_key, family_key FROM item_definition_affix_families ORDER BY definition_key, family_key')->fetchAll(PDO::FETCH_ASSOC);
        $familyMap = [];
        foreach ($families as $row) {
            $familyMap[(string) $row['definition_key']][] = (string) $row['family_key'];
        }
        foreach ($definitions as &$definition) {
            $definition['allowed_affix_families'] = $familyMap[(string) $definition['definition_key']] ?? [];
        }
        unset($definition);

        $affixes = $this->pdo->query('SELECT * FROM item_affix_definitions WHERE is_active = 1 ORDER BY affix_key')->fetchAll(PDO::FETCH_ASSOC);
        $categories = $this->pdo->query('SELECT affix_key, category FROM item_affix_category_rules ORDER BY affix_key, category')->fetchAll(PDO::FETCH_ASSOC);
        $tiers = $this->pdo->query('SELECT affix_key, tier, minimum_item_level, minimum_value, maximum_value FROM item_affix_tiers ORDER BY affix_key, tier')->fetchAll(PDO::FETCH_ASSOC);
        $categoryMap = [];
        foreach ($categories as $row) {
            $categoryMap[(string) $row['affix_key']][] = (string) $row['category'];
        }
        $tierMap = [];
        foreach ($tiers as $row) {
            $tierMap[(string) $row['affix_key']][] = $row;
        }
        foreach ($affixes as &$affix) {
            $key = (string) $affix['affix_key'];
            $affix['categories'] = $categoryMap[$key] ?? [];
            $affix['tiers'] = $tierMap[$key] ?? [];
        }
        unset($affix);

        return ['definitions' => $definitions, 'affixes' => $affixes];
    }

    public function createNoItemDrop(
        int $encounterId,
        int $rewardSlot,
        int $baseChanceBp,
        int $itemFindBonusBp,
        int $effectiveChanceBp,
        int $chanceRollBp,
        string $generatedAt,
    ): void {
        $this->requireEncounterLock($encounterId);
        $stmt = $this->pdo->prepare("INSERT INTO combat_item_drops
            (encounter_id, reward_slot, outcome, item_id, base_chance_bp, item_find_bonus_bp, effective_chance_bp, chance_roll_bp, generated_at)
            VALUES (:encounter_id, :reward_slot, 'none', NULL, :base_chance_bp, :item_find_bonus_bp, :effective_chance_bp, :chance_roll_bp, :generated_at)");
        $stmt->execute([
            'encounter_id' => $encounterId, 'reward_slot' => $rewardSlot,
            'base_chance_bp' => $baseChanceBp, 'item_find_bonus_bp' => $itemFindBonusBp,
            'effective_chance_bp' => $effectiveChanceBp, 'chance_roll_bp' => $chanceRollBp,
            'generated_at' => $generatedAt,
        ]);
    }

    public function createGeneratedItemDrop(
        int $encounterId,
        int $rewardSlot,
        array $item,
        string $sourceKey,
        int $baseChanceBp,
        int $itemFindBonusBp,
        int $effectiveChanceBp,
        int $chanceRollBp,
        string $generatedAt,
    ): int {
        $this->requireEncounterLock($encounterId);
        $columns = [
            'definition_key', 'item_level', 'rarity', 'display_name',
            'snapshot_category', 'snapshot_subtype', 'snapshot_equipment_slot',
            'snapshot_damage_type', 'snapshot_damage_min', 'snapshot_damage_max',
            'snapshot_toughness', 'snapshot_attack_rate_modifier_bp',
            'snapshot_cast_rate_modifier_bp', 'snapshot_block_rate_modifier_bp', 'glyph',
        ];
        $params = [];
        foreach ($columns as $column) {
            if (!array_key_exists($column, $item)) {
                throw new InvalidArgumentException('Missing generated item field: ' . $column);
            }
            $params[$column] = $item[$column];
        }
        $params += ['source_type' => 'combat_drop', 'source_key' => $sourceKey, 'generated_at' => $generatedAt];
        $stmt = $this->pdo->prepare('INSERT INTO character_items
            (character_id, definition_key, item_level, rarity, display_name,
             snapshot_category, snapshot_subtype, snapshot_equipment_slot,
             snapshot_damage_type, snapshot_damage_min, snapshot_damage_max,
             snapshot_toughness, snapshot_attack_rate_modifier_bp,
             snapshot_cast_rate_modifier_bp, snapshot_block_rate_modifier_bp,
             glyph, source_type, source_key, generated_at, claimed_at)
            VALUES (NULL, :definition_key, :item_level, :rarity, :display_name,
             :snapshot_category, :snapshot_subtype, :snapshot_equipment_slot,
             :snapshot_damage_type, :snapshot_damage_min, :snapshot_damage_max,
             :snapshot_toughness, :snapshot_attack_rate_modifier_bp,
             :snapshot_cast_rate_modifier_bp, :snapshot_block_rate_modifier_bp,
             :glyph, :source_type, :source_key, :generated_at, NULL)');
        $stmt->execute($params);
        $itemId = (int) $this->pdo->lastInsertId();

        $affixStmt = $this->pdo->prepare('INSERT INTO character_item_affixes
            (item_id, position, affix_key, tier, family_key, display_fragment, modifier_type, modifier_operation, rolled_value)
            VALUES (:item_id, :position, :affix_key, :tier, :family_key, :display_fragment, :modifier_type, :modifier_operation, :rolled_value)');
        foreach ($item['affixes'] ?? [] as $affix) {
            $affixStmt->execute(['item_id' => $itemId] + $affix);
        }

        $drop = $this->pdo->prepare("INSERT INTO combat_item_drops
            (encounter_id, reward_slot, outcome, item_id, base_chance_bp, item_find_bonus_bp, effective_chance_bp, chance_roll_bp, generated_at)
            VALUES (:encounter_id, :reward_slot, 'unclaimed', :item_id, :base_chance_bp, :item_find_bonus_bp, :effective_chance_bp, :chance_roll_bp, :generated_at)");
        $drop->execute([
            'encounter_id' => $encounterId, 'reward_slot' => $rewardSlot,
            'item_id' => $itemId, 'base_chance_bp' => $baseChanceBp,
            'item_find_bonus_bp' => $itemFindBonusBp,
            'effective_chance_bp' => $effectiveChanceBp, 'chance_roll_bp' => $chanceRollBp,
            'generated_at' => $generatedAt,
        ]);
        return $itemId;
    }

    public function markItemDropsGenerated(int $encounterId, string $generatedAt): bool
    {
        $this->requireEncounterLock($encounterId);
        $stmt = $this->pdo->prepare('UPDATE combat_encounters SET item_drops_generated_at = :generated_at WHERE id = :encounter_id AND item_drops_generated_at IS NULL');
        $stmt->execute(['generated_at' => $generatedAt, 'encounter_id' => $encounterId]);
        return $stmt->rowCount() === 1;
    }

    public function lockUnclaimedItemDrops(int $encounterId): array
    {
        $this->requireEncounterLock($encounterId);
        $stmt = $this->pdo->prepare("SELECT id, item_id FROM combat_item_drops WHERE encounter_id = :encounter_id AND outcome = 'unclaimed' ORDER BY reward_slot FOR UPDATE");
        $stmt->execute(['encounter_id' => $encounterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function claimLockedItemDrop(int $dropId, int $itemId, int $characterId, string $claimedAt): bool
    {
        $this->requireChampionLock($characterId);
        $item = $this->pdo->prepare('UPDATE character_items SET character_id = :character_id, claimed_at = :claimed_at WHERE id = :item_id AND character_id IS NULL AND claimed_at IS NULL');
        $item->execute(['character_id' => $characterId, 'claimed_at' => $claimedAt, 'item_id' => $itemId]);
        if ($item->rowCount() !== 1) {
            return false;
        }
        $drop = $this->pdo->prepare("UPDATE combat_item_drops SET outcome = 'claimed', claimed_character_id = :character_id, claimed_at = :claimed_at WHERE id = :drop_id AND item_id = :item_id AND outcome = 'unclaimed' AND claimed_character_id IS NULL AND claimed_at IS NULL");
        $drop->execute(['character_id' => $characterId, 'claimed_at' => $claimedAt, 'drop_id' => $dropId, 'item_id' => $itemId]);
        return $drop->rowCount() === 1;
    }

    public function dropsForEncounter(int $encounterId): array
    {
        $stmt = $this->pdo->prepare('SELECT d.id AS drop_id, d.outcome AS claim_state,
            ci.id, ci.item_level, ci.rarity, ci.display_name, ci.snapshot_category,
            ci.snapshot_subtype, ci.snapshot_equipment_slot, ci.snapshot_damage_type,
            ci.snapshot_damage_min, ci.snapshot_damage_max, ci.snapshot_toughness,
            ci.snapshot_attack_rate_modifier_bp, ci.snapshot_cast_rate_modifier_bp,
            ci.snapshot_block_rate_modifier_bp, ci.glyph
            FROM combat_item_drops d LEFT JOIN character_items ci ON ci.id = d.item_id
            WHERE d.encounter_id = :encounter_id ORDER BY d.reward_slot');
        $stmt->execute(['encounter_id' => $encounterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function affixesForItems(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->pdo->prepare("SELECT item_id, position, affix_key, tier, display_fragment, modifier_type, modifier_operation, rolled_value FROM character_item_affixes WHERE item_id IN ({$placeholders}) ORDER BY item_id, FIELD(position, 'prefix', 'suffix')");
        $stmt->execute(array_values($itemIds));
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['item_id']][] = $row;
        }
        return $result;
    }

    public function transitionLockedEncounterToDefeat(
        int $encounterId,
        string $killerEnemyKey,
        string $deathProcessedAt,
    ): bool {
        $this->requireEncounterLock($encounterId);

        $stmt = $this->pdo->prepare("UPDATE combat_encounters
            SET status = 'defeated',
                active_slot = NULL,
                death_processed_at = :death_processed_at,
                killer_enemy_key = :killer_enemy_key
            WHERE id = :encounter_id
              AND status = 'active'
              AND active_slot = 1
              AND death_processed_at IS NULL
              AND killer_enemy_key IS NULL");
        $stmt->execute([
            'death_processed_at' => $deathProcessedAt,
            'killer_enemy_key' => $killerEnemyKey,
            'encounter_id' => $encounterId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function closeLockedVictoryEncounter(
        int $encounterId,
        string $completedAt,
        int $expectedVersion,
    ): bool {
        $this->requireEncounterLock($encounterId);

        $stmt = $this->pdo->prepare("UPDATE combat_encounters
            SET status = 'closed',
                active_slot = NULL,
                completed_at = :completed_at,
                version = version + 1
            WHERE id = :encounter_id
              AND status = 'victory_loot'
              AND active_slot = 1
              AND version = :expected_version");
        $stmt->execute([
            'completed_at' => $completedAt,
            'encounter_id' => $encounterId,
            'expected_version' => $expectedVersion,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function consumeLockedEncounterPotionCharge(
        int $encounterId,
        int $expectedRemaining,
        int $expectedVersion,
    ): bool {
        $this->requireEncounterLock($encounterId);
        if ($expectedRemaining <= 0) {
            throw new InvalidArgumentException('Potion charges remaining must be positive.');
        }

        $stmt = $this->pdo->prepare('UPDATE combat_encounters
            SET potion_charges_remaining = potion_charges_remaining - 1,
                version = version + 1
            WHERE id = :encounter_id
              AND version = :expected_version
              AND potion_charges_remaining = :expected_remaining
              AND potion_charges_remaining > 0');
        $stmt->execute([
            'encounter_id' => $encounterId,
            'expected_version' => $expectedVersion,
            'expected_remaining' => $expectedRemaining,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function createResolvedPotionCommand(
        int $encounterId,
        string $definitionKey,
        string $requestToken,
        int $timelineMs,
        int $healingApplied,
    ): array {
        if ($timelineMs < 0) {
            throw new InvalidArgumentException('Potion command timeline cannot be negative.');
        }
        if ($healingApplied <= 0) {
            throw new InvalidArgumentException('Potion command healing must be positive.');
        }

        return $this->createAction($encounterId, [
            'actor' => 'player',
            'action_kind' => 'potion',
            'definition_key' => $definitionKey,
            'request_token' => $requestToken,
            'active_slot' => null,
            'state' => 'resolved',
            'started_timeline_ms' => $timelineMs,
            'resolves_timeline_ms' => $timelineMs,
            'cooldown_ready_timeline_ms' => null,
            'completed_timeline_ms' => $timelineMs,
            'snapshot_weapon_key' => null,
            'snapshot_damage_type' => null,
            'snapshot_base_damage' => null,
            'snapshot_accuracy' => null,
            'snapshot_critical_chance' => null,
            'snapshot_critical_damage' => null,
            'healing_applied' => $healingApplied,
        ]);
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
            'snapshot_weapon_key',
            'snapshot_damage_type',
            'snapshot_base_damage',
            'snapshot_accuracy',
            'snapshot_critical_chance',
            'snapshot_critical_damage',
        ]);
        $values += [
            'parent_action_id' => $action['parent_action_id'] ?? null,
            'completed_timeline_ms' => $action['completed_timeline_ms'] ?? null,
            'block_token' => $action['block_token'] ?? null,
            'block_expires_timeline_ms' => $action['block_expires_timeline_ms'] ?? null,
            'block_attempted_timeline_ms' => $action['block_attempted_timeline_ms'] ?? null,
            'block_prompt_x' => $action['block_prompt_x'] ?? null,
            'block_prompt_y' => $action['block_prompt_y'] ?? null,
            'resolved_damage' => $action['resolved_damage'] ?? null,
            'prevented_damage' => $action['prevented_damage'] ?? null,
            'healing_applied' => $action['healing_applied'] ?? null,
        ];

        if ($values['request_token'] !== null) {
            $existing = $this->lockActionByRequestToken($encounterId, (string) $values['request_token']);
            if ($existing !== null) {
                return $existing;
            }
        }

        $params = ['encounter_id' => $encounterId] + $values;
        try {
            $stmt = $this->pdo->prepare('INSERT INTO combat_actions (
                    encounter_id, parent_action_id, actor, action_kind,
                    definition_key, request_token,
                    active_slot, state, started_timeline_ms, resolves_timeline_ms,
                    cooldown_ready_timeline_ms, completed_timeline_ms,
                    snapshot_weapon_key,
                    snapshot_damage_type, snapshot_base_damage, snapshot_accuracy,
                    snapshot_critical_chance, snapshot_critical_damage,
                    block_token, block_expires_timeline_ms,
                    block_attempted_timeline_ms, block_prompt_x, block_prompt_y,
                    resolved_damage, prevented_damage, healing_applied
                ) VALUES (
                    :encounter_id, :parent_action_id, :actor, :action_kind,
                    :definition_key, :request_token,
                    :active_slot, :state, :started_timeline_ms, :resolves_timeline_ms,
                    :cooldown_ready_timeline_ms, :completed_timeline_ms,
                    :snapshot_weapon_key,
                    :snapshot_damage_type, :snapshot_base_damage, :snapshot_accuracy,
                    :snapshot_critical_chance, :snapshot_critical_damage,
                    :block_token, :block_expires_timeline_ms,
                    :block_attempted_timeline_ms, :block_prompt_x, :block_prompt_y,
                    :resolved_damage, :prevented_damage, :healing_applied
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

    public function lockEnemyActionForBlock(int $encounterId, int $actionId): ?array
    {
        $this->requireEncounterLock($encounterId);
        $this->detailRowsTouched = true;

        $stmt = $this->pdo->prepare("SELECT *
            FROM combat_actions
            WHERE id = :action_id
              AND encounter_id = :encounter_id
              AND actor = 'enemy'
            LIMIT 1
            FOR UPDATE");
        $stmt->execute([
            'action_id' => $actionId,
            'encounter_id' => $encounterId,
        ]);
        $action = $stmt->fetch();
        $this->lockedBlockActionId = is_array($action) ? $actionId : null;

        return is_array($action) ? $action : null;
    }

    public function createResolvedBlockCommand(
        int $encounterId,
        int $parentActionId,
        string $definitionKey,
        string $requestToken,
        int $attemptedTimelineMs,
    ): array {
        $this->requireBlockActionLock($encounterId, $parentActionId);
        if ($attemptedTimelineMs < 0) {
            throw new InvalidArgumentException('Block attempt timeline cannot be negative.');
        }

        return $this->createAction($encounterId, [
            'parent_action_id' => $parentActionId,
            'actor' => 'player',
            'action_kind' => 'block',
            'definition_key' => $definitionKey,
            'request_token' => $requestToken,
            'active_slot' => null,
            'state' => 'resolved',
            'started_timeline_ms' => $attemptedTimelineMs,
            'resolves_timeline_ms' => $attemptedTimelineMs,
            'cooldown_ready_timeline_ms' => null,
            'completed_timeline_ms' => $attemptedTimelineMs,
            'snapshot_weapon_key' => null,
            'snapshot_damage_type' => null,
            'snapshot_base_damage' => null,
            'snapshot_accuracy' => null,
            'snapshot_critical_chance' => null,
            'snapshot_critical_damage' => null,
        ]);
    }

    public function markLockedEnemyActionBlockAttempted(
        int $encounterId,
        int $actionId,
        string $blockToken,
        int $attemptedTimelineMs,
    ): bool {
        $this->requireBlockActionLock($encounterId, $actionId);
        if ($attemptedTimelineMs < 0) {
            throw new InvalidArgumentException('Block attempt timeline cannot be negative.');
        }

        $stmt = $this->pdo->prepare("UPDATE combat_actions
            SET block_attempted_timeline_ms = :attempted_timeline_ms
            WHERE id = :action_id
              AND encounter_id = :encounter_id
              AND actor = 'enemy'
              AND state = 'pending'
              AND block_token = :block_token
              AND block_attempted_timeline_ms IS NULL
              AND :expiry_timeline_ms < block_expires_timeline_ms");
        $stmt->execute([
            'attempted_timeline_ms' => $attemptedTimelineMs,
            'expiry_timeline_ms' => $attemptedTimelineMs,
            'action_id' => $actionId,
            'encounter_id' => $encounterId,
            'block_token' => $blockToken,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function lockActionsForEncounter(int $encounterId): array
    {
        $this->requireEncounterLock($encounterId);
        $this->detailRowsTouched = true;
        $this->actionRowsLocked = true;

        $stmt = $this->pdo->prepare('SELECT *
            FROM combat_actions
            WHERE encounter_id = :encounter_id
            ORDER BY id ASC
            FOR UPDATE');
        $stmt->execute(['encounter_id' => $encounterId]);

        return $stmt->fetchAll();
    }

    public function lockPendingActionsForEncounter(int $encounterId): array
    {
        $this->requireEncounterLock($encounterId);
        $this->detailRowsTouched = true;
        $this->actionRowsLocked = true;

        $stmt = $this->pdo->prepare("SELECT *
            FROM combat_actions
            WHERE encounter_id = :encounter_id
              AND state = 'pending'
            ORDER BY resolves_timeline_ms ASC, id ASC
            FOR UPDATE");
        $stmt->execute(['encounter_id' => $encounterId]);

        return $stmt->fetchAll();
    }

    public function resolveLockedAction(
        int $encounterId,
        int $actionId,
        int $completedTimelineMs,
    ): bool {
        $this->requireEncounterLock($encounterId);
        if (!$this->actionRowsLocked) {
            throw new LogicException('Combat action rows must be locked before resolution.');
        }

        $stmt = $this->pdo->prepare("UPDATE combat_actions
            SET state = 'resolved',
                active_slot = NULL,
                completed_timeline_ms = :completed_timeline_ms
            WHERE id = :action_id
              AND encounter_id = :encounter_id
              AND state = 'pending'");
        $stmt->execute([
            'completed_timeline_ms' => $completedTimelineMs,
            'action_id' => $actionId,
            'encounter_id' => $encounterId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function resolveLockedActionWithDamage(
        int $encounterId,
        int $actionId,
        int $completedTimelineMs,
        int $resolvedDamage,
        int $preventedDamage,
    ): bool {
        $this->requireEncounterLock($encounterId);
        if (!$this->actionRowsLocked) {
            throw new LogicException('Combat action rows must be locked before resolution.');
        }
        if ($completedTimelineMs < 0 || $resolvedDamage < 0 || $preventedDamage < 0) {
            throw new InvalidArgumentException('Combat action result values cannot be negative.');
        }

        $stmt = $this->pdo->prepare("UPDATE combat_actions
            SET state = 'resolved',
                active_slot = NULL,
                completed_timeline_ms = :completed_timeline_ms,
                resolved_damage = :resolved_damage,
                prevented_damage = :prevented_damage
            WHERE id = :action_id
              AND encounter_id = :encounter_id
              AND state = 'pending'");
        $stmt->execute([
            'completed_timeline_ms' => $completedTimelineMs,
            'resolved_damage' => $resolvedDamage,
            'prevented_damage' => $preventedDamage,
            'action_id' => $actionId,
            'encounter_id' => $encounterId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function cancelLockedPendingActionsForEncounter(
        int $encounterId,
        int $completedTimelineMs,
    ): int {
        $this->requireEncounterLock($encounterId);
        if (!$this->actionRowsLocked) {
            throw new LogicException('Combat action rows must be locked before cancellation.');
        }
        if ($completedTimelineMs < 0) {
            throw new InvalidArgumentException('Combat cancellation timeline cannot be negative.');
        }

        $stmt = $this->pdo->prepare("UPDATE combat_actions
            SET state = 'cancelled',
                active_slot = NULL,
                completed_timeline_ms = :completed_timeline_ms
            WHERE encounter_id = :encounter_id
              AND state = 'pending'");
        $stmt->execute([
            'completed_timeline_ms' => $completedTimelineMs,
            'encounter_id' => $encounterId,
        ]);

        return $stmt->rowCount();
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

    public function actionsForEncounter(int $encounterId): array
    {
        $stmt = $this->pdo->prepare('SELECT *
            FROM combat_actions
            WHERE encounter_id = :encounter_id
            ORDER BY id ASC');
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

    private function requireBlockActionLock(int $encounterId, int $actionId): void
    {
        $this->requireEncounterLock($encounterId);
        if ($this->lockedBlockActionId !== $actionId) {
            throw new LogicException('The incoming enemy action must be locked before Block mutation.');
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
        $this->lockedUserId = null;
        $this->activeEncounterLockChecked = false;
        $this->lockedEncounterId = null;
        $this->lockedEncounter = null;
        $this->detailRowsTouched = false;
        $this->actionRowsLocked = false;
        $this->lockedBlockActionId = null;
    }
}
