/* Read-only Task31 verification. Zero rows = all checks pass.
   Does not inspect/mutate historical instances or grant equipment. */
USE ascii_quest;
WITH
expected_item_definitions AS (
    SELECT 'worn_sword' AS definition_key, 'Sword' AS display_name, 'weapon' AS category, 'sword' AS subtype, 'weapon' AS equipment_slot, 'physical' AS damage_type, 4 AS base_damage_min, 7 AS base_damage_max, 0 AS base_toughness, 1 AS minimum_item_level, 'rare' AS maximum_rarity, '/' AS glyph, 100 AS loot_weight
    UNION ALL SELECT 'worn_dagger', 'Dagger', 'weapon', 'dagger', 'weapon', 'physical', 3, 5, 0, 1, 'rare', '/', 100
    UNION ALL SELECT 'worn_mace', 'Mace', 'weapon', 'mace', 'weapon', 'physical', 5, 7, 0, 1, 'rare', '/', 100
    UNION ALL SELECT 'ash_wand', 'Wand', 'weapon', 'wand', 'weapon', 'fire', 3, 6, 0, 1, 'rare', '/', 100
    UNION ALL SELECT 'hide_gloves', 'Hide Gloves', 'armour', 'gloves', 'gloves', NULL, 0, 0, 2, 1, 'rare', 'G', 100
    UNION ALL SELECT 'hide_boots', 'Hide Boots', 'armour', 'boots', 'boots', NULL, 0, 0, 2, 1, 'rare', 'B', 100
    UNION ALL SELECT 'hide_belt', 'Hide Belt', 'armour', 'belt', 'belt', NULL, 0, 0, 3, 1, 'rare', '=', 100
    UNION ALL SELECT 'copper_amulet', 'Copper Amulet', 'jewellery', 'amulet', 'amulet', NULL, 0, 0, 0, 1, 'rare', 'o', 100
    UNION ALL SELECT 'copper_charm', 'Copper Charm', 'jewellery', 'charm', 'charm', NULL, 0, 0, 0, 1, 'rare', '*', 100
),
expected_item_affix_definitions AS (
    SELECT 'keen' AS affix_key, 'accuracy' AS family_key, 'prefix' AS position, 'Keen' AS display_fragment, 'accuracy' AS modifier_type, 'flat' AS modifier_operation, 1 AS minimum_item_level, 100 AS selection_weight
    UNION ALL SELECT 'deadly', 'critical_chance', 'prefix', 'Deadly', 'critical_chance', 'flat', 1, 100
    UNION ALL SELECT 'savage', 'critical_damage', 'prefix', 'Savage', 'critical_damage', 'flat', 1, 100
    UNION ALL SELECT 'vampiric', 'life_on_hit', 'prefix', 'Vampiric', 'life_on_hit', 'flat', 1, 100
    UNION ALL SELECT 'flaming', 'fire_damage', 'prefix', 'Flaming', 'fire_damage', 'flat', 1, 100
    UNION ALL SELECT 'stormcharged', 'lightning_damage', 'prefix', 'Stormcharged', 'lightning_damage', 'flat', 1, 100
    UNION ALL SELECT 'frosted', 'cold_damage', 'prefix', 'Frosted', 'cold_damage', 'flat', 1, 100
    UNION ALL SELECT 'venomous', 'poison_damage', 'prefix', 'Venomous', 'poison_damage', 'flat', 1, 100
    UNION ALL SELECT 'bleeding', 'bleed_damage', 'prefix', 'Bleeding', 'bleed_damage', 'flat', 1, 100
    UNION ALL SELECT 'scorching', 'burn_damage', 'prefix', 'Scorching', 'burn_damage', 'flat', 1, 100
    UNION ALL SELECT 'freezing', 'freeze_damage', 'prefix', 'Freezing', 'freeze_damage', 'flat', 1, 100
    UNION ALL SELECT 'shocking', 'shock_damage', 'prefix', 'Shocking', 'shock_damage', 'flat', 1, 100
    UNION ALL SELECT 'of_evasion', 'dodging', 'suffix', 'of Evasion', 'dodging', 'flat', 1, 100
    UNION ALL SELECT 'of_guarding', 'block_rate', 'suffix', 'of Guarding', 'block_rate_bp', 'additive_percent', 1, 100
    UNION ALL SELECT 'of_storm_warding', 'lightning_resistance', 'suffix', 'of Storm Warding', 'lightning_resistance', 'flat', 1, 100
    UNION ALL SELECT 'of_poison_warding', 'poison_resistance', 'suffix', 'of Poison Warding', 'poison_resistance', 'flat', 1, 100
),
expected_item_affix_tiers AS (
    SELECT 'keen' AS affix_key, 1 AS tier, 1 AS minimum_item_level, 1 AS minimum_value, 3 AS maximum_value
    UNION ALL SELECT 'keen', 2, 10, 4, 7
    UNION ALL SELECT 'deadly', 1, 1, 1, 3
    UNION ALL SELECT 'deadly', 2, 10, 4, 7
    UNION ALL SELECT 'savage', 1, 1, 5, 10
    UNION ALL SELECT 'savage', 2, 10, 11, 20
    UNION ALL SELECT 'vampiric', 1, 1, 1, 3
    UNION ALL SELECT 'vampiric', 2, 10, 4, 7
    UNION ALL SELECT 'flaming', 1, 1, 1, 3
    UNION ALL SELECT 'flaming', 2, 10, 4, 7
    UNION ALL SELECT 'stormcharged', 1, 1, 1, 3
    UNION ALL SELECT 'stormcharged', 2, 10, 4, 7
    UNION ALL SELECT 'frosted', 1, 1, 1, 3
    UNION ALL SELECT 'frosted', 2, 10, 4, 7
    UNION ALL SELECT 'venomous', 1, 1, 1, 3
    UNION ALL SELECT 'venomous', 2, 10, 4, 7
    UNION ALL SELECT 'bleeding', 1, 1, 1, 3
    UNION ALL SELECT 'bleeding', 2, 10, 4, 7
    UNION ALL SELECT 'scorching', 1, 1, 1, 3
    UNION ALL SELECT 'scorching', 2, 10, 4, 7
    UNION ALL SELECT 'freezing', 1, 1, 1, 3
    UNION ALL SELECT 'freezing', 2, 10, 4, 7
    UNION ALL SELECT 'shocking', 1, 1, 1, 3
    UNION ALL SELECT 'shocking', 2, 10, 4, 7
    UNION ALL SELECT 'of_evasion', 1, 1, 1, 3
    UNION ALL SELECT 'of_evasion', 2, 10, 4, 7
    UNION ALL SELECT 'of_guarding', 1, 1, 1000, 2500
    UNION ALL SELECT 'of_guarding', 2, 10, 2501, 5000
    UNION ALL SELECT 'of_storm_warding', 1, 1, 1, 3
    UNION ALL SELECT 'of_storm_warding', 2, 10, 4, 7
    UNION ALL SELECT 'of_poison_warding', 1, 1, 1, 3
    UNION ALL SELECT 'of_poison_warding', 2, 10, 4, 7
),
expected_item_affix_category_rules AS (
    SELECT 'keen' AS affix_key, 'weapon' AS category
    UNION ALL SELECT 'keen', 'jewellery'
    UNION ALL SELECT 'deadly', 'weapon'
    UNION ALL SELECT 'deadly', 'jewellery'
    UNION ALL SELECT 'savage', 'weapon'
    UNION ALL SELECT 'savage', 'jewellery'
    UNION ALL SELECT 'vampiric', 'weapon'
    UNION ALL SELECT 'vampiric', 'jewellery'
    UNION ALL SELECT 'flaming', 'weapon'
    UNION ALL SELECT 'flaming', 'jewellery'
    UNION ALL SELECT 'stormcharged', 'weapon'
    UNION ALL SELECT 'stormcharged', 'jewellery'
    UNION ALL SELECT 'frosted', 'weapon'
    UNION ALL SELECT 'frosted', 'jewellery'
    UNION ALL SELECT 'venomous', 'weapon'
    UNION ALL SELECT 'venomous', 'jewellery'
    UNION ALL SELECT 'bleeding', 'weapon'
    UNION ALL SELECT 'bleeding', 'jewellery'
    UNION ALL SELECT 'scorching', 'weapon'
    UNION ALL SELECT 'scorching', 'jewellery'
    UNION ALL SELECT 'freezing', 'weapon'
    UNION ALL SELECT 'freezing', 'jewellery'
    UNION ALL SELECT 'shocking', 'weapon'
    UNION ALL SELECT 'shocking', 'jewellery'
    UNION ALL SELECT 'of_evasion', 'armour'
    UNION ALL SELECT 'of_evasion', 'jewellery'
    UNION ALL SELECT 'of_guarding', 'off_hand'
    UNION ALL SELECT 'of_storm_warding', 'armour'
    UNION ALL SELECT 'of_storm_warding', 'off_hand'
    UNION ALL SELECT 'of_storm_warding', 'jewellery'
    UNION ALL SELECT 'of_poison_warding', 'armour'
    UNION ALL SELECT 'of_poison_warding', 'off_hand'
    UNION ALL SELECT 'of_poison_warding', 'jewellery'
)
SELECT 'missing migration 010' AS violation WHERE NOT EXISTS (SELECT 1 FROM schema_migrations WHERE migration_id = '010_combat_item_affix_completion')
UNION ALL
SELECT 'missing prerequisite 009' AS violation WHERE NOT EXISTS (SELECT 1 FROM schema_migrations WHERE migration_id = '009_combat_status_effects')
UNION ALL
SELECT CONCAT('item_definitions mismatch: ', expected.definition_key)
  FROM expected_item_definitions AS expected
 WHERE NOT EXISTS (
    SELECT 1 FROM item_definitions AS actual
     WHERE actual.definition_key <=> expected.definition_key
       AND actual.display_name <=> expected.display_name
       AND actual.category <=> expected.category
       AND actual.subtype <=> expected.subtype
       AND actual.equipment_slot <=> expected.equipment_slot
       AND actual.damage_type <=> expected.damage_type
       AND actual.base_damage_min <=> expected.base_damage_min
       AND actual.base_damage_max <=> expected.base_damage_max
       AND actual.base_toughness <=> expected.base_toughness
       AND actual.minimum_item_level <=> expected.minimum_item_level
       AND actual.maximum_rarity <=> expected.maximum_rarity
       AND actual.glyph <=> expected.glyph
       AND actual.loot_weight <=> expected.loot_weight
       AND actual.is_active = 1)
