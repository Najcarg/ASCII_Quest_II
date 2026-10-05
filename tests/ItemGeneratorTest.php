<?php
declare(strict_types=1);

require_once __DIR__ . '/../ascii-quest/lib/ItemRandomSource.php';
require_once __DIR__ . '/../ascii-quest/lib/ItemDefinitionRegistry.php';
require_once __DIR__ . '/../ascii-quest/lib/ItemGenerator.php';

final class QueuedItemRandomSource implements ItemRandomSource
{
    public array $calls = [];
    public function __construct(private array $values) {}
    public function integer(int $minimum, int $maximum): int
    {
        $this->calls[] = [$minimum, $maximum];
        if ($this->values === []) {
            throw new RuntimeException('Unexpected random call.');
        }
        $value = array_shift($this->values);
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("Queued random value {$value} is outside {$minimum}..{$maximum}.");
        }
        return $value;
    }
}

function generatorRegistry(): ItemDefinitionRegistry
{
    return new ItemDefinitionRegistry(
        [[
            'definition_key' => 'axe', 'display_name' => 'Axe',
            'category' => 'weapon', 'subtype' => 'axe', 'equipment_slot' => 'weapon',
            'damage_type' => 'physical', 'base_damage_min' => 5, 'base_damage_max' => 8,
            'base_toughness' => 0, 'base_attack_rate_modifier_bp' => 0,
            'base_cast_rate_modifier_bp' => 0, 'base_block_rate_modifier_bp' => 0,
            'minimum_item_level' => 1, 'maximum_rarity' => 'rare', 'glyph' => '/',
            'loot_weight' => 100, 'allowed_affix_families' => ['damage', 'life'],
        ]],
        [[
            'affix_key' => 'hunter', 'family_key' => 'damage', 'position' => 'prefix',
            'display_fragment' => 'Hunter', 'modifier_type' => 'flat_damage',
            'modifier_operation' => 'flat',
            'minimum_item_level' => 1, 'selection_weight' => 100,
            'categories' => ['weapon'],
            'tiers' => [
                ['tier' => 1, 'minimum_item_level' => 1, 'minimum_value' => 1, 'maximum_value' => 3],
                ['tier' => 2, 'minimum_item_level' => 10, 'minimum_value' => 4, 'maximum_value' => 7],
            ],
        ], [
            'affix_key' => 'of_health', 'family_key' => 'life', 'position' => 'suffix',
            'display_fragment' => 'of Health', 'modifier_type' => 'maximum_life',
            'modifier_operation' => 'flat',
            'minimum_item_level' => 1, 'selection_weight' => 100,
            'categories' => ['weapon', 'armour'],
            'tiers' => [['tier' => 1, 'minimum_item_level' => 1, 'minimum_value' => 2, 'maximum_value' => 5]],
        ], [
            'affix_key' => 'forbidden', 'family_key' => 'forbidden', 'position' => 'prefix',
            'display_fragment' => 'Forbidden', 'modifier_type' => 'critical_chance_bp',
            'modifier_operation' => 'additive_percent',
            'minimum_item_level' => 1, 'selection_weight' => 100,
            'categories' => ['armour'], 'tiers' => [['tier' => 1, 'minimum_item_level' => 1, 'minimum_value' => 1, 'maximum_value' => 1]],
        ]],
        ['normal' => 60, 'magic' => 30, 'rare' => 10],
    );
}

return [
    'Normal generation is source-level immutable and has no affixes' => function (): void {
        $random = new QueuedItemRandomSource([1, 1]);
        $item = (new ItemGenerator(generatorRegistry()))->generate(1, $random);
        assertSameValue('normal', $item['rarity'], 'Rarity.');
        assertSameValue(1, $item['item_level'], 'Source level only.');
        assertSameValue('Axe', $item['display_name'], 'Base name.');
        assertSameValue([], $item['affixes'], 'No normal affixes.');
    },

    'Magic generation can roll exactly one prefix' => function (): void {
        $item = (new ItemGenerator(generatorRegistry()))->generate(1, new QueuedItemRandomSource([1, 61, 0, 1, 1, 3]));
        assertSameValue('magic', $item['rarity'], 'Rarity.');
        assertSameValue('Hunter Axe', $item['display_name'], 'Server name.');
        assertSameValue('prefix', $item['affixes'][0]['position'] ?? null, 'Prefix.');
        assertSameValue(3, $item['affixes'][0]['rolled_value'] ?? null, 'Inclusive value.');
    },

    'Magic generation can roll exactly one suffix' => function (): void {
        $item = (new ItemGenerator(generatorRegistry()))->generate(1, new QueuedItemRandomSource([1, 61, 1, 1, 1, 2]));
        assertSameValue('Axe of Health', $item['display_name'], 'Server name.');
        assertSameValue('suffix', $item['affixes'][0]['position'] ?? null, 'Suffix.');
    },

    'Rare generation rolls one prefix and one suffix' => function (): void {
        $item = (new ItemGenerator(generatorRegistry()))->generate(1, new QueuedItemRandomSource([1, 91, 1, 1, 1, 1, 1, 5]));
        assertSameValue('rare', $item['rarity'], 'Rarity.');
        assertSameValue('Hunter Axe of Health', $item['display_name'], 'Combined server name.');
        assertSameValue(2, count($item['affixes']), 'Two affixes.');
    },

    'Affix tiers cannot exceed source item level' => function (): void {
        $low = (new ItemGenerator(generatorRegistry()))->generate(1, new QueuedItemRandomSource([1, 61, 0, 1, 1, 1]));
        assertSameValue(1, $low['affixes'][0]['tier'], 'Low source tier.');
        $high = (new ItemGenerator(generatorRegistry()))->generate(10, new QueuedItemRandomSource([1, 61, 0, 1, 2, 7]));
        assertSameValue(2, $high['affixes'][0]['tier'], 'High source tier.');
    },

    'Category and definition family allowlists exclude invalid affixes' => function (): void {
        $item = (new ItemGenerator(generatorRegistry()))->generate(1, new QueuedItemRandomSource([1, 61, 0, 1, 1, 1]));
        assertSameValue('hunter', $item['affixes'][0]['affix_key'], 'Only allowed prefix.');
        assertSameValue(false, str_contains($item['display_name'], 'Forbidden'), 'Unsupported mechanics never generated.');
    },

    'Generator rejects non-positive source item level' => function (): void {
        try {
            (new ItemGenerator(generatorRegistry()))->generate(0, new QueuedItemRandomSource([]));
            throw new RuntimeException('Expected item-level rejection.');
        } catch (InvalidArgumentException) {
        }
    },
];
