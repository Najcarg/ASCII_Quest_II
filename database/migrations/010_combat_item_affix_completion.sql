/* Task31 catalogue-only expansion. Migration010 NOT APPLIED by development.
   No historical instances, starter definitions, weights, or combat formulas change. */
USE ascii_quest;
DROP PROCEDURE IF EXISTS run_010_combat_item_affix_completion;
DELIMITER $$
CREATE PROCEDURE run_010_combat_item_affix_completion()
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SELECT COUNT(*) INTO v_count FROM schema_migrations
     WHERE migration_id = '009_combat_status_effects';
    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 010 requires migration 009';
    END IF;
    SELECT COUNT(*) INTO v_count FROM schema_migrations
     WHERE migration_id = '010_combat_item_affix_completion';
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 010 is already applied';
    END IF;

    -- Detect collisions/partial installs instead of ignoring or overwriting them.
    SELECT
        (SELECT COUNT(*) FROM item_definitions WHERE definition_key IN ('worn_sword', 'worn_dagger', 'worn_mace', 'ash_wand', 'hide_gloves', 'hide_boots', 'hide_belt', 'copper_amulet', 'copper_charm')) +
        (SELECT COUNT(*) FROM item_affix_definitions WHERE affix_key IN ('keen', 'deadly', 'savage', 'vampiric', 'flaming', 'stormcharged', 'frosted', 'venomous', 'bleeding', 'scorching', 'freezing', 'shocking', 'of_evasion', 'of_guarding', 'of_storm_warding', 'of_poison_warding')) +
        (SELECT COUNT(*) FROM item_affix_tiers WHERE affix_key IN ('keen', 'deadly', 'savage', 'vampiric', 'flaming', 'stormcharged', 'frosted', 'venomous', 'bleeding', 'scorching', 'freezing', 'shocking', 'of_evasion', 'of_guarding', 'of_storm_warding', 'of_poison_warding')) +
        (SELECT COUNT(*) FROM item_affix_category_rules WHERE affix_key IN ('keen', 'deadly', 'savage', 'vampiric', 'flaming', 'stormcharged', 'frosted', 'venomous', 'bleeding', 'scorching', 'freezing', 'shocking', 'of_evasion', 'of_guarding', 'of_storm_warding', 'of_poison_warding')) +
        (SELECT COUNT(*) FROM item_definition_affix_families WHERE family_key IN ('accuracy', 'critical_chance', 'critical_damage', 'life_on_hit', 'fire_damage', 'lightning_damage', 'cold_damage', 'poison_damage', 'bleed_damage', 'burn_damage', 'freeze_damage', 'shock_damage', 'dodging', 'block_rate', 'lightning_resistance', 'poison_resistance'))
      INTO v_count;
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 010 partial install or catalogue collision';
    END IF;

    START TRANSACTION;
    INSERT INTO item_definitions (definition_key, display_name, category, subtype, equipment_slot, damage_type, base_damage_min, base_damage_max, base_toughness, minimum_item_level, maximum_rarity, glyph, loot_weight) VALUES
        ('worn_sword', 'Sword', 'weapon', 'sword', 'weapon', 'physical', 4, 7, 0, 1, 'rare', '/', 100),
        ('worn_dagger', 'Dagger', 'weapon', 'dagger', 'weapon', 'physical', 3, 5, 0, 1, 'rare', '/', 100),
        ('worn_mace', 'Mace', 'weapon', 'mace', 'weapon', 'physical', 5, 7, 0, 1, 'rare', '/', 100),
        ('ash_wand', 'Wand', 'weapon', 'wand', 'weapon', 'fire', 3, 6, 0, 1, 'rare', '/', 100),
        ('hide_gloves', 'Hide Gloves', 'armour', 'gloves', 'gloves', NULL, 0, 0, 2, 1, 'rare', 'G', 100),
        ('hide_boots', 'Hide Boots', 'armour', 'boots', 'boots', NULL, 0, 0, 2, 1, 'rare', 'B', 100),
        ('hide_belt', 'Hide Belt', 'armour', 'belt', 'belt', NULL, 0, 0, 3, 1, 'rare', '=', 100),
        ('copper_amulet', 'Copper Amulet', 'jewellery', 'amulet', 'amulet', NULL, 0, 0, 0, 1, 'rare', 'o', 100),
        ('copper_charm', 'Copper Charm', 'jewellery', 'charm', 'charm', NULL, 0, 0, 0, 1, 'rare', '*', 100);

    INSERT INTO item_affix_definitions (affix_key, family_key, position, display_fragment, modifier_type, modifier_operation, minimum_item_level, selection_weight) VALUES
        ('keen', 'accuracy', 'prefix', 'Keen', 'accuracy', 'flat', 1, 100),
        ('deadly', 'critical_chance', 'prefix', 'Deadly', 'critical_chance', 'flat', 1, 100),
        ('savage', 'critical_damage', 'prefix', 'Savage', 'critical_damage', 'flat', 1, 100),
        ('vampiric', 'life_on_hit', 'prefix', 'Vampiric', 'life_on_hit', 'flat', 1, 100),
        ('flaming', 'fire_damage', 'prefix', 'Flaming', 'fire_damage', 'flat', 1, 100),
        ('stormcharged', 'lightning_damage', 'prefix', 'Stormcharged', 'lightning_damage', 'flat', 1, 100),
        ('frosted', 'cold_damage', 'prefix', 'Frosted', 'cold_damage', 'flat', 1, 100),
        ('venomous', 'poison_damage', 'prefix', 'Venomous', 'poison_damage', 'flat', 1, 100),
        ('bleeding', 'bleed_damage', 'prefix', 'Bleeding', 'bleed_damage', 'flat', 1, 100),
        ('scorching', 'burn_damage', 'prefix', 'Scorching', 'burn_damage', 'flat', 1, 100),
        ('freezing', 'freeze_damage', 'prefix', 'Freezing', 'freeze_damage', 'flat', 1, 100),
        ('shocking', 'shock_damage', 'prefix', 'Shocking', 'shock_damage', 'flat', 1, 100),
        ('of_evasion', 'dodging', 'suffix', 'of Evasion', 'dodging', 'flat', 1, 100),
        ('of_guarding', 'block_rate', 'suffix', 'of Guarding', 'block_rate_bp', 'additive_percent', 1, 100),
        ('of_storm_warding', 'lightning_resistance', 'suffix', 'of Storm Warding', 'lightning_resistance', 'flat', 1, 100),
        ('of_poison_warding', 'poison_resistance', 'suffix', 'of Poison Warding', 'poison_resistance', 'flat', 1, 100);

    INSERT INTO item_affix_tiers (affix_key, tier, minimum_item_level, minimum_value, maximum_value) VALUES
        ('keen', 1, 1, 1, 3),
        ('keen', 2, 10, 4, 7),
        ('deadly', 1, 1, 1, 3),
        ('deadly', 2, 10, 4, 7),
        ('savage', 1, 1, 5, 10),
        ('savage', 2, 10, 11, 20),
        ('vampiric', 1, 1, 1, 3),
        ('vampiric', 2, 10, 4, 7),
        ('flaming', 1, 1, 1, 3),
        ('flaming', 2, 10, 4, 7),
        ('stormcharged', 1, 1, 1, 3),
        ('stormcharged', 2, 10, 4, 7),
        ('frosted', 1, 1, 1, 3),
        ('frosted', 2, 10, 4, 7),
        ('venomous', 1, 1, 1, 3),
        ('venomous', 2, 10, 4, 7),
        ('bleeding', 1, 1, 1, 3),
        ('bleeding', 2, 10, 4, 7),
        ('scorching', 1, 1, 1, 3),
        ('scorching', 2, 10, 4, 7),
        ('freezing', 1, 1, 1, 3),
        ('freezing', 2, 10, 4, 7),
        ('shocking', 1, 1, 1, 3),
        ('shocking', 2, 10, 4, 7),
        ('of_evasion', 1, 1, 1, 3),
        ('of_evasion', 2, 10, 4, 7),
        ('of_guarding', 1, 1, 1000, 2500),
        ('of_guarding', 2, 10, 2501, 5000),
        ('of_storm_warding', 1, 1, 1, 3),
        ('of_storm_warding', 2, 10, 4, 7),
        ('of_poison_warding', 1, 1, 1, 3),
        ('of_poison_warding', 2, 10, 4, 7);

    INSERT INTO item_affix_category_rules (affix_key, category) VALUES
        ('keen', 'weapon'),
        ('keen', 'jewellery'),
        ('deadly', 'weapon'),
        ('deadly', 'jewellery'),
        ('savage', 'weapon'),
        ('savage', 'jewellery'),
        ('vampiric', 'weapon'),
        ('vampiric', 'jewellery'),
        ('flaming', 'weapon'),
        ('flaming', 'jewellery'),
        ('stormcharged', 'weapon'),
        ('stormcharged', 'jewellery'),
        ('frosted', 'weapon'),
        ('frosted', 'jewellery'),
        ('venomous', 'weapon'),
        ('venomous', 'jewellery'),
        ('bleeding', 'weapon'),
        ('bleeding', 'jewellery'),
        ('scorching', 'weapon'),
        ('scorching', 'jewellery'),
        ('freezing', 'weapon'),
        ('freezing', 'jewellery'),
        ('shocking', 'weapon'),
        ('shocking', 'jewellery'),
        ('of_evasion', 'armour'),
        ('of_evasion', 'jewellery'),
        ('of_guarding', 'off_hand'),
        ('of_storm_warding', 'armour'),
        ('of_storm_warding', 'off_hand'),
        ('of_storm_warding', 'jewellery'),
        ('of_poison_warding', 'armour'),
        ('of_poison_warding', 'off_hand'),
        ('of_poison_warding', 'jewellery');

    -- Include every existing and new loot definition, retain every old mapping.
    -- Category rules are authoritative; NOT EXISTS only prevents duplicate pairs.
    INSERT INTO item_definition_affix_families (definition_key, family_key)
    SELECT DISTINCT definitions.definition_key, affixes.family_key
      FROM item_definitions AS definitions
      INNER JOIN item_affix_category_rules AS rules ON rules.category = definitions.category
      INNER JOIN item_affix_definitions AS affixes ON affixes.affix_key = rules.affix_key
     WHERE definitions.loot_weight > 0
       AND NOT EXISTS (
           SELECT 1 FROM item_definition_affix_families AS existing
            WHERE existing.definition_key = definitions.definition_key
              AND existing.family_key = affixes.family_key
       );

    INSERT INTO schema_migrations (migration_id) VALUES ('010_combat_item_affix_completion');
    COMMIT;
END$$
DELIMITER ;
CALL run_010_combat_item_affix_completion();
DROP PROCEDURE run_010_combat_item_affix_completion;
