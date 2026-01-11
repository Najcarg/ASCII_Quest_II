use std::io;

mod game;
mod engine;
mod entities;
mod combat;
mod loot;
mod ui;
mod stats;
mod schema;
mod database;
mod character_creation;
mod map_editor;

use map_editor::MapEditorManager;

fn main() -> Result<(), Box<dyn std::error::Error>> {
    println!("Welcome to ASCII Quest II!");
    println!("Press any key to start...");

    // Wait for keypress
    crossterm::event::read()?;

    // Run map editor
    println!("Starting Map Editor...");
    MapEditorManager::run_map_editor()?;

    Ok(())
}


fn demo_character_creation() -> io::Result<()> {
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

            // Save the character
            CharacterCreator::save_character(&character)?;
        },
        None => {
            println!("Character creation cancelled.");
        }
    }

    Ok(())
}
