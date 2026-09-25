<?php
declare(strict_types=1);

require_once __DIR__ . '/BlockResolver.php';
require_once __DIR__ . '/CombatDefinitionRegistry.php';
require_once __DIR__ . '/CombatEquipmentProvider.php';
require_once __DIR__ . '/CombatRepository.php';
require_once __DIR__ . '/EnemyDefenseResolver.php';

final class CombatActionResolver
{
    public function __construct(
        private CombatRepository $repository,
        private CombatDefinitionRegistry $definitions,
        private CombatEquipmentProvider $equipment,
        private EnemyDefenseResolver $enemyDefense,
        private BlockResolver $blockResolver,
    ) {
    }

    public function resolvePending(
        array $encounter,
        array $lockedCharacter,
        array $lockedAction,
    ): array {
        if (($lockedAction['state'] ?? null) !== 'pending') {
            throw new DomainException('Only a pending combat action can be resolved.');
        }
        self::assertActionBelongsToEncounter($encounter, $lockedAction);

        return match ($lockedAction['actor'] ?? null) {
            'player' => $this->resolvePlayerAction(
                $encounter,
                $lockedCharacter,
                $lockedAction,
            ),
            'enemy' => $this->resolveEnemyAttackUsingCurrentDefense(
                $encounter,
                $lockedCharacter,
                $lockedAction,
            ),
            default => throw new DomainException('Unsupported combat action actor.'),
        };
    }

    private function resolvePlayerAction(
        array $encounter,
        array $lockedCharacter,
        array $lockedAction,
    ): array {
        $definitionKey = self::requiredString($lockedAction, 'definition_key');
        $definition = $this->definitions->playerAction($definitionKey);
        if (
            $definition === null ||
            !in_array($definition['kind'] ?? null, ['weapon', 'skill'], true) ||
            ($lockedAction['action_kind'] ?? null) !== ($definition['kind'] ?? null) ||
            ($lockedAction['snapshot_damage_type'] ?? null) !== ($definition['damage_type'] ?? null)
        ) {
            throw new DomainException('Unsupported player combat action.');
        }

        $enemyDefinition = $this->enemyDefinition($encounter);
        $damage = $this->enemyDefense->resolve($lockedAction, $enemyDefinition);
        $appliedDamage = self::requiredNonNegativeInteger($damage, 'applied_damage');
        $preventedDamage = self::requiredNonNegativeInteger($damage, 'prevented_damage');
        $enemyCurrentHp = self::requiredNonNegativeInteger($encounter, 'enemy_current_hp');
        $completedTimelineMs = self::requiredNonNegativeInteger(
            $lockedAction,
            'resolves_timeline_ms',
        );

        if (!$this->repository->resolveLockedActionWithDamage(
            self::requiredPositiveInteger($encounter, 'id'),
            self::requiredPositiveInteger($lockedAction, 'id'),
            $completedTimelineMs,
            $appliedDamage,
            $preventedDamage,
        )) {
            throw new RuntimeException('Pending player action resolution could not be persisted.');
        }

        if (($definition['kind'] ?? null) === 'skill') {
            $this->createResolvedEffect(
                self::requiredPositiveInteger($encounter, 'id'),
                self::requiredPositiveInteger($lockedAction, 'id'),
                $completedTimelineMs,
                $definition,
            );
        }

        $encounter['enemy_current_hp'] = max(0, $enemyCurrentHp - $appliedDamage);

        return [
            'encounter' => $encounter,
            'character' => $lockedCharacter,
        ];
    }

    private function createResolvedEffect(
        int $encounterId,
        int $parentActionId,
        int $startedTimelineMs,
        array $skillDefinition,
    ): void {
        $effect = $skillDefinition['effect'] ?? null;
        if (!is_array($effect)) {
            throw new DomainException('Player skill effect is unavailable.');
        }
        $durationMs = (int) round((float) ($effect['duration_seconds'] ?? 0) * 1000);
        if ($durationMs <= 0) {
            throw new DomainException('Player skill effect duration is invalid.');
        }

        $stored = $this->repository->createAction($encounterId, [
            'parent_action_id' => $parentActionId,
            'actor' => 'system',
            'action_kind' => 'skill',
            'definition_key' => self::requiredString($effect, 'key'),
            'request_token' => null,
            'active_slot' => null,
            'state' => 'resolved',
            'started_timeline_ms' => $startedTimelineMs,
            'resolves_timeline_ms' => $startedTimelineMs + $durationMs,
            'cooldown_ready_timeline_ms' => null,
            'completed_timeline_ms' => $startedTimelineMs,
            'snapshot_weapon_key' => null,
            'snapshot_damage_type' => null,
            'snapshot_base_damage' => null,
            'snapshot_accuracy' => null,
            'snapshot_critical_chance' => null,
            'snapshot_critical_damage' => null,
        ]);
        if (
            ($stored['actor'] ?? null) !== 'system' ||
            ($stored['state'] ?? null) !== 'resolved' ||
            self::requiredPositiveInteger($stored, 'parent_action_id') !== $parentActionId
        ) {
            throw new RuntimeException('Player skill effect persistence returned invalid state.');
        }
    }

