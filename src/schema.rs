use serde::{Deserialize, Serialize};
use std::collections::HashMap;
use std::fmt;

/// Represents a database field/column
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Field {
    pub name: String,
    pub field_type: FieldType,
    pub nullable: bool,
    pub default_value: Option<String>,
}

/// Database field types mapping to SQL equivalents
#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum FieldType {
    Integer,
    BigInt,
    Text,
    Boolean,
    DateTime,
    Decimal,
    Custom(String), // For custom types
}

impl fmt::Display for FieldType {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            FieldType::Integer => write!(f, "INTEGER"),
            FieldType::BigInt => write!(f, "BIGINT"),
            FieldType::Text => write!(f, "TEXT"),
            FieldType::Boolean => write!(f, "BOOLEAN"),
            FieldType::DateTime => write!(f, "DATETIME"),
            FieldType::Decimal => write!(f, "DECIMAL"),
            FieldType::Custom(custom_type) => write!(f, "{}", custom_type),
        }
    }
}

/// Represents a database table
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Table {
    pub name: String,
    pub fields: Vec<Field>,
    pub primary_key: Option<String>,
}

impl Table {
    pub fn new(name: String) -> Self {
        Table {
            name,
            fields: Vec::new(),
            primary_key: None,
        }
    }

    pub fn add_field(&mut self, field: Field) {
        self.fields.push(field);
    }

    pub fn set_primary_key(&mut self, field_name: String) {
        self.primary_key = Some(field_name);
    }

    /// Generates CREATE TABLE SQL statement
    pub fn generate_create_sql(&self) -> String {
        let mut sql = format!("CREATE TABLE IF NOT EXISTS {} (\n", self.name);

        let field_definitions: Vec<String> = self.fields.iter().map(|field| {
            let nullable = if field.nullable { "" } else { " NOT NULL" };
            let default = if let Some(ref default_val) = field.default_value {
                format!(" DEFAULT {}", default_val)
            } else {
                String::new()
            };
            format!("  {} {}{}{}", field.name, field.field_type, nullable, default)
        }).collect();

        sql.push_str(&field_definitions.join(",\n"));

        if let Some(ref pk) = self.primary_key {
            sql.push_str(&format!(",\n  PRIMARY KEY ({})", pk));
        }

        sql.push_str("\n);");
        sql
    }
}

/// Schema migration manager
pub struct SchemaManager {
    pub tables: HashMap<String, Table>,
    pub migrations: Vec<Migration>,
}

impl SchemaManager {
    pub fn new() -> Self {
        SchemaManager {
            tables: HashMap::new(),
            migrations: Vec::new(),
        }
    }

    /// Adds a table to the schema
    pub fn add_table(&mut self, table: Table) {
        self.tables.insert(table.name.clone(), table);
    }

    /// Checks if a field exists in a table
    pub fn field_exists(&self, table_name: &str, field_name: &str) -> bool {
        if let Some(table) = self.tables.get(table_name) {
            table.fields.iter().any(|field| field.name == field_name)
        } else {
            false
        }
    }

    /// Adds fields to an existing table
    pub fn add_fields_to_table(
        &mut self,
        table_name: &str,
        fields: Vec<Field>,
        batch_size: usize,
    ) -> Result<(), SchemaError> {
        if !self.tables.contains_key(table_name) {
            return Err(SchemaError::TableNotFound(table_name.to_string()));
        }

        let table = self.tables.get_mut(table_name).unwrap();
        let mut processed_count = 0;

        for field in fields {
            if !self.field_exists(table_name, &field.name) {
                table.add_field(field);
                processed_count += 1;

                // Simulate batch processing
                if batch_size > 0 && processed_count % batch_size == 0 {
                    println!("Processed batch of {} field additions", batch_size);
                }
            }
        }

        println!("Added {} new fields to table {}", processed_count, table_name);
        Ok(())
    }

    /// Generates all CREATE TABLE statements
    pub fn generate_schema_sql(&self) -> Vec<String> {
        self.tables.values().map(|table| table.generate_create_sql()).collect()
    }

    /// Applies a migration
    pub fn apply_migration(&mut self, migration: Migration) -> Result<(), SchemaError> {
        match migration.migration_type {
            MigrationType::AddFields { table_name, fields } => {
                self.add_fields_to_table(&table_name, fields, 10)?; // Default batch size
            }
            MigrationType::CreateTable { table } => {
                self.add_table(table);
            }
            // Add more migration types as needed
        }

        self.migrations.push(migration);
        Ok(())
    }
}

