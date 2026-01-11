pub mod renderer;
pub mod input;

use crossterm::{
    ExecutableCommand,
    terminal::{self, EnterAlternateScreen, LeaveAlternateScreen},
    event::{self, Event, KeyCode, KeyEvent},
};
use std::io::{self, Write};

pub struct Engine {
    pub game_state: GameState,
}

pub struct GameState {
    pub player_x: i32,
    pub player_y: i32,
    pub location_id: i32,
    pub map_width: i32,
    pub map_height: i32,
    pub map_chars: Vec<Vec<char>>,
    pub map_walkable: Vec<Vec<bool>>,
    pub monsters: Vec<Monster>,
    pub corpses: Vec<Corpse>,
}

pub struct Monster {
    pub x: i32,
    pub y: i32,
    pub glyph: char,
    pub hp: i32,
    pub alive: bool,
    pub instance_id: i32,
}

pub struct Corpse {
    pub x: i32,
    pub y: i32,
    pub id: i32,
    pub gold: i32,
    pub xp: i32,
}

impl Engine {
    pub fn new() -> io::Result<Self> {
        // Initialize terminal
        terminal::enable_raw_mode()?;
        io::stdout().execute(EnterAlternateScreen)?;

        let game_state = GameState {
            player_x: 0,
            player_y: 0,
            location_id: 1,
            map_width: 20,
            map_height: 15,
            map_chars: vec![vec!['.'; 20]; 15],
            map_walkable: vec![vec![true; 20]; 15],
            monsters: Vec::new(),
            corpses: Vec::new(),
        };

        Ok(Engine { game_state })
    }

    pub fn run(&mut self) -> io::Result<()> {
        loop {
            self.render()?;

            if let Event::Key(KeyEvent { code, .. }) = event::read()? {
                match code {
                    KeyCode::Char('q') | KeyCode::Esc => break,
                    KeyCode::Up => self.move_player(0, -1),
                    KeyCode::Down => self.move_player(0, 1),
                    KeyCode::Left => self.move_player(-1, 0),
                    KeyCode::Right => self.move_player(1, 0),
                    _ => {}
                }
            }
        }
        Ok(())
    }

    fn render(&self) -> io::Result<()> {
        // Clear screen
        print!("{}{}", crossterm::cursor::MoveTo(0, 0), crossterm::terminal::Clear(crossterm::terminal::ClearType::All));

        // Render map
        for y in 0..self.game_state.map_height {
            for x in 0..self.game_state.map_width {
                if x == self.game_state.player_x && y == self.game_state.player_y {
                    print!("@");
                } else {
                    let mut drawn = false;

                    // Check for monsters
                    for monster in &self.game_state.monsters {
                        if monster.x == x && monster.y == y {
                            if monster.alive && monster.hp > 0 {
                                print!("{}", monster.glyph);
                            } else {
                                print!("±");
                            }
                            drawn = true;
                            break;
                        }
                    }

                    // Check for corpses
                    if !drawn {
                        for corpse in &self.game_state.corpses {
                            if corpse.x == x && corpse.y == y {
                                print!("±");
                                drawn = true;
                                break;
                            }
                        }
                    }

                    // Draw map tile
                    if !drawn {
                        print!("{}", self.game_state.map_chars[y as usize][x as usize]);
                    }
                }
            }
            println!();
        }

        // Render HUD
        println!();
        println!("Use arrow keys to move, Q or Esc to quit");
        println!("Location: {}", self.game_state.location_id);

        io::stdout().flush()?;
        Ok(())
    }

    fn move_player(&mut self, dx: i32, dy: i32) {
        let new_x = self.game_state.player_x + dx;
        let new_y = self.game_state.player_y + dy;

        // Bounds checking
        if new_x < 0 || new_x >= self.game_state.map_width ||
           new_y < 0 || new_y >= self.game_state.map_height {
            return;
        }

        // Wall collision
        if !self.game_state.map_walkable[new_y as usize][new_x as usize] {
            return;
        }

        // Check for monsters (battle)
        for monster in &self.game_state.monsters {
            if monster.x == new_x && monster.y == new_y && monster.alive && monster.hp > 0 {
                // Would trigger battle here
                println!("Battle with monster!");
                return;
            }
        }

        // Normal movement
        self.game_state.player_x = new_x;
        self.game_state.player_y = new_y;
    }
}

impl Drop for Engine {
    fn drop(&mut self) {
        // Clean up terminal
        let _ = io::stdout().execute(LeaveAlternateScreen);
        let _ = terminal::disable_raw_mode();
    }
}
