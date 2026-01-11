use crate::engine::{Engine, GameState};
use crate::entities::{Player, Item, Rarity};

pub struct Game {
    pub engine: Engine,
    pub player: Player,
    pub inventory: Vec<Item>,
}

impl Game {
    pub fn new() -> Game {
        let engine = Engine::new().expect("Failed to create engine");
        let player = Player::new("Hero".to_string());
        let inventory = Vec::new();

        Game {
            engine,
            player,
            inventory,
        }
    }

    pub fn init_location(&mut self, location_id: i32) {
        // Initialize map based on location
        self.engine.game_state.location_id = location_id;

        // Set up some sample walls
        for x in 0..self.engine.game_state.map_width {
            self.engine.game_state.map_chars[0][x as usize] = '#';
            self.engine.game_state.map_walkable[0][x as usize] = false;
            self.engine.game_state.map_chars[(self.engine.game_state.map_height-1) as usize][x as usize] = '#';
            self.engine.game_state.map_walkable[(self.engine.game_state.map_height-1) as usize][x as usize] = false;
        }

        for y in 0..self.engine.game_state.map_height {
            self.engine.game_state.map_chars[y as usize][0] = '#';
            self.engine.game_state.map_walkable[y as usize][0] = false;
            self.engine.game_state.map_chars[y as usize][(self.engine.game_state.map_width-1) as usize] = '#';
            self.engine.game_state.map_walkable[y as usize][(self.engine.game_state.map_width-1) as usize] = false;
        }

        // Add some sample monsters
        self.engine.game_state.monsters.clear();
        self.engine.game_state.monsters.push(crate::engine::Monster {
            x: 5,
            y: 5,
            glyph: 'M',
            hp: 10,
            alive: true,
            instance_id: 1,
        });

        // Add some sample corpses
        self.engine.game_state.corpses.clear();
        self.engine.game_state.corpses.push(crate::engine::Corpse {
            x: 8,
            y: 8,
            id: 1,
            gold: 50,
            xp: 25,
        });

        // Position player
        self.engine.game_state.player_x = 1;
        self.engine.game_state.player_y = 1;
    }

    pub fn update_hud(&self) {
        // In a real implementation, this would update the HUD display
        println!("HP: {}/{} | Mana: {}/{} | Gold: {} | XP: {}",
                 self.player.hp, self.player.max_hp,
                 self.player.mana, self.player.max_mana,
                 self.player.gold, self.player.experience);
    }
}
