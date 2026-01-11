use crossterm::{
    event::{self, Event, KeyCode, MouseButton, MouseEventKind},
    terminal::{self, Clear, ClearType},
    cursor,
    ExecutableCommand,
};
use std::io::{self, Write, stdout};
use std::collections::HashMap;

/// Represents a map tile
#[derive(Debug, Clone)]
pub struct MapTile {
    pub x: i32,
    pub y: i32,
    pub tile_char: char,
    pub walkable: bool,
    pub tile_type: String,
}

/// Represents a location link
#[derive(Debug, Clone)]
pub struct LocationLink {
    pub link_id: i32,
    pub from_location_id: i32,
    pub from_x: i32,
    pub from_y: i32,
    pub to_location_id: i32,
    pub to_x: i32,
    pub to_y: i32,
    pub link_type: String,
}

/// Represents a monster spawn point
#[derive(Debug, Clone)]
pub struct MonsterSpawn {
    pub spawn_id: i32,
    pub location_id: i32,
    pub monster_id: i32,
    pub pos_x: i32,
    pub pos_y: i32,
}

/// Represents a location/map
#[derive(Debug, Clone)]
pub struct Location {
    pub id: i32,
    pub name: String,
    pub width: i32,
    pub height: i32,
    pub background_color: String,
    pub foreground_color: String,
}

/// Tile type definition
#[derive(Debug, Clone)]
pub struct TileType {
    pub id: i32,
    pub name: String,
    pub tile_char: char,
    pub walkable: bool,
}

/// Map editor state
pub struct MapEditor {
    pub current_location: Option<Location>,
    pub map_tiles: Vec<MapTile>,
    pub location_links: Vec<LocationLink>,
    pub monster_spawns: Vec<MonsterSpawn>,
    pub tile_types: Vec<TileType>,
    pub locations: Vec<Location>,

    // Editor state
    pub edit_mode: EditMode,
    pub selected_tile_type: Option<i32>,
    pub selected_monster: Option<i32>,
    pub selected_link: Option<i32>,
    pub selected_spawn: Option<i32>,
    pub to_location_id: Option<i32>,
    pub to_x: i32,
    pub to_y: i32,
    pub link_type: String,

    // Map display
    pub map_chars: Vec<Vec<char>>,
    pub cursor_x: i32,
    pub cursor_y: i32,
    pub map_width: i32,
    public map_height: i32,
}

#[derive(Debug, Clone, PartialEq)]
pub enum EditMode {
    Tiles,
    Links,
    Spawns,
}

impl MapEditor {
    pub fn new() -> Self {
        MapEditor {
            current_location: None,
            map_tiles: Vec::new(),
            location_links: Vec::new(),
            monster_spawns: Vec::new(),
            tile_types: vec![
                TileType { id: 1, name: "Floor".to_string(), tile_char: '.', walkable: true },
                TileType { id: 2, name: "Wall".to_string(), tile_char: '#', walkable: false },
                TileType { id: 3, name: "Door".to_string(), tile_char: '+', walkable: true },
                TileType { id: 4, name: "Water".to_string(), tile_char: '~', walkable: false },
                TileType { id: 5, name: "Grass".to_string(), tile_char: ',', walkable: true },
            ],
            locations: vec![
                Location { id: 1, name: "Starting Area".to_string(), width: 20, height: 15, background_color: "Black".to_string(), foreground_color: "White".to_string() },
                Location { id: 2, name: "Forest".to_string(), width: 30, height: 20, background_color: "Green".to_string(), foreground_color: "Brown".to_string() },
                Location { id: 3, name: "Dungeon".to_string(), width: 25, height: 25, background_color: "Gray".to_string(), foreground_color: "DarkGray".to_string() },
            ],

            edit_mode: EditMode::Tiles,
            selected_tile_type: None,
            selected_monster: None,
            selected_link: None,
            selected_spawn: None,
            to_location_id: None,
            to_x: 2,
            to_y: 2,
            link_type: "Door".to_string(),

            map_chars: vec![vec!['.'; 20]; 15],
            cursor_x: 0,
            cursor_y: 0,
            map_width: 20,
            map_height: 15,
        }
    }