    private function resolveEnemyAttackUsingCurrentDefense(
        array $encounter,
        array $lockedCharacter,
        array $lockedAction,
    ): array {
        $enemyDefinition = $this->enemyDefinition($encounter);
        $definitionKey = self::requiredString($lockedAction, 'definition_key');
        $definition = $enemyDefinition['actions'][$definitionKey] ?? null;
        if (
            !is_array($definition) ||
            !in_array($definitionKey, ['smash', 'fire_slam'], true) ||
            ($lockedAction['action_kind'] ?? null) !== ($definition['kind'] ?? null) ||
            ($lockedAction['snapshot_damage_type'] ?? null) !== ($definition['damage_type'] ?? null)
        ) {
            throw new DomainException('Unsupported enemy combat action.');
        }

        $damage = $this->blockResolver->resolve(
            $lockedAction,
            $this->equipment->currentDefense($lockedCharacter),
            ($lockedAction['block_attempted_timeline_ms'] ?? null) !== null,
        );
        $appliedDamage = self::requiredNonNegativeInteger($damage, 'applied_damage');
        $preventedDamage = self::requiredNonNegativeInteger($damage, 'prevented_damage');
        $currentHp = self::requiredNonNegativeInteger($lockedCharacter, 'current_hp');
        $newCurrentHp = max(0, $currentHp - $appliedDamage);

        if (!$this->repository->updateLockedCharacterCurrentHp(
            self::requiredPositiveInteger($lockedCharacter, 'user_id'),
            self::requiredPositiveInteger($lockedCharacter, 'id'),
            $currentHp,
            $newCurrentHp,
        )) {
            throw new RuntimeException('Champion current HP changed before action resolution.');
        }
        if (!$this->repository->resolveLockedActionWithDamage(
            self::requiredPositiveInteger($encounter, 'id'),
            self::requiredPositiveInteger($lockedAction, 'id'),
            self::requiredNonNegativeInteger($lockedAction, 'resolves_timeline_ms'),
            $appliedDamage,
            $preventedDamage,
        )) {
            throw new RuntimeException('Pending enemy action resolution could not be persisted.');
        }

        $lockedCharacter['current_hp'] = $newCurrentHp;

        return [
            'encounter' => $encounter,
            'character' => $lockedCharacter,
        ];
    }

    private function enemyDefinition(array $encounter): array
    {
        $enemyKey = self::requiredString($encounter, 'enemy_key');
        $definition = $this->definitions->enemy($enemyKey);
        if ($definition === null) {
            throw new DomainException('Combat enemy definition is unavailable.');
        }

        return $definition;
    }

    private static function assertActionBelongsToEncounter(
        array $encounter,
        array $lockedAction,
    ): void {
        if (
            self::requiredPositiveInteger($encounter, 'id') !==
            self::requiredPositiveInteger($lockedAction, 'encounter_id')
        ) {
            throw new DomainException('Combat action does not belong to the encounter.');
        }
    }

    private static function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new DomainException("Combat action field {$key} is invalid.");
        }

        return $value;
    }

    private static function requiredPositiveInteger(array $values, string $key): int
    {
        $value = self::requiredNonNegativeInteger($values, $key);
        if ($value === 0) {
            throw new DomainException("Combat action field {$key} must be positive.");
        }

        return $value;
    }

    private static function requiredNonNegativeInteger(array $values, string $key): int
    {
        $value = $values[$key] ?? null;
        if (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0) {
            throw new DomainException("Combat action field {$key} is invalid.");
        }

        return $value;
    }
}
