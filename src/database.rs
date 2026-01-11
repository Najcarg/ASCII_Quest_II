use crate::schema::{SchemaManager, Migration, add_item_instance_affix_extra_fields};
use std::fs::File;
use std::io::{Write, BufReader, BufRead};
use std::path::Path;

/// Database migration runner
pub struct DatabaseMigrator {
    schema_manager: SchemaManager,
    migration_history: Vec<String>,
    migration_file: String,
}

impl DatabaseMigrator {
    pub fn new(migration_file: String) -> Self {
        DatabaseMigrator {
            schema_manager: SchemaManager::new(),
            migration_history: Vec::new(),
            migration_file,
        }
    }

    /// Loads migration history from file
    pub fn load_migration_history(&mut self) -> std::io::Result<()> {
        if Path::new(&self.migration_file).exists() {
            let file = File::open(&self.migration_file)?;
            let reader = BufReader::new(file);
            self.migration_history = reader.lines().collect::<Result<Vec<_>, _>>()?;
        }
        Ok(())
    }

    /// Saves migration history to file
    pub fn save_migration_history(&self) -> std::io::Result<()> {
        let mut file = File::create(&self.migration_file)?;
        for migration_id in &self.migration_history {
            writeln!(file, "{}", migration_id)?;
        }
        Ok(())
    }

    /// Checks if a migration has already been applied
    pub fn is_migration_applied(&self, migration_id: &str) -> bool {
        self.migration_history.contains(&migration_id.to_string())
    }

    /// Applies a migration if not already applied
    pub fn apply_migration(&mut self, migration: Migration) -> Result<(), Box<dyn std::error::Error>> {
        if self.is_migration_applied(&migration.id) {
            println!("Migration '{}' already applied, skipping", migration.id);
            return Ok(());
        }

        println!("Applying migration: {}", migration.description);

        match self.schema_manager.apply_migration(migration) {
            Ok(_) => {
                self.migration_history.push(migration.id.clone());
                println!("Migration '{}' applied successfully", migration.id);
                Ok(())
            }
            Err(e) => {
                eprintln!("Failed to apply migration '{}': {}", migration.id, e);
                Err(Box::new(e))
            }
        }
    }

    /// Runs all pending migrations
    pub fn run_pending_migrations(&mut self) -> Result<(), Box<dyn std::error::Error>> {
        self.load_migration_history()?;

        // Apply the item instance affix extra fields migration
        let item_affix_migration = add_item_instance_affix_extra_fields();
        self.apply_migration(item_affix_migration)?;

        self.save_migration_history()?;
        Ok(())
    }

    /// Generates SQL schema for current state
    pub fn generate_schema_sql(&self) -> Vec<String> {
        self.schema_manager.generate_schema_sql()
    }
}

/// Example usage function
pub fn run_database_migrations() -> Result<(), Box<dyn std::error::Error>> {
    let mut migrator = DatabaseMigrator::new("migrations.log".to_string());

    // Run all pending migrations
    migrator.run_pending_migrations()?;

    // Generate and display schema SQL
    let schema_sql = migrator.generate_schema_sql();
    println!("\nGenerated Schema SQL:");
    for sql_statement in schema_sql {
        println!("{}\n", sql_statement);
    }

    Ok(())
}
