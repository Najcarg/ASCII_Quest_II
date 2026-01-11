use crate::entities::{Item, Rarity, Player, Inventory};
use crossterm::{
    event::{self, Event, KeyCode},
    terminal::{self, Clear, ClearType},
    cursor,
    ExecutableCommand,
};
use std::io::{self, Write};

pub struct LootScreen {
    pub monster_name: String,
    pub xp_reward: i32,
    pub gold_reward: i32,
    pub dropped_item: Option<Item>,
    pub player_inventory_full: bool,
}

impl LootScreen {
    pub fn new(monster_name: String, xp_reward: i32, gold_reward: i32, dropped_item: Option<Item>, inventory_full: bool) -> Self {
        LootScreen {
            monster_name,
            xp_reward,
            gold_reward,
            dropped_item,
            player_inventory_full: inventory_full,
        }
    }

    pub fn show_loot_screen(&self) -> io::Result<LootAction> {
        loop {
            self.display_loot_screen()?;

            if let Event::Key(event) = event::read()? {
                match event.code {
                    KeyCode::Char('t') | KeyCode::Char('T') => {
                        if self.dropped_item.is_some() {
                            if self.player_inventory_full {
                                println!("Your inventory is full! Press any key to continue...");
                                event::read()?;
                                continue;
                            }
                            return Ok(LootAction::Take);
                        } else {
                            return Ok(LootAction::Continue);
                        }
                    },
                    KeyCode::Char('d') | KeyCode::Char('D') => {
                        if self.dropped_item.is_some() {
                            return Ok(LootAction::Destroy);
                        }
                        return Ok(LootAction::Continue);
                    },
                    KeyCode::Char('c') | KeyCode::Char('C') => {
                        return Ok(LootAction::Continue);
                    },
                    KeyCode::Char('q') | KeyCode::Char('Q') => {
                        return Ok(LootAction::Continue);
                    },
                    _ => {}
                }
            }
        }
    }

    fn display_loot_screen(&self) -> io::Result<()> {
        // Clear screen
        terminal::enable_raw_mode()?;
        io::stdout().execute(cursor::MoveTo(0, 0))?;
        io::stdout().execute(Clear(ClearType::All))?;

        // Display header
        println!("=== LOOT SCREEN ===");
        println!("Defeated: {}", self.monster_name);
        println!("XP Reward: {}", self.xp_reward);
        println!("Gold Reward: {}", self.gold_reward);
        println!();

        // Display item info if dropped
        if let Some(ref item) = self.dropped_item {
            println!("Dropped Item:");
            println!("Name: {}", item.name);
            println!("Rarity: {:?}", item.rarity);
            println!("Stats:");
            if item.attack_bonus > 0 {
                println!("  ATK: +{}", item.attack_bonus);
            }
            if item.defense_bonus > 0 {
                println!("  DEF: +{}", item.defense_bonus);
            }
            if item.hp_bonus > 0 {
                println!("  HP: +{}", item.hp_bonus);
            }
            if item.mana_bonus > 0 {
                println!("  Mana: +{}", item.mana_bonus);
            }
            println!();

            if self.player_inventory_full {
                println!("*** WARNING: Your inventory is full! ***");
                println!();
            }

            println!("[T]ake Item | [D]estroy Item | [C]ontinue");
        } else {
            println!("No item dropped.");
            println!();
            println!("[C]ontinue");
        }

        println!();
        println!("Press the corresponding key to make your choice.");

        io::stdout().flush()?;
        Ok(())
    }
}

pub enum LootAction {
    Take,
    Destroy,
    Continue,
}

// Function to handle the complete loot process
pub fn handle_monster_death(
    player: &mut Player,
    inventory: &mut Inventory,
    monster_name: String,
    xp_reward: i32,
    gold_reward: i32,
    possible_drop: Option<Item>,
) -> io::Result<()> {
    // Grant XP and gold immediately
    player.gain_experience(xp_reward);
    player.gain_gold(gold_reward);

    // Check if inventory is full
    let inventory_full = inventory.items.len() >= 20; // Assuming 20 item limit like in your VBA

    // Create loot screen
    let loot_screen = LootScreen::new(
        monster_name,
        xp_reward,
        gold_reward,
        possible_drop,
        inventory_full,
    );

    // Show loot screen and handle player choice
    match loot_screen.show_loot_screen()? {
        LootAction::Take => {
            if let Some(item) = loot_screen.dropped_item {
                if !inventory_full {
                    inventory.add_item(item);
                    println!("You took the item!");
                } else {
                    println!("Your inventory is full! You leave the item behind.");
                }
            }
        },
        LootAction::Destroy => {
            if loot_screen.dropped_item.is_some() {
                println!("You destroyed the item.");
            }
        },
        LootAction::Continue => {
            // Rewards already granted, just continue
        },
    }

    Ok(())
}

// Helper function to generate a random item drop
pub fn generate_item_drop(monster_tier: &crate::combat::MonsterTier) -> Option<Item> {
    use rand::Rng;

    // Determine drop chance based on tier
    let drop_chance = match monster_tier {
        crate::combat::MonsterTier::Normal => 0.2,
        crate::combat::MonsterTier::Magic => 0.4,
        crate::combat::MonsterTier::Elite => 0.6,
        crate::combat::MonsterTier::Boss => 0.9,
        crate::combat::MonsterTier::Unique => 1.0,
    };

    let mut rng = rand::thread_rng();
    if rng.gen::<f64>() > drop_chance {
        return None;
    }

    // Determine rarity
    let rarity_roll = rng.gen::<f64>();
    let rarity = if rarity_roll < 0.5 {
        Rarity::Normal
    } else if rarity_roll < 0.8 {
        Rarity::Magic
    } else if rarity_roll < 0.95 {
        Rarity::Rare
    } else {
        Rarity::Epic
    };

    // Generate item based on tier and rarity
    let (attack_bonus, defense_bonus, hp_bonus, mana_bonus) = match rarity {
        Rarity::Normal => (rng.gen_range(1..=3), rng.gen_range(0..=2), rng.gen_range(0..=5), rng.gen_range(0..=3)),
        Rarity::Magic => (rng.gen_range(2..=5), rng.gen_range(1..=3), rng.gen_range(2..=10), rng.gen_range(1..=5)),
        Rarity::Rare => (rng.gen_range(4..=7), rng.gen_range(2..=5), rng.gen_range(5..=15), rng.gen_range(3..=8)),
        Rarity::Epic => (rng.gen_range(6..=10), rng.gen_range(4..=8), rng.gen_range(10..=25), rng.gen_range(5..=15)),
    };

    Some(Item {
        id: rng.gen_range(1000..=9999), // Simple ID generation
        name: format!("{} Sword", rarity),
        glyph: '†',
        rarity,
        attack_bonus,
        defense_bonus,
        hp_bonus,
        mana_bonus,
    })
}