UNION ALL
SELECT CONCAT('item_affix_definitions mismatch: ', expected.affix_key)
  FROM expected_item_affix_definitions AS expected
 WHERE NOT EXISTS (
    SELECT 1 FROM item_affix_definitions AS actual
     WHERE actual.affix_key <=> expected.affix_key
       AND actual.family_key <=> expected.family_key
       AND actual.position <=> expected.position
       AND actual.display_fragment <=> expected.display_fragment
       AND actual.modifier_type <=> expected.modifier_type
       AND actual.modifier_operation <=> expected.modifier_operation
       AND actual.minimum_item_level <=> expected.minimum_item_level
       AND actual.selection_weight <=> expected.selection_weight
       AND actual.is_active = 1)
UNION ALL
SELECT CONCAT('item_affix_tiers mismatch: ', expected.affix_key)
  FROM expected_item_affix_tiers AS expected
 WHERE NOT EXISTS (
    SELECT 1 FROM item_affix_tiers AS actual
     WHERE actual.affix_key <=> expected.affix_key
       AND actual.tier <=> expected.tier
       AND actual.minimum_item_level <=> expected.minimum_item_level
       AND actual.minimum_value <=> expected.minimum_value
       AND actual.maximum_value <=> expected.maximum_value)
UNION ALL
SELECT CONCAT('item_affix_category_rules mismatch: ', expected.affix_key)
  FROM expected_item_affix_category_rules AS expected
 WHERE NOT EXISTS (
    SELECT 1 FROM item_affix_category_rules AS actual
     WHERE actual.affix_key <=> expected.affix_key
       AND actual.category <=> expected.category)
