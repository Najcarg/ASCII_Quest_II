// src/main.rs
use std::io;

mod game;
mod engine;
mod entities;
mod combat;
mod loot;
mod ui;
mod stats;
mod character_creation;
mod map_editor;

use game::Game;
use character_creation::CharacterCreator;
use map_editor::MapEditorManager;
use stats::StatsManager;
use entities::{Player, Inventory};

fn main() -> Result<(), Box<dyn std::error::Error>> {
    println!("Welcome to ASCII Quest II!");
    println!("Press any key to continue...");

    // Wait for keypress
    crossterm::event::read()?;

    // Run main menu
    run_main_menu()?;

    Ok(())
}

fn run_main_menu() -> Result<(), Box<dyn std::error::Error>> {
    loop {
        clear_screen();
        println!("{}", ui::Colors::colored("=== ASCII QUEST II ===", ui::Colors::CYAN));
        println!();
        println!("1. New Game");
        println!("2. Load Game");
        println!("3. Map Editor");
        println!("4. Character Creation Demo");
        println!("5. Exit");
        println!();
        print!("Select an option (1-5): ");
        std::io::stdout().flush()?;

        let mut input = String::new();
        std::io::stdin().read_line(&mut input)?;

        match input.trim() {
            "1" => {
                if let Some(player) = create_new_game()? {
                    run_game(player)?;
                }
            },
            "2" => {
                if let Some(player) = load_game()? {
                    run_game(player)?;
                }
            },
            "3" => {
                MapEditorManager::run_map_editor()?;
            },
            "4" => {
                demo_character_creation()?;
            },
            "5" => {
                println!("Thank you for playing ASCII Quest II!");
                break;
            },
            _ => {
                println!("Invalid option. Press any key to continue...");
                crossterm::event::read()?;
            }
        }
    }

    Ok(())
}

fn create_new_game() -> Result<Option<Player>, Box<dyn std::error::Error>> {
    println!("\n=== NEW CHARACTER ===");

    // Get player name
    println!("Enter your name:");
    let mut player_name = String::new();
    std::io::stdin().read_line(&mut player_name)?;
    let player_name = player_name.trim().to_string();

    if player_name.is_empty() {
        println!("Invalid name!");
        wait_for_key();
        return Ok(None);
    }

    // Run character creation
    match CharacterCreator::create_character(player_name)? {
        Some(character_stats) => {
            let mut player = Player::new(character_stats.character_name.clone());
            player.stats = character_stats;

            // Save the new character
            save_character(&player)?;

            println!("\nCharacter created successfully!");
            wait_for_key();

            Ok(Some(player))
        },
        None => {
            println!("Character creation cancelled.");
            wait_for_key();
            Ok(None)
        }
    }
}

fn load_game() -> Result<Option<Player>, Box<dyn std::error::Error>> {
    println!("\n=== LOAD CHARACTER ===");

    // In a real implementation, this would load from saved files
    // For now, we'll create a default character
    println!("Loading last saved character...");
    std::thread::sleep(std::time::Duration::from_secs(1));

    let mut player = Player::new("Hero".to_string());
    player.stats.level = 3;
    player.stats.xp = 500;
    player.stats.gold = 250;
    player.stats.hp = player.stats.max_hp;
    player.stats.mana = player.stats.max_mana;

    println!("Character loaded successfully!");
    wait_for_key();

    Ok(Some(player))
}

fn run_game(mut player: Player) -> Result<(), Box<dyn std::error::Error>> {
    println!("\n=== ENTERING WORLD ===");
    std::thread::sleep(std::time::Duration::from_secs(1));

    // Create and initialize game
    let mut game = Game::new();
    game.player = player;

    // Run the main game loop
    game.run_game_loop()?;

    // Save character when exiting
    save_character(&game.player)?;

    Ok(())
}

fn demo_character_creation() -> Result<(), Box<dyn std::error::Error>> {
    clear_screen();
    println!("=== CHARACTER CREATION DEMO ===");

    // Run character creation
    match CharacterCreator::create_character("Player1".to_string())? {
        Some(character) => {
            println!("\nCharacter created successfully!");
            println!("Name: {}", character.character_name);
            println!("Class: {}", character.class_name);
            println!("Level: {}", character.level);
            println!("HP: {}/{}", character.hp, character.max_hp);
            println!("Mana: {}/{}", character.mana, character.max_mana);
            println!("Attack: {}", character.attack);
            println!("Defense: {}", character.defense);
        },
        None => {
            println!("Character creation cancelled.");
        }
    }

    wait_for_key();
    Ok(())
}

fn save_character(player: &Player) -> Result<(), Box<dyn std::error::Error>> {
    use std::fs::File;
    use std::io::Write;
    use serde_json;

    // Create saves directory if it doesn't exist
    std::fs::create_dir_all("saves")?;

    // Create filename based on character name
    let filename = format!("saves/{}.json", player.stats.character_name);

    // Serialize player data
    let json = serde_json::to_string_pretty(&player)?;

    // Write to file
    let mut file = File::create(filename)?;
    file.write_all(json.as_bytes())?;

    println!("Game saved successfully!");
    Ok(())
}

fn clear_screen() {
    print!("{}[2J", 27 as char); // ANSI escape code for clear screen
    print!("{}[H", 27 as char);  // ANSI escape code for cursor to home
    std::io::stdout().flush().unwrap();
}

fn wait_for_key() {
    println!("\nPress any key to continue...");
    let _ = crossterm::event::read();
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_save_character() {
        let player = Player::new("TestHero".to_string());
        // This test would require mocking the file system
        // In a real test, we'd mock std::fs::File
    }
}
