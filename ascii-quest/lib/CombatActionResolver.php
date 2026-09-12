<?php
declare(strict_types=1);

require_once __DIR__ . '/ChampionDamageResolver.php';
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
        private ChampionDamageResolver $championDamage,
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
            'player' => $this->resolvePlayerWeapon(
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

    private function resolvePlayerWeapon(
        array $encounter,
        array $lockedCharacter,
        array $lockedAction,
    ): array {
        $definitionKey = self::requiredString($lockedAction, 'definition_key');
        $definition = $this->definitions->playerAction($definitionKey);
        if (
            $definitionKey !== 'prototype_weapon_attack' ||
            $definition === null ||
            ($definition['kind'] ?? null) !== 'weapon' ||
            ($lockedAction['action_kind'] ?? null) !== 'weapon' ||
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

        $encounter['enemy_current_hp'] = max(0, $enemyCurrentHp - $appliedDamage);

        return [
            'encounter' => $encounter,
            'character' => $lockedCharacter,
        ];
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

        $damage = $this->championDamage->resolve(
            $lockedAction,
            $this->equipment->currentDefense($lockedCharacter),
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