UNION ALL
SELECT CONCAT('unexpected item_affix_tiers: ', actual.affix_key)
  FROM item_affix_tiers AS actual
  INNER JOIN expected_item_affix_definitions AS a ON a.affix_key = actual.affix_key
 WHERE NOT EXISTS (
    SELECT 1 FROM expected_item_affix_tiers AS expected
     WHERE actual.affix_key <=> expected.affix_key
       AND actual.tier <=> expected.tier
       AND actual.minimum_item_level <=> expected.minimum_item_level
       AND actual.minimum_value <=> expected.minimum_value
       AND actual.maximum_value <=> expected.maximum_value)
UNION ALL
SELECT CONCAT('unexpected item_affix_category_rules: ', actual.affix_key)
  FROM item_affix_category_rules AS actual
  INNER JOIN expected_item_affix_definitions AS a ON a.affix_key = actual.affix_key
 WHERE NOT EXISTS (
    SELECT 1 FROM expected_item_affix_category_rules AS expected
     WHERE actual.affix_key <=> expected.affix_key
       AND actual.category <=> expected.category)
UNION ALL
SELECT CONCAT('missing family: ', d.definition_key, '/', a.family_key)
  FROM item_definitions AS d
  INNER JOIN item_affix_category_rules AS r ON r.category = d.category
  INNER JOIN item_affix_definitions AS a ON a.affix_key = r.affix_key
 WHERE d.loot_weight > 0 AND NOT EXISTS (
    SELECT 1 FROM item_definition_affix_families AS f
     WHERE f.definition_key = d.definition_key AND f.family_key = a.family_key)
UNION ALL
SELECT CONCAT('invalid new family: ', f.definition_key, '/', f.family_key)
  FROM item_definition_affix_families AS f
  INNER JOIN item_definitions AS d ON d.definition_key = f.definition_key
  INNER JOIN expected_item_affix_definitions AS a ON a.family_key = f.family_key
 WHERE NOT EXISTS (
    SELECT 1 FROM expected_item_affix_category_rules AS r
     WHERE r.affix_key = a.affix_key AND r.category = d.category);
