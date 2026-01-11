use serde::{Deserialize, Serialize};
use std::collections::HashMap;

/// Central character stats manager - single source of truth
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct CharacterStats {
    // ---- Current character identity ----
    pub character_id: i32,
    pub character_name: String,
    pub class_id: i32,
    pub class_name: String,
    pub player_glyph: char,

    // ---- Location / position ----
    pub location_id: i32,
    pub location_name: String,
    pub pos_x: i32,
    pub pos_y: i32,

    // ---- Progress ----
    pub level: i32,
    pub xp: i64,
    pub gold: i64,

    // ---- Core stats ----
    pub max_hp: i32,
    pub hp: i32,
    pub max_mana: i32,
    pub mana: i32,
    pub attack: i32,
    pub defense: i32,

    // ---- Extra stats ----
    pub crit_damage: i32,
    pub crit_chance: i32,
    pub attack_count: i32,
    pub dodge: i32,

    pub life_on_hit: i32,
    pub mana_per_min: i32,
    pub mana_on_hit: i32,
    pub bonus_xp_on_kill: i32,
    pub gold_find: i32,

    // --- Regen / step stats ---
    pub heal_per_step: i32,
    pub mana_per_step: i32,
}

impl CharacterStats {
    pub fn new() -> Self {
        CharacterStats {
            character_id: 0,
            character_name: String::new(),
            class_id: 0,
            class_name: String::new(),
            player_glyph: '@',
            location_id: 1,
            location_name: String::new(),
            pos_x: 2,
            pos_y: 2,
            level: 1,
            xp: 0,
            gold: 0,
            max_hp: 20,
            hp: 20,
            max_mana: 10,
            mana: 10,
            attack: 5,
            defense: 3,
            crit_damage: 0,
            crit_chance: 0,
            attack_count: 1,
            dodge: 0,
            life_on_hit: 0,
            mana_per_min: 0,
            mana_on_hit: 0,
            bonus_xp_on_kill: 0,
            gold_find: 0,
            heal_per_step: 0,
            mana_per_step: 0,
        }
    }

    /// Recalculates derived stats from base stats and equipment
    pub fn recalculate_derived_stats(&mut self, class_base: &ClassStats, equipment: &[ItemStats]) {
        // Preserve current resources
        let current_hp = self.hp;
        let current_mana = self.mana;

        // Reset derived stats
        self.reset_derived_stats();

        // Apply class and level stats
        self.apply_class_and_level_stats(class_base);

        // Apply equipment stats
        self.apply_equipment_stats(equipment);

        // Restore current resources (clamped)
        self.hp = current_hp.min(self.max_hp).max(0);
        self.mana = current_mana.min(self.max_mana).max(0);
    }

    /// Resets only derived combat stats, preserving identity/location
    fn reset_derived_stats(&mut self) {
        self.max_hp = 0;
        self.max_mana = 0;
        self.attack = 0;
        self.defense = 0;

        self.crit_damage = 0;
        self.crit_chance = 0;
        self.attack_count = 1;
        self.dodge = 0;

        self.heal_per_step = 0;
        self.mana_per_step = 0;

        self.life_on_hit = 0;
        self.mana_per_min = 0;
        self.mana_on_hit = 0;
        self.bonus_xp_on_kill = 0;
        self.gold_find = 0;
    }

    /// Applies class base stats scaled by level
    fn apply_class_and_level_stats(&mut self, class: &ClassStats) {
        let level = self.level.max(1);
        let level_multiplier = (level - 1) as i32;

        // Core stats (scaled by level)
        self.max_hp = class.base_hp + level_multiplier * class.hp_per_level;
        self.max_mana = class.base_mana + level_multiplier * class.mana_per_level;
        self.attack = class.base_attack + level_multiplier * class.attack_per_level;
        self.defense = class.base_defense + level_multiplier * class.defense_per_level;
        self.dodge = class.base_dodge + level_multiplier * class.dodge_per_level;

        // Extra stats (mostly base-only)
        self.crit_damage = class.base_crit_damage;
        self.crit_chance = class.base_crit_chance;
        self.attack_count = class.base_attack_count.max(1);
        self.heal_per_step = class.base_heal_per_step;
        self.life_on_hit = class.base_life_on_hit;
        self.mana_per_min = class.base_mana_per_min;
        self.mana_on_hit = class.base_mana_on_hit;
        self.bonus_xp_on_kill = class.base_bonus_xp_on_kill;
        self.gold_find = class.base_gold_find;

        // Safety defaults
        if self.attack_count < 1 {
            self.attack_count = 1;
        }
        if self.max_hp < 1 {
            self.max_hp = 1;
        }
        if self.max_mana < 0 {
            self.max_mana = 0;
        }
    }

