use crate::stats::{CharacterStats, ClassStats, ItemStats};

pub struct Player {
    pub stats: CharacterStats,
}

impl Player {
    pub fn new(name: String) -> Self {
        let mut stats = CharacterStats::new();
        stats.character_name = name;
        Player { stats }
    }

    pub fn heal(&mut self, amount: i32) {
        self.stats.hp += amount;
        if self.stats.hp > self.stats.max_hp {
            self.stats.hp = self.stats.max_hp;
        }
    }

    pub fn take_damage(&mut self, amount: i32) {
        self.stats.hp -= amount;
        if self.stats.hp < 0 {
            self.stats.hp = 0;
        }
    }

    pub fn gain_gold(&mut self, amount: i64) {
        self.stats.gold += amount;
    }

    pub fn gain_experience(&mut self, amount: i64) {
        self.stats.xp += amount;
    }

    pub fn is_dead(&self) -> bool {
        self.stats.is_dead()
    }

    pub fn heal_to_full(&mut self) {
        self.stats.heal_to_full();
    }
}

pub struct Item {
    pub id: i32,
    pub name: String,
    pub glyph: char,
    pub rarity: Rarity,
    pub stats: ItemStats, // Now using the structured item stats
    pub attack_bonus: i32,
    pub defense_bonus: i32,
    pub hp_bonus: i32,
    pub mana_bonus: i32,
}

#[derive(Debug, Clone, Copy)]
pub enum Rarity {
    Normal,
    Magic,
    Rare,
    Epic,
}

impl Rarity {
    pub fn color_code(&self) -> &str {
        match self {
            Rarity::Normal => "\x1b[37m", // White
            Rarity::Magic => "\x1b[34m",  // Blue
            Rarity::Rare => "\x1b[33m",   // Yellow
            Rarity::Epic => "\x1b[35m",   // Magenta
        }
    }
}

pub struct Inventory {
    pub items: Vec<Item>,
}

impl Inventory {
    pub fn new() -> Self {
        Inventory {
            items: Vec::new(),
        }
    }

    pub fn add_item(&mut self, item: Item) {
        self.items.push(item);
    }

    pub fn remove_item(&mut self, item_id: i32) -> Option<Item> {
        if let Some(index) = self.items.iter().position(|item| item.id == item_id) {
            Some(self.items.remove(index))
        } else {
            None
        }
    }

    pub fn is_full(&self) -> bool {
        self.items.len() >= 20 // 20 item limit like in your VBA
    }
}
