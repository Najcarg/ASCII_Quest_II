use crate::entities::{Player, Inventory};
use crate::stats::{CharacterStats, ClassStats, StatsManager};
use crate::ui::{UiUtils, Colors, Hud};
use crossterm::{
    event::{self, Event, KeyCode},
    terminal::{self, Clear, ClearType},
    cursor,
    ExecutableCommand,
};
use std::io::{self, Write};
use std::collections::HashMap;

/// Character class definition
#[derive(Debug, Clone)]
pub struct CharacterClass {
    pub id: i32,
    pub name: String,
    pub description: String,
    pub glyph: char,
    pub base_stats: ClassStats,
}

impl CharacterClass {
    pub fn new(id: i32, name: String, description: String, glyph: char, base_stats: ClassStats) -> Self {
        CharacterClass {
            id,
            name,
            description,
            glyph,
            base_stats,
        }
    }
}

/// Character creation form state
pub struct CharacterCreationForm {
    pub player_name: String,
    pub character_name: String,
    pub selected_class_id: Option<i32>,
    pub classes: HashMap<i32, CharacterClass>,
    pub error_message: Option<String>,
}

impl CharacterCreationForm {
    pub fn new(player_name: String) -> Self {
        let mut classes = HashMap::new();

        // Define default classes
        classes.insert(1, CharacterClass::new(
            1,
            "Warrior".to_string(),
            "Strong melee fighter with high health and defense.".to_string(),
            '⚔',
            ClassStats {
                class_id: 1,
                class_name: "Warrior".to_string(),
                base_hp: 30,
                base_mana: 5,
                base_attack: 8,
                base_defense: 5,
                base_dodge: 1,
                hp_per_level: 10,
                mana_per_level: 1,
                attack_per_level: 3,
                defense_per_level: 2,
                dodge_per_level: 0,
                base_crit_damage: 50,
                base_crit_chance: 5,
                base_attack_count: 1,
                base_heal_per_step: 2,
                base_life_on_hit: 1,
                base_mana_per_min: 0,
                base_mana_on_hit: 0,
                base_bonus_xp_on_kill: 0,
                base_gold_find: 0,
            },
        ));

        classes.insert(2, CharacterClass::new(
            2,
            "Mage".to_string(),
            "Powerful spellcaster with high damage and mana.".to_string(),
            '🔮',
            ClassStats {
                class_id: 2,
                class_name: "Mage".to_string(),
                base_hp: 15,
                base_mana: 25,
                base_attack: 6,
                base_defense: 2,
                base_dodge: 2,
                hp_per_level: 4,
                mana_per_level: 8,
                attack_per_level: 4,
                defense_per_level: 1,
                dodge_per_level: 1,
                base_crit_damage: 75,
                base_crit_chance: 10,
                base_attack_count: 1,
                base_heal_per_step: 1,
                base_life_on_hit: 0,
                base_mana_per_min: 5,
                base_mana_on_hit: 2,
                base_bonus_xp_on_kill: 5,
                base_gold_find: 0,
            },
        ));

        classes.insert(3, CharacterClass::new(
            3,
            "Rogue".to_string(),
            "Stealthy combatant with high crit chance and dodge.".to_string(),
            '🗡',
            ClassStats {
                class_id: 3,
                class_name: "Rogue".to_string(),
                base_hp: 20,
                base_mana: 15,
                base_attack: 7,
                base_defense: 3,
                base_dodge: 8,
                hp_per_level: 6,
                mana_per_level: 4,
                attack_per_level: 5,
                defense_per_level: 1,
                dodge_per_level: 2,
                base_crit_damage: 100,
                base_crit_chance: 15,
                base_attack_count: 2,
                base_heal_per_step: 1,
                base_life_on_hit: 2,
                base_mana_per_min: 2,
                base_mana_on_hit: 1,
                base_bonus_xp_on_kill: 0,
                base_gold_find: 10,
            },
        ));

        classes.insert(4, CharacterClass::new(
            4,
            "Cleric".to_string(),
            "Divine healer with balanced stats and healing abilities.".to_string(),
            '♰',
            ClassStats {
                class_id: 4,
                class_name: "Cleric".to_string(),
                base_hp: 25,
                base_mana: 20,
                base_attack: 5,
                base_defense: 4,
                base_dodge: 3,
                hp_per_level: 7,
                mana_per_level: 6,
                attack_per_level: 2,
                defense_per_level: 2,
                dodge_per_level: 1,
                base_crit_damage: 50,
                base_crit_chance: 5,
                base_attack_count: 1,
                base_heal_per_step: 5,
                base_life_on_hit: 3,
                base_mana_per_min: 8,
                base_mana_on_hit: 3,
                base_bonus_xp_on_kill: 0,
                base_gold_find: 0,
            },
        ));

        CharacterCreationForm {
            player_name,
            character_name: String::new(),
            selected_class_id: None,
            classes,
            error_message: None,
        }
    }

