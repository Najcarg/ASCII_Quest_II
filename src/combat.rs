use rand::Rng;
use crate::entities::{Player, Rarity};
use crate::loot::{handle_monster_death, generate_item_drop};
use crossterm::{
    event::{self, Event, KeyCode},
    terminal::{self, Clear, ClearType},
    cursor,
    ExecutableCommand,
};
use std::io::{self, Write};

// ... (previous Monster and MonsterTier structs remain the same)

pub struct Battle {
    pub player: Player,
    pub monster: Monster,
    pub battle_over: bool,
    pub dice_sides: i32,
    pub battle_log: Vec<String>,
}

// ... (Monster and MonsterTier implementations remain the same)

impl Battle {
    pub fn new(player: Player, monster: Monster) -> Self {
        let dice_sides = monster.tier.dice_sides();

        Battle {
            player,
            monster,
            battle_over: false,
            dice_sides,
            battle_log: Vec::new(),
        }
    }

    pub fn run_battle(&mut self) -> io::Result<BattleResult> {
        self.log_message(format!("A wild {} appears ({:?})!", self.monster.name, self.monster.tier));

        while !self.battle_over {
            self.display_battle_screen()?;

            // Wait for player input
            if let Event::Key(event) = event::read()? {
                match event.code {
                    KeyCode::Char('a') | KeyCode::Enter => {
                        self.do_attack_round();
                    },
                    KeyCode::Char('r') => {
                        // Attempt to run
                        if rand::thread_rng().gen_bool(0.5) {
                            self.log_message("You successfully ran away!".to_string());
                            return Ok(BattleResult::Fled);
                        } else {
                            self.log_message("You failed to run away!".to_string());
                            self.monster_attack();
                        }
                    },
                    KeyCode::Char('q') => {
                        return Ok(BattleResult::Fled);
                    },
                    _ => {}
                }
            }
        }

        self.display_battle_screen()?;
        Ok(if self.player.hp <= 0 {
            BattleResult::PlayerDefeated
        } else {
            BattleResult::MonsterDefeated(self.monster.xp_reward, self.monster.gold_min, self.monster.gold_max)
        })
    }

    // ... (display_battle_screen remains the same)

    fn do_attack_round(&mut self) {
        if self.battle_over {
            return;
        }

        // Player attacks monster
        self.player_attack();

        if self.monster.hp <= 0 {
            self.monster.hp = 0;
            self.battle_over = true;
            self.log_message(format!("{} is defeated!", self.monster.name));
            return;
        }

        // Monster attacks player
        if !self.battle_over {
            self.monster_attack();
        }
    }

    // ... (player_attack and monster_attack remain the same)

    fn log_message(&mut self, message: String) {
        self.battle_log.push(message);
    }
}

pub enum BattleResult {
    MonsterDefeated(i32, i32, i32), // XP reward, gold min, gold max
    PlayerDefeated,
    Fled,
}

// ... (Monster implementation remains the same)
