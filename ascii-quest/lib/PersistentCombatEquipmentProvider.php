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
        if ($definition === null || ($definition['kind'] ?? null) !== 'weapon') {
            throw new DomainException('Combat weapon action is unavailable.');
        }
        $items = $this->equippedItems($lockedCharacter);
        $weapon = null;
        foreach ($items as $item) {
            if (($item['equipment_slot'] ?? null) === 'weapon') {
                $weapon = $item;
                break;
            }
        }
        if ($weapon === null || ($weapon['snapshot_equipment_slot'] ?? null) !== 'weapon') {
            throw new DomainException('No equipped weapon is available.');
        }
        $modifiers = $this->aggregator->aggregate($items);
        $stats = CharacterStats::calculate($lockedCharacter, $modifiers);
        $minimum = self::nonNegativeInteger($weapon, 'snapshot_damage_min');
        $maximum = self::nonNegativeInteger($weapon, 'snapshot_damage_max');
        if ($minimum > $maximum) {
            throw new DomainException('Equipped weapon damage is invalid.');
        }
        $baseDamage = (int) round(($minimum + $maximum) / 2);
        $flatDamage = $modifiers['flat_damage'];
        $percent = max(-10000, $modifiers['damage_percent_bp']);
        $damage = max(0, (int) round(($baseDamage + $flatDamage) * (1 + ($percent / 10000))));
        $itemId = self::positiveInteger($weapon, 'id');
        $definitionKey = $weapon['definition_key'] ?? null;
        $damageType = $weapon['snapshot_damage_type'] ?? null;
        if (!is_string($definitionKey) || $definitionKey === '' || !is_string($damageType) || $damageType === '') {
            throw new DomainException('Equipped weapon snapshot is invalid.');
        }

        return [
            'snapshot_weapon_item_id' => $itemId,
            'snapshot_weapon_key' => $definitionKey,
            'snapshot_damage_type' => $damageType,
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
            'resistances' => [
                'fire' => (float) $stats['resistances']['fire'],
                'lightning' => (float) $stats['resistances']['lightning'],
                'poison' => (float) $stats['resistances']['poison'],
                'cold' => (float) $stats['resistances']['cold'],
            ],
        ];
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

    private static function nonNegativeInteger(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_string($value) && ctype_digit($value)) { $value = (int) $value; }
        if (!is_int($value) || $value < 0) { throw new DomainException('Invalid weapon damage.'); }
        return $value;
    }
}
