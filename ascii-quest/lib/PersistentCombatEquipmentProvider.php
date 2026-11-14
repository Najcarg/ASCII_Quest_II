<?php
declare(strict_types=1);

require_once __DIR__ . '/CharacterStats.php';
require_once __DIR__ . '/CombatDefinitionRegistry.php';
require_once __DIR__ . '/CombatEquipmentProvider.php';
require_once __DIR__ . '/EquipmentStatAggregator.php';

final class PersistentCombatEquipmentProvider implements CombatEquipmentProvider
{
    public function __construct(
        private object $repository,
        private CombatDefinitionRegistry $definitions,
        private EquipmentStatAggregator $aggregator,
    ) {}

    public function offensiveSnapshot(array $lockedCharacter, string $attackKey): array
    {
        $definition = $this->definitions->playerAction($attackKey);
        if ($definition === null || !in_array($definition['kind'] ?? null, ['weapon', 'skill'], true)) {
            throw new DomainException('Combat weapon action is unavailable.');
        }
        $items = $this->equippedItems($lockedCharacter);
        $weapon = EffectiveWeaponDamage::equippedWeapon($items);
        $modifiers = $this->aggregator->aggregate($items);
        $stats = CharacterStats::calculate($lockedCharacter, $modifiers, $weapon);
        if (($definition['kind'] ?? null) === 'skill') {
            return [
                'snapshot_weapon_item_id' => null,
                'snapshot_weapon_key' => null,
                'snapshot_damage_type' => (string) $definition['damage_type'],
                'snapshot_base_damage' => (int) $definition['prototype_damage'],
                'snapshot_accuracy' => (float) $stats['combat']['accuracy'],
                'snapshot_critical_chance' => (float) $stats['combat']['critical_chance'],
                'snapshot_critical_damage' => (int) $stats['combat']['critical_damage'],
            ];
        }
        $range = $stats['combat']['attack_damage'];
        // Preserve deterministic combat resolution, derived from the displayed effective range.
        $damage = (int) round(($range['min'] + $range['max']) / 2);
        // Keep normal attack timing/allowance architecture; unarmed has no item identity.
        $itemId = null;
        $definitionKey = null;
        if ($weapon !== null) {
            $itemId = self::positiveInteger($weapon, 'id');
            $definitionKey = $weapon['definition_key'] ?? null;
            if (!is_string($definitionKey) || $definitionKey === '') {
                throw new DomainException('Equipped weapon snapshot is invalid.');
            }
        }

        return [
            'snapshot_weapon_item_id' => $itemId,
            'snapshot_weapon_key' => $definitionKey,
            'snapshot_damage_type' => $range['type'],
            'snapshot_base_damage' => $damage,
            'snapshot_accuracy' => (float) $stats['combat']['accuracy'],
            'snapshot_critical_chance' => (float) $stats['combat']['critical_chance'],
            'snapshot_critical_damage' => (int) $stats['combat']['critical_damage'],
        ];
    }

    public function effectiveDurationMs(array $lockedCharacter, string $actionKey, int $baseDurationMs): int
    {
        if ($baseDurationMs < 1) {
            throw new InvalidArgumentException('Combat action duration is invalid.');
        }
        $definition = $this->definitions->playerAction($actionKey);
        if ($definition === null || !in_array($definition['kind'] ?? null, ['weapon', 'skill'], true)) {
            throw new DomainException('Combat player action is unavailable.');
        }
        $modifiers = $this->aggregator->aggregate($this->equippedItems($lockedCharacter));
        $stats = CharacterStats::calculate($lockedCharacter, $modifiers);
        $factor = ($definition['kind'] ?? null) === 'weapon'
            ? (float) $stats['rates']['attack_rate']
            : (float) $stats['rates']['cast_rate'];
        return max(1, (int) round($baseDurationMs / max(0.10, $factor)));
    }

    public function currentDefense(array $lockedCharacter): array
    {
        $modifiers = $this->aggregator->aggregate($this->equippedItems($lockedCharacter));
        $stats = CharacterStats::calculate($lockedCharacter, $modifiers);
        return [
            'toughness' => (int) $stats['combat']['toughness'],
            'dodging' => (float) $stats['combat']['dodging'],
            'block_rate' => (float) $stats['rates']['block_rate'],
            'resistances' => [
                'fire' => (float) $stats['resistances']['fire'],
                'lightning' => (float) $stats['resistances']['lightning'],
                'poison' => (float) $stats['resistances']['poison'],
                'cold' => (float) $stats['resistances']['cold'],
            ],
        ];
    }

    public function characterStats(array $character): array
    {
        $items = $this->equippedItems($character);
        $modifiers = $this->aggregator->aggregate($items);
        return CharacterStats::calculate($character, $modifiers, EffectiveWeaponDamage::equippedWeapon($items));
    }

    private function equippedItems(array $lockedCharacter): array
    {
        return $this->repository->lockEquippedItems(self::positiveInteger($lockedCharacter, 'id'));
    }

    private static function positiveInteger(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_string($value) && ctype_digit($value)) { $value = (int) $value; }
        if (!is_int($value) || $value < 1) { throw new DomainException('Invalid equipment identity.'); }
        return $value;
    }
}
