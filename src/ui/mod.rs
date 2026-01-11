use std::io::Write;

pub struct UiUtils;

impl UiUtils {
    /// Creates a vertical ASCII bar similar to your VBA version
    pub fn ascii_vertical_bar(current: i32, max: i32, height: usize,
                             fill_char: char, empty_char: char) -> String {
        if max <= 0 {
            return "\n".repeat(height);
        }

        let current = current.max(0).min(max);
        let progress = current as f32 / max as f32;
        let filled_blocks = (progress * height as f32).round() as usize;

        let mut result = String::new();
        for i in (0..height).rev() {
            if i < filled_blocks {
                result.push(fill_char);
            } else {
                result.push(empty_char);
            }
            if i > 0 {
                result.push('\n');
            }
        }
        result
    }

    /// Creates a framed vertical ASCII bar with Unicode box drawing characters
    pub fn ascii_vertical_bar_framed(current: i32, max: i32, height: usize,
                                    fill_char: char, empty_char: char) -> String {
        if max <= 0 {
            return format!("┌─ ┐\n{}└─ ┘", "\n".repeat(height));
        }

        let current = current.max(0).min(max);
        let progress = current as f32 / max as f32;
        let filled_blocks = (progress * height as f32).round() as usize;

        let mut result = String::from("┌─ ┐\n");

        for i in (0..height).rev() {
            result.push('│');
            result.push(' ');
            if i < filled_blocks {
                result.push(fill_char);
            } else {
                result.push(empty_char);
            }
            result.push_str(" │\n");
        }

        result.push_str("└─ ┘");
        result
    }

    /// Creates a horizontal ASCII bar with spacing
    pub fn ascii_horizontal_bar_spaced(current: i32, max: i32, blocks: usize) -> String {
        if max <= 0 {
            return " ".repeat(blocks * 2 - 1); // Space between each block
        }

        let current = current.max(0).min(max);
        let progress = current as f32 / max as f32;
        let filled_blocks = (progress * blocks as f32).round() as usize;

        let mut result = String::new();
        for i in 0..blocks {
            if i < filled_blocks {
                result.push('█'); // Full block character
            } else {
                result.push('░'); // Light shade character
            }

            if i < blocks - 1 {
                result.push(' '); // Space between blocks
            }
        }
        result
    }

    /// Creates a simple horizontal bar without spacing
    pub fn ascii_horizontal_bar(current: i32, max: i32, width: usize) -> String {
        if max <= 0 {
            return "░".repeat(width);
        }

        let current = current.max(0).min(max);
        let progress = current as f32 / max as f32;
        let filled_width = (progress * width as f32).round() as usize;

        let mut result = String::new();
        for i in 0..width {
            if i < filled_width {
                result.push('█'); // Full block
            } else {
                result.push('░'); // Light shade
            }
        }
        result
    }

    /// Formats a stat line with a label and value
    pub fn format_stat_line(label: &str, value: i32, max: i32) -> String {
        format!("{}: {} / {}", label, value, max)
    }

    /// Creates a boxed UI element with title
    pub fn create_box(title: &str, content: &str, width: usize) -> String {
        let mut result = String::new();

        // Top border
        result.push('┌');
        for _ in 0..(width-2) {
            result.push('─');
        }
        result.push('┐');
        result.push('\n');

        // Title line
        result.push('│');
        let title_padding = (width - 2 - title.len()) / 2;
        for _ in 0..title_padding {
            result.push(' ');
        }
        result.push_str(title);
        for _ in 0..(width - 2 - title_padding - title.len()) {
            result.push(' ');
        }
        result.push('│');
        result.push('\n');

        // Separator
        result.push('├');
        for _ in 0..(width-2) {
            result.push('─');
        }
        result.push('┤');
        result.push('\n');

        // Content lines
        for line in content.lines() {
            result.push('│');
            result.push_str(line);
            for _ in line.len()..(width-2) {
                result.push(' ');
            }
            result.push('│');
            result.push('\n');
        }

        // Bottom border
        result.push('└');
        for _ in 0..(width-2) {
            result.push('─');
        }
        result.push('┘');

        result
    }
}

// Color utilities for terminal output
pub struct Colors;