    /// Loads a location into the editor
    pub fn load_location(&mut self, location_id: i32) -> Result<(), Box<dyn std::error::Error>> {
        // Find location
        let location = self.locations.iter().find(|loc| loc.id == location_id)
            .ok_or("Location not found")?.clone();

        self.current_location = Some(location.clone());
        self.map_width = location.width;
        self.map_height = location.height;

        // Initialize map chars with default floor
        self.map_chars = vec![vec!['.'; location.width as usize]; location.height as usize];

        // Load existing tiles (in a real implementation, this would come from database)
        self.load_map_tiles(location_id)?;
        self.load_location_links(location_id)?;
        self.load_monster_spawns(location_id)?;

        Ok(())
    }

    /// Loads map tiles for current location
    fn load_map_tiles(&mut self, location_id: i32) -> Result<(), Box<dyn std::error::Error>> {
        // In a real implementation, this would query the database
        // For demo purposes, we'll just clear and use defaults

        // Clear existing tiles for this location
        self.map_tiles.retain(|tile| tile.x < 0); // This effectively clears for demo

        // Set some sample walls around edges
        for x in 0..self.map_width {
            self.set_tile(x, 0, '#', false, "Wall".to_string())?;
            self.set_tile(x, self.map_height - 1, '#', false, "Wall".to_string())?;
        }

        for y in 0..self.map_height {
            self.set_tile(0, y, '#', false, "Wall".to_string())?;
            self.set_tile(self.map_width - 1, y, '#', false, "Wall".to_string())?;
        }

        Ok(())
    }

    /// Loads location links for current location
    fn load_location_links(&mut self, location_id: i32) -> Result<(), Box<dyn std::error::Error>> {
        // In a real implementation, this would query the database
        // For demo, we'll add a sample link
        self.location_links.clear();

        // Add a sample link if this is the starting area
        if location_id == 1 {
            self.location_links.push(LocationLink {
                link_id: 1,
                from_location_id: 1,
                from_x: 10,
                from_y: 0, // Top edge
                to_location_id: 2,
                to_x: 15,
                to_y: 19, // Bottom edge of forest
                link_type: "Door".to_string(),
            });
        }

        Ok(())
    }

    /// Loads monster spawns for current location
    fn load_monster_spawns(&mut self, location_id: i32) -> Result<(), Box<dyn std::error::Error>> {
        // In a real implementation, this would query the database
        // For demo, we'll add sample spawns
        self.monster_spawns.clear();

        // Add sample spawns for dungeon
        if location_id == 3 {
            self.monster_spawns.push(MonsterSpawn {
                spawn_id: 1,
                location_id: 3,
                monster_id: 1, // Goblin
                pos_x: 5,
                pos_y: 5,
            });

            self.monster_spawns.push(MonsterSpawn {
                spawn_id: 2,
                location_id: 3,
                monster_id: 2, // Orc
                pos_x: 15,
                pos_y: 15,
            });
        }

        Ok(())
    }

    /// Sets a tile at the specified coordinates
    pub fn set_tile(&mut self, x: i32, y: i32, tile_char: char, walkable: bool, tile_type: String) -> Result<(), Box<dyn std::error::Error>> {
        if x < 0 || x >= self.map_width || y < 0 || y >= self.map_height {
            return Err("Coordinates out of bounds".into());
        }

        // Update map display
        self.map_chars[y as usize][x as usize] = tile_char;

        // Update or add tile record
        let tile_index = self.map_tiles.iter().position(|tile| tile.x == x && tile.y == y);

        if let Some(index) = tile_index {
            // Update existing tile
            self.map_tiles[index].tile_char = tile_char;
            self.map_tiles[index].walkable = walkable;
            self.map_tiles[index].tile_type = tile_type;
        } else {
            // Add new tile
            self.map_tiles.push(MapTile {
                x,
                y,
                tile_char,
                walkable,
                tile_type,
            });
        }

        Ok(())
    }