    /// Applies bonuses from equipped items
    fn apply_equipment_stats(&mut self, equipment: &[ItemStats]) {
        for item in equipment {
            self.attack += item.total_attack;
            self.defense += item.total_defense;
            self.max_hp += item.total_hp;
            self.max_mana += item.total_mana;
            // Add other item stat bonuses as needed
        }

        // Safety checks
        if self.attack_count < 1 {
            self.attack_count = 1;
        }
        if self.max_hp < 1 {
            self.max_hp = 1;
        }
    }

    /// Clamps resources to valid ranges
    pub fn clamp_resources(&mut self) {
        self.hp = self.hp.clamp(0, self.max_hp);
        self.mana = self.mana.clamp(0, self.max_mana);
    }

    /// Heals character to full health and mana
    pub fn heal_to_full(&mut self) {
        self.hp = self.max_hp;
        self.mana = self.max_mana;
    }

    /// Gains gold
    pub fn gain_gold(&mut self, amount: i64) {
        self.gold += amount;
    }

    /// Gains experience
    pub fn gain_xp(&mut self, amount: i64) {
        self.xp += amount;
    }

    /// Checks if character is dead
    pub fn is_dead(&self) -> bool {
        self.hp <= 0
    }

    /// Saves current position only
    pub fn save_position_only(&mut self, new_location_id: i32, new_pos_x: i32, new_pos_y: i32) {
        self.location_id = new_location_id;
        self.pos_x = new_pos_x;
        self.pos_y = new_pos_y;
    }

    /// Saves core character stats
    pub fn save_core_stats(&self) -> String {
        format!(
            "UPDATE tblCharacters SET \
            [Level]={}, [Experience]={}, [Gold]={}, \
            [MaxHP]={}, [CurrentHP]={}, [MaxMana]={}, [Mana]={}, \
            [Attack]={}, [Defense]={}, \
            [CritDamage]={}, [CritChance]={}, [AttackCount]={}, [Dodge]={}, \
            [HealPerStep]={}, [LifeOnHit]={}, [ManaPerMin]={}, [ManaOnHit]={}, \
            [BonusXPOnKill]={}, [GoldFind]={}, \
            [LocationID]={}, [PosX]={}, [PosY]={}, [LastActive]=Now() \
            WHERE [CharacterID]={};",
            self.level, self.xp, self.gold,
            self.max_hp, self.hp, self.max_mana, self.mana,
            self.attack, self.defense,
            self.crit_damage, self.crit_chance, self.attack_count, self.dodge,
            self.heal_per_step, self.life_on_hit, self.mana_per_min, self.mana_on_hit,
            self.bonus_xp_on_kill, self.gold_find,
            self.location_id, self.pos_x, self.pos_y, self.character_id
        )
    }
}

impl Default for CharacterStats {
    fn default() -> Self {
        Self::new()
    }
}

/// Class base stats definition
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ClassStats {
    pub class_id: i32,
    pub class_name: String,

    // Base stats
    pub base_hp: i32,
    pub base_mana: i32,
    pub base_attack: i32,
    pub base_defense: i32,
    pub base_dodge: i32,

    // Per level scaling
    pub hp_per_level: i32,
    pub mana_per_level: i32,
    pub attack_per_level: i32,
    pub defense_per_level: i32,
    pub dodge_per_level: i32,

    // Extra stats
    pub base_crit_damage: i32,
    pub base_crit_chance: i32,
    pub base_attack_count: i32,
    pub base_heal_per_step: i32,
    pub base_life_on_hit: i32,
    pub base_mana_per_min: i32,
    pub base_mana_on_hit: i32,
    pub base_bonus_xp_on_kill: i32,
    pub base_gold_find: i32,
}