impl Colors {
    pub const RESET: &'static str = "\x1b[0m";
    pub const BLACK: &'static str = "\x1b[30m";
    pub const RED: &'static str = "\x1b[31m";
    pub const GREEN: &'static str = "\x1b[32m";
    pub const YELLOW: &'static str = "\x1b[33m";
    pub const BLUE: &'static str = "\x1b[34m";
    pub const MAGENTA: &'static str = "\x1b[35m";
    pub const CYAN: &'static str = "\x1b[36m";
    pub const WHITE: &'static str = "\x1b[37m";

    // Background colors
    pub const BG_BLACK: &'static str = "\x1b[40m";
    pub const BG_RED: &'static str = "\x1b[41m";
    pub const BG_GREEN: &'static str = "\x1b[42m";
    pub const BG_YELLOW: &'static str = "\x1b[43m";
    pub const BG_BLUE: &'static str = "\x1b[44m";
    pub const BG_MAGENTA: &'static str = "\x1b[45m";
    pub const BG_CYAN: &'static str = "\x1b[46m";
    pub const BG_WHITE: &'static str = "\x1b[47m";

    /// Applies color to text
    pub fn colored(text: &str, color: &str) -> String {
        format!("{}{}{}", color, text, Self::RESET)
    }

    /// Applies background color to text
    pub fn bg_colored(text: &str, bg_color: &str) -> String {
        format!("{}{}{}", bg_color, text, Self::RESET)
    }
}

// HUD (Heads-Up Display) utilities
pub struct Hud;

impl Hud {
    /// Creates a player stats HUD
    pub fn create_player_hud(player: &crate::entities::Player) -> String {
        let mut hud = String::new();

        // Player name and level
        hud.push_str(&format!("{} (Level {})\n", player.name, player.level));

        // Health bar
        hud.push_str(&UiUtils::format_stat_line("HP", player.hp, player.max_hp));
        hud.push('\n');
        hud.push_str(&UiUtils::ascii_horizontal_bar(player.hp, player.max_hp, 20));
        hud.push('\n');

        // Mana bar
        hud.push_str(&UiUtils::format_stat_line("Mana", player.mana, player.max_mana));
        hud.push('\n');
        hud.push_str(&UiUtils::ascii_horizontal_bar(player.mana, player.max_mana, 20));
        hud.push('\n');

        // Other stats
        hud.push_str(&format!("ATK: {} | DEF: {}\n", player.attack, player.defense));
        hud.push_str(&format!("Gold: {} | XP: {}\n", player.gold, player.experience));

        hud
    }

    /// Creates a monster info panel
    pub fn create_monster_panel(monster: &crate::combat::Monster) -> String {
        let mut panel = String::new();

        panel.push_str(&format!("{} [{}]\n", monster.name, monster.glyph));
        panel.push_str(&UiUtils::format_stat_line("HP", monster.hp, monster.max_hp));
        panel.push('\n');
        panel.push_str(&UiUtils::ascii_horizontal_bar(monster.hp, monster.max_hp, 15));
        panel.push('\n');
        panel.push_str(&format!("Tier: {:?}\n", monster.tier));

        panel
    }

    /// Creates an inventory display
    pub fn create_inventory_display(inventory: &crate::entities::Inventory) -> String {
        let mut display = String::new();

        display.push_str("Inventory:\n");
        if inventory.items.is_empty() {
            display.push_str("(Empty)\n");
        } else {
            for (i, item) in inventory.items.iter().enumerate() {
                display.push_str(&format!("{}. {}{}\n",
                    i + 1,
                    Colors::colored(&item.name, item.rarity.color_code()),
                    if i >= 9 { " (FULL)" } else { "" } // Indicate if inventory is full
                ));
            }
        }

        display
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_ascii_vertical_bar() {
        let bar = UiUtils::ascii_vertical_bar(5, 10, 5, '█', '░');
        assert_eq!(bar.lines().count(), 5);
    }

    #[test]
    fn test_ascii_horizontal_bar() {
        let bar = UiUtils::ascii_horizontal_bar(5, 10, 10);
        assert_eq!(bar.len(), 10);
    }
}