    /// Clears a tile at the specified coordinates
    pub fn clear_tile(&mut self, x: i32, y: i32) -> Result<(), Box<dyn std::error::Error>> {
        if x < 0 || x >= self.map_width || y < 0 || y >= self.map_height {
            return Err("Coordinates out of bounds".into());
        }

        // Update map display to default floor
        self.map_chars[y as usize][x as usize] = '.';

        // Remove tile record
        self.map_tiles.retain(|tile| !(tile.x == x && tile.y == y));

        Ok(())
    }

    /// Places a link at the specified coordinates
    pub fn place_link(&mut self, x: i32, y: i32) -> Result<(), Box<dyn std::error::Error>> {
        let location_id = self.current_location.as_ref().ok_or("No location loaded")?.id;
        let to_location_id = self.to_location_id.ok_or("No destination location selected")?;

        // Remove any existing link at this position
        self.location_links.retain(|link| !(
            link.from_location_id == location_id &&
            link.from_x == x &&
            link.from_y == y
        ));

        // Add new link
        self.location_links.push(LocationLink {
            link_id: (self.location_links.len() + 1) as i32,
            from_location_id: location_id,
            from_x: x,
            from_y: y,
            to_location_id,
            to_x: self.to_x,
            to_y: self.to_y,
            link_type: self.link_type.clone(),
        });

        Ok(())
    }

    /// Removes a link at the specified coordinates
    pub fn remove_link(&mut self, x: i32, y: i32) -> Result<(), Box<dyn std::error::Error>> {
        let location_id = self.current_location.as_ref().ok_or("No location loaded")?.id;

        self.location_links.retain(|link| !(
            link.from_location_id == location_id &&
            link.from_x == x &&
            link.from_y == y
        ));

        Ok(())
    }

    /// Places a monster spawn at the specified coordinates
    pub fn place_spawn(&mut self, x: i32, y: i32) -> Result<(), Box<dyn std::error::Error>> {
        let location_id = self.current_location.as_ref().ok_or("No location loaded")?.id;
        let monster_id = self.selected_monster.ok_or("No monster selected")?;

        // Remove any existing spawn at this position
        self.monster_spawns.retain(|spawn| !(
            spawn.location_id == location_id &&
            spawn.pos_x == x &&
            spawn.pos_y == y
        ));

        // Add new spawn
        self.monster_spawns.push(MonsterSpawn {
            spawn_id: (self.monster_spawns.len() + 1) as i32,
            location_id,
            monster_id,
            pos_x: x,
            pos_y: y,
        });

        Ok(())
    }

    /// Removes a monster spawn at the specified coordinates
    pub fn remove_spawn(&mut self, x: i32, y: i32) -> Result<(), Box<dyn std::error::Error>> {
        let location_id = self.current_location.as_ref().ok_or("No location loaded")?.id;

        self.monster_spawns.retain(|spawn| !(
            spawn.location_id == location_id &&
            spawn.pos_x == x &&
            spawn.pos_y == y
        ));

        Ok(())
    }

    /// Checks if there's a link at the specified coordinates
    pub fn has_link_at(&self, x: i32, y: i32) -> bool {
        if let Some(location) = &self.current_location {
            self.location_links.iter().any(|link| {
                link.from_location_id == location.id &&
                link.from_x == x &&
                link.from_y == y
            })
        } else {
            false
        }
    }

    /// Checks if there's a spawn at the specified coordinates
    pub fn has_spawn_at(&self, x: i32, y: i32) -> bool {
        if let Some(location) = &self.current_location {
            self.monster_spawns.iter().any(|spawn| {
                spawn.location_id == location.id &&
                spawn.pos_x == x &&
                spawn.pos_y == y
            })
        } else {
            false
        }
    }