/// Represents a schema migration
#[derive(Debug, Clone)]
pub struct Migration {
    pub id: String,
    pub description: String,
    pub migration_type: MigrationType,
}

/// Types of migrations supported
#[derive(Debug, Clone)]
pub enum MigrationType {
    AddFields {
        table_name: String,
        fields: Vec<Field>,
    },
    CreateTable {
        table: Table,
    },
    // Add more migration types as needed
}

/// Schema-related errors
#[derive(Debug)]
pub enum SchemaError {
    TableNotFound(String),
    FieldAlreadyExists(String, String),
    MigrationFailed(String),
}

impl fmt::Display for SchemaError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            SchemaError::TableNotFound(table_name) => {
                write!(f, "Table '{}' not found", table_name)
            }
            SchemaError::FieldAlreadyExists(table_name, field_name) => {
                write!(f, "Field '{}' already exists in table '{}'", field_name, table_name)
            }
            SchemaError::MigrationFailed(error) => {
                write!(f, "Migration failed: {}", error)
            }
        }
    }
}

impl std::error::Error for SchemaError {}

/// Helper functions for common field types
impl Field {
    pub fn new(name: String, field_type: FieldType) -> Self {
        Field {
            name,
            field_type,
            nullable: true,
            default_value: None,
        }
    }

    pub fn not_null(mut self) -> Self {
        self.nullable = false;
        self
    }

    pub fn with_default(mut self, default_value: String) -> Self {
        self.default_value = Some(default_value);
        self
    }
}

/// Predefined migration for item instance affix extra fields
pub fn add_item_instance_affix_extra_fields() -> Migration {
    let fields = vec![
        Field::new("RollAtk".to_string(), FieldType::Integer),
        Field::new("RollDef".to_string(), FieldType::Integer),
        Field::new("RollHP".to_string(), FieldType::Integer),
        Field::new("RollMana".to_string(), FieldType::Integer),
        Field::new("RollCritDamage".to_string(), FieldType::Integer),
        Field::new("RollCritChance".to_string(), FieldType::Integer),
        Field::new("RollAttackCount".to_string(), FieldType::Integer),
        Field::new("RollDodge".to_string(), FieldType::Integer),
        Field::new("RollHealPerStep".to_string(), FieldType::Integer),
        Field::new("RollManaPerStep".to_string(), FieldType::Integer),
        Field::new("RollHealPerMin".to_string(), FieldType::Integer),
        Field::new("RollManaPerMin".to_string(), FieldType::Integer),
        Field::new("RollLifeOnHit".to_string(), FieldType::Integer),
        Field::new("RollManaOnHit".to_string(), FieldType::Integer),
        Field::new("RollBonusXPOnKill".to_string(), FieldType::Integer),
        Field::new("RollGoldFind".to_string(), FieldType::Integer),
    ];

    Migration {
        id: "add_item_instance_affix_extra_fields".to_string(),
        description: "Add extra fields to item instance affixes table".to_string(),
        migration_type: MigrationType::AddFields {
            table_name: "tblItemInstanceAffixes".to_string(),
            fields,
        },
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_field_creation() {
        let field = Field::new("test_field".to_string(), FieldType::Integer);
        assert_eq!(field.name, "test_field");
        assert_eq!(format!("{}", field.field_type), "INTEGER");
    }

    #[test]
    fn test_table_creation() {
        let mut table = Table::new("test_table".to_string());
        table.add_field(Field::new("id".to_string(), FieldType::Integer));
        table.set_primary_key("id".to_string());

        let sql = table.generate_create_sql();
        assert!(sql.contains("CREATE TABLE IF NOT EXISTS test_table"));
        assert!(sql.contains("id INTEGER"));
    }

    #[test]
    fn test_schema_manager() {
        let mut manager = SchemaManager::new();
        let table = Table::new("test_table".to_string());
        manager.add_table(table);

        assert!(manager.tables.contains_key("test_table"));
    }

    #[test]
    fn test_field_exists() {
        let mut manager = SchemaManager::new();
        let mut table = Table::new("test_table".to_string());
        table.add_field(Field::new("existing_field".to_string(), FieldType::Integer));
        manager.add_table(table);

        assert!(manager.field_exists("test_table", "existing_field"));
        assert!(!manager.field_exists("test_table", "nonexistent_field"));
    }
}
