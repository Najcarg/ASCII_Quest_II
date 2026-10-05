<?php
declare(strict_types=1);

final class ItemDefinitionRegistry
{
    private const RARITY_ORDER = ['normal' => 0, 'magic' => 1, 'rare' => 2];
    private const ACTIVE_MODIFIERS = [
        'flat_damage', 'damage_percent_bp', 'maximum_life', 'maximum_mana',
        'strength', 'dexterity', 'vitality', 'energy', 'fate', 'toughness',
        'physical_resistance', 'fire_resistance', 'lightning_resistance',
        'poison_resistance', 'cold_resistance', 'attack_rate_bp', 'cast_rate_bp',
    ];

    public function __construct(
        private array $definitions,
        private array $affixes,
        private array $rarityWeights,
    ) {
        if ($definitions === [] || $affixes === []) {
            throw new InvalidArgumentException('The item generation catalogue cannot be empty.');
        }
        foreach ($rarityWeights as $rarity => $weight) {
            if (!isset(self::RARITY_ORDER[$rarity]) || !is_int($weight) || $weight < 0) {
                throw new InvalidArgumentException('Invalid item rarity weight.');
            }
        }
        if (array_sum($rarityWeights) < 1) {
            throw new InvalidArgumentException('At least one item rarity must be selectable.');
        }
        foreach ($definitions as $definition) {
            foreach (['definition_key', 'display_name', 'category', 'subtype', 'equipment_slot', 'minimum_item_level', 'maximum_rarity', 'loot_weight', 'allowed_affix_families'] as $key) {
                if (!array_key_exists($key, $definition)) {
                    throw new InvalidArgumentException('Incomplete item definition: ' . $key);
                }
            }
            if (!isset(self::RARITY_ORDER[$definition['maximum_rarity']]) || !is_array($definition['allowed_affix_families'])) {
                throw new InvalidArgumentException('Invalid item definition generation rules.');
            }
        }
        $activeAffixes = [];
        foreach ($affixes as $affix) {
            if (!in_array($affix['position'] ?? null, ['prefix', 'suffix'], true)
                || !is_array($affix['categories'] ?? null)
                || !is_array($affix['tiers'] ?? null)
                || !in_array($affix['modifier_operation'] ?? null, ['flat', 'additive_percent'], true)) {
                throw new InvalidArgumentException('Invalid active item affix.');
            }
            if (in_array($affix['modifier_type'] ?? null, self::ACTIVE_MODIFIERS, true)) {
                $activeAffixes[] = $affix;
            }
        }
        $this->affixes = $activeAffixes;
    }

    public function eligibleDefinitions(int $itemLevel): array
    {
        return array_values(array_filter($this->definitions, static fn (array $definition): bool =>
            (int) $definition['minimum_item_level'] <= $itemLevel
            && (int) $definition['loot_weight'] > 0));
    }

    public function eligibleAffixes(array $definition, string $position, int $itemLevel, array $excludedFamilies): array
    {
        return array_values(array_filter($this->affixes, static function (array $affix) use ($definition, $position, $itemLevel, $excludedFamilies): bool {
            return $affix['position'] === $position
                && (int) $affix['minimum_item_level'] <= $itemLevel
                && in_array($definition['category'], $affix['categories'], true)
                && in_array($affix['family_key'], $definition['allowed_affix_families'], true)
                && !in_array($affix['family_key'], $excludedFamilies, true);
        }));
    }

    public function eligibleTiers(array $affix, int $itemLevel): array
    {
        return array_values(array_filter($affix['tiers'], static fn (array $tier): bool =>
            (int) $tier['minimum_item_level'] <= $itemLevel));
    }

    public function rarityWeights(string $maximumRarity): array
    {
        $maximum = self::RARITY_ORDER[$maximumRarity];
        return array_filter($this->rarityWeights, static fn (int $weight, string $rarity): bool =>
            $weight > 0 && self::RARITY_ORDER[$rarity] <= $maximum, ARRAY_FILTER_USE_BOTH);
    }
}
