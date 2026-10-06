<?php
declare(strict_types=1);

final class EquipmentStatAggregator
{
    private const OPERATIONS = [
        'strength' => 'flat',
        'dexterity' => 'flat',
        'vitality' => 'flat',
        'energy' => 'flat',
        'fate' => 'flat',
        'maximum_life' => 'flat',
        'maximum_mana' => 'flat',
        'toughness' => 'flat',
        'fire_resistance' => 'flat',
        'lightning_resistance' => 'flat',
        'poison_resistance' => 'flat',
        'cold_resistance' => 'flat',
        'accuracy' => 'flat',
        'critical_chance' => 'flat',
        'critical_damage' => 'flat',
        'dodging' => 'flat',
        'life_on_hit' => 'flat',
        'fire_damage' => 'flat',
        'lightning_damage' => 'flat',
        'cold_damage' => 'flat',
        'poison_damage' => 'flat',
        'bleed_damage' => 'flat',
        'burn_damage' => 'flat',
        'freeze_damage' => 'flat',
        'shock_damage' => 'flat',
        'flat_damage' => 'flat',
        'damage_percent_bp' => 'additive_percent',
        'attack_rate_bp' => 'additive_percent',
        'cast_rate_bp' => 'additive_percent',
        'block_rate_bp' => 'additive_percent',
    ];

    private const LIMIT = 1000000000;

    public function aggregate(array $items): array
    {
        usort($items, static fn (array $left, array $right): int => (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0));
        $totals = array_fill_keys(array_keys(self::OPERATIONS), 0);

        foreach ($items as $item) {
            if (!isset($item['id']) || filter_var($item['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new UnexpectedValueException('Invalid equipped item id.');
            }
            $this->add($totals, 'toughness', $item['snapshot_toughness'] ?? 0);
            $this->add($totals, 'attack_rate_bp', $item['snapshot_attack_rate_modifier_bp'] ?? 0);
            $this->add($totals, 'cast_rate_bp', $item['snapshot_cast_rate_modifier_bp'] ?? 0);
            $this->add($totals, 'block_rate_bp', $item['snapshot_block_rate_modifier_bp'] ?? 0);

            $affixes = $item['affixes'] ?? [];
            if (!is_array($affixes)) {
                throw new UnexpectedValueException('Invalid equipped item affixes.');
            }
            foreach ($affixes as $affix) {
                $key = $affix['modifier_type'] ?? null;
                $operation = $affix['modifier_operation'] ?? null;
                if (!is_string($key) || !isset(self::OPERATIONS[$key])) {
                    throw new UnexpectedValueException('Unknown equipment modifier.');
                }
                if ($operation !== self::OPERATIONS[$key]) {
                    throw new UnexpectedValueException('Invalid equipment modifier operation.');
                }
                $this->add($totals, $key, $affix['rolled_value'] ?? null);
            }
        }

        return $totals;
    }

    private function add(array &$totals, string $key, mixed $value): void
    {
        if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)$/D', $value) === 1) {
            $value = filter_var($value, FILTER_VALIDATE_INT);
        }
        if (!is_int($value) || abs($value) > self::LIMIT) {
            throw new UnexpectedValueException('Invalid equipment modifier value.');
        }
        $sum = $totals[$key] + $value;
        if (abs($sum) > self::LIMIT) {
            throw new OverflowException('Equipment modifier total exceeds supported bounds.');
        }
        $totals[$key] = $sum;
    }
}
