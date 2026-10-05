<?php
declare(strict_types=1);

require_once __DIR__ . '/ItemRandomSource.php';
require_once __DIR__ . '/ItemDefinitionRegistry.php';

final class ItemGenerator
{
    public function __construct(private ItemDefinitionRegistry $registry) {}

    public function generate(int $itemLevel, ItemRandomSource $random): array
    {
        if ($itemLevel < 1) {
            throw new InvalidArgumentException('Item level must be positive.');
        }
        $definition = $this->weightedChoice(
            $this->registry->eligibleDefinitions($itemLevel),
            'loot_weight',
            $random,
        );
        $rarity = (string) $this->weightedMapChoice(
            $this->registry->rarityWeights((string) $definition['maximum_rarity']),
            $random,
        );
        $positions = match ($rarity) {
            'normal' => [],
            'magic' => [$random->integer(0, 1) === 0 ? 'prefix' : 'suffix'],
            'rare' => ['prefix', 'suffix'],
            default => throw new RuntimeException('Unsupported generated rarity.'),
        };
        $rarePrefixCandidates = null;
        if ($rarity === 'rare') {
            $prefixes = $this->registry->eligibleAffixes($definition, 'prefix', $itemLevel, []);
            $rarePrefixCandidates = array_values(array_filter(
                $prefixes,
                fn (array $prefix): bool => $this->registry->eligibleAffixes(
                    $definition,
                    'suffix',
                    $itemLevel,
                    [(string) $prefix['family_key']],
                ) !== [],
            ));
            if ($rarePrefixCandidates === []) {
                $suffixes = $this->registry->eligibleAffixes($definition, 'suffix', $itemLevel, []);
                $rarity = $prefixes !== [] || $suffixes !== [] ? 'magic' : 'normal';
                $positions = $rarity === 'normal'
                    ? []
                    : [$prefixes !== [] ? 'prefix' : 'suffix'];
                $rarePrefixCandidates = null;
            }
        }

        $affixes = [];
        $families = [];
        foreach ($positions as $position) {
            $eligible = $position === 'prefix' && $rarePrefixCandidates !== null
                ? $rarePrefixCandidates
                : $this->registry->eligibleAffixes($definition, $position, $itemLevel, $families);
            if ($eligible === []) {
                if ($rarity === 'rare' && $affixes !== []) {
                    $rarity = 'magic';
                    break;
                }
                $otherPosition = $position === 'prefix' ? 'suffix' : 'prefix';
                $eligible = $this->registry->eligibleAffixes($definition, $otherPosition, $itemLevel, $families);
                if ($rarity === 'magic' && $eligible !== []) {
                    $position = $otherPosition;
                } else {
                    $rarity = 'normal';
                    $affixes = [];
                    break;
                }
            }
            $affix = $this->weightedChoice($eligible, 'selection_weight', $random);
            $tiers = $this->registry->eligibleTiers($affix, $itemLevel);
            if ($tiers === []) {
                throw new RuntimeException('No eligible affix tier exists at the source item level.');
            }
            $tier = $tiers[$random->integer(1, count($tiers)) - 1];
            $value = $random->integer((int) $tier['minimum_value'], (int) $tier['maximum_value']);
            $families[] = (string) $affix['family_key'];
            $affixes[] = [
                'affix_key' => (string) $affix['affix_key'],
                'family_key' => (string) $affix['family_key'],
                'position' => $position,
                'display_fragment' => (string) $affix['display_fragment'],
                'modifier_type' => (string) $affix['modifier_type'],
                'modifier_operation' => (string) $affix['modifier_operation'],
                'tier' => (int) $tier['tier'],
                'rolled_value' => $value,
            ];
        }

        $prefix = $this->fragment($affixes, 'prefix');
        $suffix = $this->fragment($affixes, 'suffix');
        $displayName = implode(' ', array_values(array_filter([
            $prefix, (string) $definition['display_name'], $suffix,
        ], static fn (string $part): bool => $part !== '')));

        return [
            'definition_key' => (string) $definition['definition_key'],
            'item_level' => $itemLevel,
            'rarity' => $rarity,
            'display_name' => $displayName,
            'snapshot_category' => (string) $definition['category'],
            'snapshot_subtype' => (string) $definition['subtype'],
            'snapshot_equipment_slot' => (string) $definition['equipment_slot'],
            'snapshot_damage_type' => $definition['damage_type'] ?? null,
            'snapshot_damage_min' => (int) ($definition['base_damage_min'] ?? 0),
            'snapshot_damage_max' => (int) ($definition['base_damage_max'] ?? 0),
            'snapshot_toughness' => (int) ($definition['base_toughness'] ?? 0),
            'snapshot_attack_rate_modifier_bp' => (int) ($definition['base_attack_rate_modifier_bp'] ?? 0),
            'snapshot_cast_rate_modifier_bp' => (int) ($definition['base_cast_rate_modifier_bp'] ?? 0),
            'snapshot_block_rate_modifier_bp' => (int) ($definition['base_block_rate_modifier_bp'] ?? 0),
            'glyph' => (string) $definition['glyph'],
            'affixes' => $affixes,
        ];
    }

    private function weightedChoice(array $rows, string $weightKey, ItemRandomSource $random): array
    {
        if ($rows === []) {
            throw new RuntimeException('No eligible item generation choice exists.');
        }
        $weights = [];
        foreach ($rows as $index => $row) {
            $weights[(string) $index] = (int) $row[$weightKey];
        }
        return $rows[(int) $this->weightedMapChoice($weights, $random)];
    }

    private function weightedMapChoice(array $weights, ItemRandomSource $random): string
    {
        $total = array_sum($weights);
        if ($total < 1) {
            throw new RuntimeException('Generation weights must be positive.');
        }
        $roll = $random->integer(1, $total);
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return (string) $key;
            }
        }
        throw new LogicException('Weighted item choice failed.');
    }

    private function fragment(array $affixes, string $position): string
    {
        foreach ($affixes as $affix) {
            if ($affix['position'] === $position) {
                return (string) $affix['display_fragment'];
            }
        }
        return '';
    }
}