    /// Displays the character creation form
    pub fn show_form(&mut self) -> io::Result<Option<CharacterStats>> {
        loop {
            self.display_form()?;

            if let Event::Key(event) = event::read()? {
                match event.code {
                    KeyCode::Char('n') | KeyCode::Char('N') => {
                        self.handle_name_input()?;
                    },
                    KeyCode::Char('c') | KeyCode::Char('C') => {
                        self.handle_class_selection()?;
                    },
                    KeyCode::Char('v') | KeyCode::Char('V') => {
                        self.view_class_details()?;
                    },
                    KeyCode::Enter => {
                        if let Some(character) = self.create_character() {
                            return Ok(Some(character));
                        }
                    },
                    KeyCode::Char('q') | KeyCode::Char('Q') | KeyCode::Esc => {
                        return Ok(None);
                    },
                    _ => {}
                }
            }
        }
    }

    /// Displays the character creation interface
    fn display_form(&self) -> io::Result<()> {
        // Clear screen
        terminal::enable_raw_mode()?;
        io::stdout().execute(cursor::MoveTo(0, 0))?;
        io::stdout().execute(Clear(ClearType::All))?;

        // Display header
        println!("{}", Colors::colored("=== CHARACTER CREATION ===", Colors::CYAN));
        println!("Player: {}", self.player_name);
        println!();

        // Display error message if any
        if let Some(ref error) = self.error_message {
            println!("{}", Colors::colored(&format!("ERROR: {}", error), Colors::RED));
            println!();
        }

        // Display character name input
        println!("Character Name: {}",
            if self.character_name.is_empty() {
                "<Enter name>"
            } else {
                &self.character_name
            }
        );

        // Display class selection
        println!("\nAvailable Classes:");
        for (id, class) in &self.classes {
            let selected_marker = if self.selected_class_id == Some(*id) { " [SELECTED]" } else { "" };
            println!("  {}. {}{} - {}", id, class.glyph, class.name, selected_marker);
        }

        // Display instructions
        println!("\nCommands:");
        println!("  [N]ame - Enter character name");
        println!("  [C]lass - Select class");
        println!("  [V]iew - View class details");
        println!("  [Enter] - Create character");
        println!("  [Q]uit - Cancel character creation");

        // Display preview if class selected
        if let Some(class_id) = self.selected_class_id {
            if let Some(class) = self.classes.get(&class_id) {
                println!("\n--- {} Preview ---", class.name);
                println!("Glyph: {}", class.glyph);
                println!("Description: {}", class.description);
                println!("\nBase Stats:");
                println!("  HP: {}", class.base_stats.base_hp);
                println!("  Mana: {}", class.base_stats.base_mana);
                println!("  Attack: {}", class.base_stats.base_attack);
                println!("  Defense: {}", class.base_stats.base_defense);
            }
        }

        io::stdout().flush()?;
        Ok(())
    }

    /// Handles character name input
    fn handle_name_input(&mut self) -> io::Result<()> {
        self.clear_error();

        // Move cursor to input area
        println!("\nEnter character name (or 'cancel' to go back):");
        io::stdout().flush()?;

        let mut input = String::new();
        io::stdin().read_line(&mut input)?;
        let input = input.trim();

        if input.to_lowercase() != "cancel" {
            if input.is_empty() {
                self.set_error("Character name cannot be empty.");
            } else if input.len() > 20 {
                self.set_error("Character name too long (max 20 characters).");
            } else {
                self.character_name = input.to_string();
            }
        }

        Ok(())
    }

    /// Handles class selection
    fn handle_class_selection(&mut self) -> io::Result<()> {
        self.clear_error();

        println!("\nEnter class number (1-4):");
        io::stdout().flush()?;

        let mut input = String::new();
        io::stdin().read_line(&mut input)?;

        if let Ok(class_id) = input.trim().parse::<i32>() {
            if self.classes.contains_key(&class_id) {
                self.selected_class_id = Some(class_id);
            } else {
                self.set_error("Invalid class selection.");
            }
        } else {
            self.set_error("Please enter a valid number.");
        }

        Ok(())
    }