    /// Renders the map editor interface
    pub fn render(&self) -> io::Result<()> {
        // Clear screen
        terminal::enable_raw_mode()?;
        stdout().execute(cursor::MoveTo(0, 0))?;
        stdout().execute(Clear(ClearType::All))?;

        // Display header
        println!("=== MAP EDITOR ===");
        if let Some(location) = &self.current_location {
            println!("Editing: {} ({}x{})", location.name, location.width, location.height);
        } else {
            println!("No location loaded");
        }
        println!();

        // Display edit mode
        println!("Mode: {:?}", self.edit_mode);
        match &self.edit_mode {
            EditMode::Tiles => {
                if let Some(tile_type_id) = self.selected_tile_type {
                    if let Some(tile_type) = self.tile_types.iter().find(|tt| tt.id == tile_type_id) {
                        println!("Selected Tile: {} ({})", tile_type.name, tile_type.tile_char);
                    }
                } else {
                    println!("No tile type selected");
                }
            },
            EditMode::Links => {
                println!("Link Mode - From: ({}, {}) To: Location {}, ({}, {})",
                         self.cursor_x, self.cursor_y,
                         self.to_location_id.unwrap_or(0), self.to_x, self.to_y);
            },
            EditMode::Spawns => {
                if let Some(monster_id) = self.selected_monster {
                    println!("Selected Monster ID: {}", monster_id);
                } else {
                    println!("No monster selected");
                }
            }
        }
        println!();

        // Display map
        self.render_map()?;
        println!();

        // Display controls
        println!("Controls:");
        println!("  Arrow Keys: Move cursor");
        println!("  1/2/3: Switch mode (Tiles/Links/Spawns)");
        println!("  Space: Place item at cursor");
        println!("  Backspace: Remove item at cursor");
        println!("  T: Select tile type");
        println!("  M: Select monster");
        println!("  L: Select link destination");
        println!("  S: Save map");
        println!("  Q: Quit");

        stdout().flush()?;
        Ok(())
    }

    /// Renders the map display
    fn render_map(&self) -> io::Result<()> {
        // Print column headers
        print!("   ");
        for x in 0..self.map_width {
            if x < 10 {
                print!(" {}", x);
            } else {
                print!("{}", x % 10);
            }
        }
        println!();

        // Print map rows
        for y in 0..self.map_height {
            if y < 10 {
                print!(" {} ", y);
            } else {
                print!("{} ", y);
            }

            for x in 0..self.map_width {
                let ch = if x == self.cursor_x && y == self.cursor_y {
                    // Cursor position
                    '*'
                } else if self.has_link_at(x, y) {
                    // Link marker
                    'D'
                } else if self.has_spawn_at(x, y) {
                    // Spawn marker
                    '!'
                } else {
                    // Regular tile
                    self.map_chars[y as usize][x as usize]
                };
                print!("{}", ch);
            }
            println!();
        }

        Ok(())
    }

    /// Runs the map editor main loop
    pub fn run_editor(&mut self) -> io::Result<()> {
        // Load initial location if available
        if !self.locations.is_empty() {
            let _ = self.load_location(self.locations[0].id);
        }

        loop {
            self.render()?;

            if let Event::Key(event) = event::read()? {
                match event.code {
                    KeyCode::Char('q') | KeyCode::Char('Q') | KeyCode::Esc => {
                        break;
                    },
                    KeyCode::Char('1') => {
                        self.edit_mode = EditMode::Tiles;
                    },
                    KeyCode::Char('2') => {
                        self.edit_mode = EditMode::Links;
                    },
                    KeyCode::Char('3') => {
                        self.edit_mode = EditMode::Spawns;
                    },
                    KeyCode::Char('t') | KeyCode::Char('T') => {
                        self.select_tile_type()?;
                    },
                    KeyCode::Char('m') | KeyCode::Char('M') => {
                        self.select_monster()?;
                    },
                    KeyCode::Char('l') | KeyCode::Char('L') => {
                        self.select_link_destination()?;
                    },
                    KeyCode::Char('s') | KeyCode::Char('S') => {
                        self.save_map()?;
                    },
                    KeyCode::Up => {
                        if self.cursor_y > 0 {
                            self.cursor_y -= 1;
                        }
                    },
                    KeyCode::Down => {
                        if self.cursor_y < self.map_height - 1 {
                            self.cursor_y += 1;
                        }
                    },
                    KeyCode::Left => {
                        if self.cursor_x > 0 {
                            self.cursor_x -= 1;
                        }
                    },
                    KeyCode::Right => {
                        if self.cursor_x < self.map_width - 1 {
                            self.cursor_x += 1;
                        }
                    },
                    KeyCode::Char(' ') => {
                        self.place_item_at_cursor()?;
                    },
                    KeyCode::Backspace => {
                        self.remove_item_at_cursor()?;
                    },
                    KeyCode::Enter => {
                        self.load_location_dialog()?;
                    },
                    _ => {}
                }
            }
        }

        Ok(())
    }