impl ClassStats {
    pub fn new() -> Self {
        ClassStats {
            class_id: 0,
            class_name: String::new(),
            base_hp: 20,
            base_mana: 10,
            base_attack: 5,
            base_defense: 3,
            base_dodge: 0,
            hp_per_level: 5,
            mana_per_level: 3,
            attack_per_level: 2,
            defense_per_level: 1,
            dodge_per_level: 0,
            base_crit_damage: 0,
            base_crit_chance: 0,
            base_attack_count: 1,
            base_heal_per_step: 0,
            base_life_on_hit: 0,
            base_mana_per_min: 0,
            base_mana_on_hit: 0,
            base_bonus_xp_on_kill: 0,
            base_gold_find: 0,
        }
    }
}

/// Item stats for equipment
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ItemStats {
    pub item_instance_id: i32,
    pub total_attack: i32,
    pub total_defense: i32,
    pub total_hp: i32,
    pub total_mana: i32,
    // Add other item stat fields as needed
}

impl ItemStats {
    pub fn new() -> Self {
        ItemStats {
            item_instance_id: 0,
            total_attack: 0,
            total_defense: 0,
            total_hp: 0,
            total_mana: 0,
        }
    }
}

/// Global stats manager - equivalent to your modStats module
pub struct StatsManager {
    pub current_character: CharacterStats,
    pub classes: HashMap<i32, ClassStats>,
    pub equipment: Vec<ItemStats>,
}

impl StatsManager {
    pub fn new() -> Self {
        StatsManager {
            current_character: CharacterStats::new(),
            classes: HashMap::new(),
            equipment: Vec::new(),
        }
    }

    /// Loads character stats from storage (simulated)
    pub fn load_character_stats(&mut self, character_id: i32) {
        self.current_character.character_id = character_id;
        // In a real implementation, this would load from database
        // For now, we'll simulate with default values

        // Load class data if available
        if let Some(class_stats) = self.classes.get(&self.current_character.class_id) {
            self.current_character.recalculate_derived_stats(class_stats, &self.equipment);
        }
    }

    /// Rebuilds stats from class and level (for character creation or level up)
    pub fn rebuild_stats_from_class_and_level(&mut self) {
        if let Some(class_stats) = self.classes.get(&self.current_character.class_id) {
            let current_hp = self.current_character.hp;
            let current_mana = self.current_character.mana;

            self.current_character.apply_class_and_level_stats(class_stats);

            // Full heal after rebuild
            self.current_character.heal_to_full();
        }
    }

    /// Updates character position
    pub fn update_position(&mut self, location_id: i32, pos_x: i32, pos_y: i32) {
        self.current_character.save_position_only(location_id, pos_x, pos_y);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_character_creation() {
        let mut stats = CharacterStats::new();
        assert_eq!(stats.level, 1);
        assert_eq!(stats.hp, 20);
        assert_eq!(stats.mana, 10);
    }

    #[test]
    fn test_recalculation() {
        let mut stats = CharacterStats::new();
        let class = ClassStats::new();
        let equipment = vec![ItemStats::new()];

        stats.recalculate_derived_stats(&class, &equipment);
        assert!(stats.max_hp > 0);
    }

    #[test]
    fn test_resource_clamping() {
        let mut stats = CharacterStats::new();
        stats.hp = 1000; // Way above max
        stats.mana = -50; // Below zero

        stats.clamp_resources();
        assert_eq!(stats.hp, stats.max_hp);
        assert_eq!(stats.mana, 0);
    }

    #[test]
    fn test_stats_manager() {
        let mut manager = StatsManager::new();
        assert_eq!(manager.current_character.character_id, 0);
    }
}