    /// Views detailed class information
    fn view_class_details(&self) -> io::Result<()> {
        if let Some(class_id) = self.selected_class_id {
            if let Some(class) = self.classes.get(&class_id) {
                // Clear screen for detailed view
                io::stdout().execute(cursor::MoveTo(0, 0))?;
                io::stdout().execute(Clear(ClearType::All))?;

                println!("{}", Colors::colored(&format!("=== {} DETAILS ===", class.name), Colors::YELLOW));
                println!("Glyph: {}", class.glyph);
                println!("Description: {}", class.description);
                println!();

                println!("Base Stats:");
                println!("  HP: {}", class.base_stats.base_hp);
                println!("  Mana: {}", class.base_stats.base_mana);
                println!("  Attack: {}", class.base_stats.base_attack);
                println!("  Defense: {}", class.base_stats.base_defense);
                println!("  Dodge: {}", class.base_stats.base_dodge);
                println!();

                println!("Per Level Growth:");
                println!("  HP per level: {}", class.base_stats.hp_per_level);
                println!("  Mana per level: {}", class.base_stats.mana_per_level);
                println!("  Attack per level: {}", class.base_stats.attack_per_level);
                println!("  Defense per level: {}", class.base_stats.defense_per_level);
                println!();

                println!("Special Abilities:");
                println!("  Crit Damage: {}%", class.base_stats.base_crit_damage);
                println!("  Crit Chance: {}%", class.base_stats.base_crit_chance);
                println!("  Attack Count: {}", class.base_stats.base_attack_count);
                println!("  Heal per Step: {}", class.base_stats.base_heal_per_step);
                println!();

                println!("Press any key to return to character creation...");
                event::read()?;
            }
        } else {
            self.set_error("Please select a class first.");
        }

        Ok(())
    }

    /// Creates the character with selected options
    fn create_character(&self) -> Option<CharacterStats> {
        // Validate inputs
        if self.character_name.is_empty() {
            self.set_error("Please enter a character name.");
            return None;
        }

        let class_id = match self.selected_class_id {
            Some(id) => id,
            None => {
                self.set_error("Please select a class.");
                return None;
            }
        };

        let class = self.classes.get(&class_id)?;

        // Create character stats
        let mut character = CharacterStats::new();
        character.character_name = self.character_name.clone();
        character.class_id = class.id;
        character.class_name = class.name.clone();
        character.player_glyph = class.glyph;

        // Apply base stats
        character.level = 1;
        character.max_hp = class.base_stats.base_hp;
        character.hp = class.base_stats.base_hp;
        character.max_mana = class.base_stats.base_mana;
        character.mana = class.base_stats.base_mana;
        character.attack = class.base_stats.base_attack;
        character.defense = class.base_stats.base_defense;

        // Set starting position
        character.location_id = 1;
        character.pos_x = 2;
        character.pos_y = 2;

        Some(character)
    }

    /// Sets an error message
    fn set_error(&mut self, message: &str) {
        self.error_message = Some(message.to_string());
    }

    /// Clears the error message
    fn clear_error(&mut self) {
        self.error_message = None;
    }
}

/// Character creation manager
pub struct CharacterCreator;

impl CharacterCreator {
    /// Runs the complete character creation process
    pub fn create_character(player_name: String) -> io::Result<Option<CharacterStats>> {
        let mut form = CharacterCreationForm::new(player_name);
        form.show_form()
    }

    /// Saves the newly created character to storage
    pub fn save_character(character: &CharacterStats) -> io::Result<()> {
        // In a real implementation, this would save to database
        println!("Saving character '{}' to database...", character.character_name);
        println!("Character ID: {}", character.character_id);
        println!("Class: {}", character.class_name);
        println!("Level: {}", character.level);
        println!("HP: {}/{}", character.hp, character.max_hp);
        println!("Location: {}, Pos: ({}, {})",
                 character.location_id, character.pos_x, character.pos_y);

        // Generate SQL-like statement for reference
        let sql = character.save_core_stats();
        println!("\nGenerated SQL:");
        println!("{}", sql);

        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_character_class_creation() {
        let warrior_class = CharacterClass::new(
            1,
            "Warrior".to_string(),
            "Test class".to_string(),
            '⚔',
            ClassStats::new(),
        );

        assert_eq!(warrior_class.id, 1);
        assert_eq!(warrior_class.name, "Warrior");
        assert_eq!(warrior_class.glyph, '⚔');
    }

    #[test]
    fn test_character_creation_form() {
        let form = CharacterCreationForm::new("TestPlayer".to_string());
        assert_eq!(form.player_name, "TestPlayer");
        assert!(form.character_name.is_empty());
        assert_eq!(form.selected_class_id, None);
        assert_eq!(form.classes.len(), 4); // 4 default classes
    }
}