    /// Selects a tile type
    fn select_tile_type(&mut self) -> io::Result<()> {
        println!("\nAvailable Tile Types:");
        for (i, tile_type) in self.tile_types.iter().enumerate() {
            println!("  {}: {} ({})", i + 1, tile_type.name, tile_type.tile_char);
        }
        println!("Enter selection (1-{}):", self.tile_types.len());

        stdout().flush()?;

        let mut input = String::new();
        io::stdin().read_line(&mut input)?;

        if let Ok(selection) = input.trim().parse::<usize>() {
            if selection > 0 && selection <= self.tile_types.len() {
                self.selected_tile_type = Some(self.tile_types[selection - 1].id);
            }
        }

        Ok(())
    }

    /// Selects a monster
    fn select_monster(&mut self) -> io::Result<()> {
        println!("\nAvailable Monsters:");
        println!("  1: Goblin");
        println!("  2: Orc");
        println!("  3: Skeleton");
        println!("  4: Zombie");
        println!("Enter selection (1-4):");

        stdout().flush()?;

        let mut input = String::new();
        io::stdin().read_line(&mut input)?;

        if let Ok(selection) = input.trim().parse::<i32>() {
            if selection >= 1 && selection <= 4 {
                self.selected_monster = Some(selection);
            }
        }

        Ok(())
    }

    /// Selects link destination
    fn select_link_destination(&mut self) -> io::Result<()> {
        println!("\nAvailable Destinations:");
        for location in &self.locations {
            println!("  {}: {}", location.id, location.name);
        }
        println!("Enter destination location ID:");

        stdout().flush()?;

        let mut input = String::new();
        io::stdin().read_line(&mut input)?;

        if let Ok(location_id) = input.trim().parse::<i32>() {
            if self.locations.iter().any(|loc| loc.id == location_id) {
                self.to_location_id = Some(location_id);
            }
        }

        println!("Enter destination X coordinate:");
        let mut input = String::new();
        io::stdin().read_line(&mut input)?;
        if let Ok(x) = input.trim().parse::<i32>() {
            self.to_x = x;
        }

        println!("Enter destination Y coordinate:");
        let mut input = String::new();
        io::stdin().read_line(&mut input)?;
        if let Ok(y) = input.trim().parse::<i32>() {
            self.to_y = y;
        }

        Ok(())
    }

    /// Places an item at the cursor position based on current mode
    fn place_item_at_cursor(&mut self) -> io::Result<()> {
        match &self.edit_mode {
            EditMode::Tiles => {
                if let Some(tile_type_id) = self.selected_tile_type {
                    if let Some(tile_type) = self.tile_types.iter().find(|tt| tt.id == tile_type_id) {
                        let _ = self.set_tile(
                            self.cursor_x,
                            self.cursor_y,
                            tile_type.tile_char,
                            tile_type.walkable,
                            tile_type.name.clone()
                        );
                    }
                }
            },
            EditMode::Links => {
                let _ = self.place_link(self.cursor_x, self.cursor_y);
            },
            EditMode::Spawns => {
                let _ = self.place_spawn(self.cursor_x, self.cursor_y);
            }
        }
        Ok(())
    }

