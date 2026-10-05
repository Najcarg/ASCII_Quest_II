<?php
declare(strict_types=1);

final class ItemProjector
{
    private const RARITIES = ['normal', 'magic', 'rare'];
    private const CATEGORIES = ['weapon', 'armour', 'off_hand', 'jewellery'];
    private const SLOTS = ['helm', 'gloves', 'chest', 'ring', 'weapon', 'off-hand', 'amulet', 'belt', 'charm', 'boots'];
    private const DAMAGE_TYPES = ['physical', 'fire', 'lightning', 'poison', 'cold'];

    public function projectItem(
        array $row,
        array $affixes = [],
        ?string $equippedSlot = null,
    ): array {
        $id = $this->positiveInteger($row['id'] ?? null, 'item id');
        $level = $this->positiveInteger($row['item_level'] ?? null, 'item level');
        $name = $this->nonEmptyString($row['display_name'] ?? null, 'display name', 160);
        $rarity = $this->allowedString($row['rarity'] ?? null, self::RARITIES, 'rarity');
        $category = $this->allowedString($row['snapshot_category'] ?? null, self::CATEGORIES, 'category');
        $baseType = $this->asciiKey($row['snapshot_subtype'] ?? null, 'base type', 32);
        $slot = $this->allowedString($row['snapshot_equipment_slot'] ?? null, self::SLOTS, 'equipment slot');
        $glyph = $this->nonEmptyString($row['glyph'] ?? null, 'glyph', 8);
        $damageType = $row['snapshot_damage_type'] ?? null;
        if ($damageType !== null) {
            $damageType = $this->allowedString($damageType, self::DAMAGE_TYPES, 'damage type');
        }
        if ($equippedSlot !== null && !in_array($equippedSlot, self::SLOTS, true)) {
            throw new UnexpectedValueException('Invalid equipped slot.');
        }

        $damageMin = $this->nonNegativeInteger($row['snapshot_damage_min'] ?? null, 'minimum damage');
        $damageMax = $this->nonNegativeInteger($row['snapshot_damage_max'] ?? null, 'maximum damage');
        if ($damageMin > $damageMax) {
            throw new UnexpectedValueException('Invalid damage range.');
        }

        $publicAffixes = [];
        $statLines = [];
        foreach ($affixes as $affix) {
            $position = $this->allowedString($affix['position'] ?? null, ['prefix', 'suffix'], 'affix position');
            $fragment = $this->nonEmptyString($affix['display_fragment'] ?? null, 'affix display fragment', 96);
            $modifier = $this->asciiKey($affix['modifier_type'] ?? null, 'affix modifier', 48);
            $operation = $this->allowedString($affix['modifier_operation'] ?? null, ['flat', 'additive_percent'], 'affix operation');
            $value = $this->integer($affix['rolled_value'] ?? null, 'affix value');
            $tier = $this->positiveInteger($affix['tier'] ?? null, 'affix tier');
            $publicAffixes[] = [
                'position' => $position,
                'display_fragment' => $fragment,
                'modifier_type' => $modifier,
                'operation' => $operation,
                'value' => $value,
                'tier' => $tier,
            ];
            $statLines[] = $this->statLine($modifier, $operation, $value);
        }

        $projected = [
            'id' => $id,
            'display_name' => $name,
            'rarity' => $rarity,
            'item_level' => $level,
            'base_type' => $baseType,
            'category' => $category,
            'equipment_slot' => $slot,
            'glyph' => $glyph,
            'base_stats' => [
                'damage_type' => $damageType,
                'damage_min' => $damageMin,
                'damage_max' => $damageMax,
                'toughness' => $this->nonNegativeInteger($row['snapshot_toughness'] ?? null, 'toughness'),
                'attack_rate_modifier_bp' => $this->integer($row['snapshot_attack_rate_modifier_bp'] ?? null, 'attack rate modifier'),
                'cast_rate_modifier_bp' => $this->integer($row['snapshot_cast_rate_modifier_bp'] ?? null, 'cast rate modifier'),
                'block_rate_modifier_bp' => $this->integer($row['snapshot_block_rate_modifier_bp'] ?? null, 'block rate modifier'),
            ],
            'equipped' => $equippedSlot !== null,
            'equipped_slot' => $equippedSlot,
        ];
        if ($publicAffixes !== []) {
            $projected['affixes'] = $publicAffixes;
            $projected['stat_lines'] = $statLines;
        }
        return $projected;
    }

    private function statLine(string $modifier, string $operation, int $value): string
    {
        $label = match ($modifier) {
            'flat_damage' => 'Damage',
            'damage_percent_bp' => 'Damage',
            'maximum_life' => 'Maximum Life',
            'maximum_mana' => 'Maximum Mana',
            'strength' => 'Strength',
            'dexterity' => 'Dexterity',
            'vitality' => 'Vitality',
            'energy' => 'Energy',
            'fate' => 'Fate',
            'toughness' => 'Toughness',
            'physical_resistance' => 'Physical Resistance',
            'fire_resistance' => 'Fire Resistance',
            'lightning_resistance' => 'Lightning Resistance',
            'poison_resistance' => 'Poison Resistance',
            'cold_resistance' => 'Cold Resistance',
            'attack_rate_bp' => 'Attack Rate',
            'cast_rate_bp' => 'Cast Rate',
            default => throw new UnexpectedValueException('Unsupported public item modifier.'),
        };
        if ($operation === 'additive_percent') {
            $absolute = abs($value);
            return sprintf('%s%d.%02d%% %s', $value >= 0 ? '+' : '-', intdiv($absolute, 100), $absolute % 100, $label);
        }
        return sprintf('%+d %s', $value, $label);
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        $integer = $this->integer($value, $field);
        if ($integer < 1) {
            throw new UnexpectedValueException('Invalid ' . $field . '.');
        }
        return $integer;
    }

    private function nonNegativeInteger(mixed $value, string $field): int
    {
        $integer = $this->integer($value, $field);
        if ($integer < 0) {
            throw new UnexpectedValueException('Invalid ' . $field . '.');
        }
        return $integer;
    }

    private function integer(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?(0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new UnexpectedValueException('Invalid ' . $field . '.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new UnexpectedValueException('Invalid ' . $field . '.');
        }
        return $integer;
    }

    private function nonEmptyString(mixed $value, string $field, int $maximumBytes): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $maximumBytes) {
            throw new UnexpectedValueException('Invalid ' . $field . '.');
        }
        return $value;
    }

    private function asciiKey(mixed $value, string $field, int $maximumBytes): string
    {
        $key = $this->nonEmptyString($value, $field, $maximumBytes);
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $key) !== 1) {
            throw new UnexpectedValueException('Invalid ' . $field . '.');
        }
        return $key;
    }

    private function allowedString(mixed $value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new UnexpectedValueException('Invalid ' . $field . '.');
        }
        return $value;
    }
}
