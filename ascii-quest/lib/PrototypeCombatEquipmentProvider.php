<?php
declare(strict_types=1);

require_once __DIR__ . '/CharacterStats.php';
require_once __DIR__ . '/CombatDefinitionRegistry.php';
require_once __DIR__ . '/CombatEquipmentProvider.php';

final class PrototypeCombatEquipmentProvider implements CombatEquipmentProvider
{
    public function __construct(private CombatDefinitionRegistry $definitions)
    {
    }

    public function offensiveSnapshot(array $lockedCharacter, string $attackKey): array
    {
        $definition = $this->definitions->playerAction($attackKey);
        if ($definition === null || ($definition['kind'] ?? null) !== 'weapon') {
            throw new DomainException('Combat weapon action is unavailable.');
        }

        $stats = CharacterStats::calculate($lockedCharacter);

        return [
            'snapshot_weapon_key' => (string) $definition['key'],
            'snapshot_damage_type' => (string) $definition['damage_type'],
            'snapshot_base_damage' => (int) $definition['prototype_damage'],
            'snapshot_accuracy' => (float) $stats['combat']['accuracy'],
            'snapshot_critical_chance' => (float) $stats['combat']['critical_chance'],
            'snapshot_critical_damage' => (int) $stats['combat']['critical_damage'],
        ];
    }

    public function currentDefense(array $lockedCharacter): array
    {
        $stats = CharacterStats::calculate($lockedCharacter);

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
}