    /// Removes an item at the cursor position based on current mode
    fn remove_item_at_cursor(&mut self) -> io::Result<()> {
        match &self.edit_mode {
            EditMode::Tiles => {
                let _ = self.clear_tile(self.cursor_x, self.cursor_y);
            },
            EditMode::Links => {
                let _ = self.remove_link(self.cursor_x, self.cursor_y);
            },
            EditMode::Spawns => {
                let _ = self.remove_spawn(self.cursor_x, self.cursor_y);
            }
        }
        Ok(())
    }

    /// Saves the current map
    fn save_map(&mut self) -> io::Result<()> {
        println!("\nSaving map...");

        // In a real implementation, this would save to database
        // For demo, we'll just show what would be saved

        if let Some(location) = &self.current_location {
            println!("Location: {}", location.name);
            println!("Tiles: {}", self.map_tiles.len());
            println!("Links: {}", self.location_links.len());
            println!("Spawns: {}", self.monster_spawns.len());

            // Generate sample SQL statements
            println!("\nSample SQL statements that would be executed:");

            for tile in &self.map_tiles {
                println!("INSERT INTO tblMapTiles (LocationID, X, Y, TileChar, Walkable, TileType) VALUES ({}, {}, {}, '{}', {}, '{}');",
                         location.id, tile.x, tile.y, tile.tile_char, tile.walkable, tile.tile_type);
            }

            for link in &self.location_links {
                println!("INSERT INTO tblLocationLinks (FromLocationID, FromX, FromY, ToLocationID, ToX, ToY, LinkType) VALUES ({}, {}, {}, {}, {}, {}, '{}');",
                         link.from_location_id, link.from_x, link.from_y, link.to_location_id, link.to_x, link.to_y, link.link_type);
            }

            for spawn in &self.monster_spawns {
                println!("INSERT INTO tblMonsterSpawns (LocationID, MonsterID, MPosX, MPosY) VALUES ({}, {}, {}, {});",
                         spawn.location_id, spawn.monster_id, spawn.pos_x, spawn.pos_y);
            }
        }

        println!("\nPress any key to continue...");
        event::read()?;

        Ok(())
    }

    /// Loads a location via dialog
    fn load_location_dialog(&mut self) -> io::Result<()> {
        println!("\nAvailable Locations:");
        for (i, location) in self.locations.iter().enumerate() {
            println!("  {}: {} ({}x{})", location.id, location.name, location.width, location.height);
        }
        println!("Enter location ID to load:");

        stdout().flush()?;

        let mut input = String::new();
        io::stdin().read_line(&mut input)?;

        if let Ok(location_id) = input.trim().parse::<i32>() {
            if let Err(e) = self.load_location(location_id) {
                println!("Error loading location: {}", e);
            }
        }

        Ok(())
    }
}

/// Map editor manager
pub struct MapEditorManager;

impl MapEditorManager {
    /// Runs the complete map editor
    pub fn run_map_editor() -> io::Result<()> {
        let mut editor = MapEditor::new();
        editor.run_editor()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_map_editor_creation() {
        let editor = MapEditor::new();
        assert_eq!(editor.tile_types.len(), 5);
        assert_eq!(editor.locations.len(), 3);
        assert_eq!(editor.edit_mode, EditMode::Tiles);
    }

    #[test]
    fn test_tile_operations() {
        let mut editor = MapEditor::new();
        editor.map_width = 10;
        editor.map_height = 10;
        editor.map_chars = vec![vec!['.'; 10]; 10];

        // Test setting a tile
        assert!(editor.set_tile(5, 5, '#', false, "Wall".to_string()).is_ok());
        assert_eq!(editor.map_chars[5][5], '#');
        assert_eq!(editor.map_tiles.len(), 1);

        // Test clearing a tile
        assert!(editor.clear_tile(5, 5).is_ok());
        assert_eq!(editor.map_chars[5][5], '.');
        assert_eq!(editor.map_tiles.len(), 0);
    }
}
