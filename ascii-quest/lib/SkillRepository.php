<?php
declare(strict_types=1);
require_once __DIR__ . '/SkillCatalogue.php';

final class SkillRepository
{
    public function __construct(private PDO $pdo) {}
    public function begin(): void
    {
        if ($this->pdo->inTransaction()) { throw new LogicException('Skill transaction already active.'); }
        $this->pdo->beginTransaction();
    }
    public function commit(): void { $this->pdo->commit(); }
    public function rollback(): void { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } }

    public function catalogue(): SkillCatalogue
    {
        $rows = $this->pdo->query('SELECT sd.*, cc.class_key FROM skill_definitions sd INNER JOIN character_classes cc ON cc.id = sd.class_id ORDER BY sd.class_id, sd.tree_position')->fetchAll(PDO::FETCH_ASSOC);
        $edges = $this->pdo->query('SELECT * FROM skill_prerequisites ORDER BY skill_key, prerequisite_key')->fetchAll(PDO::FETCH_ASSOC);
        return new SkillCatalogue($rows, $edges);
    }

    public function ownedCharacter(int $userId, int $characterId, bool $lock = false): ?array
    {
        $stmt = $this->pdo->prepare('SELECT c.id,c.user_id,c.class_id,c.life_state,c.current_hp,c.level,c.experience FROM characters c WHERE c.id = :character_id AND c.user_id = :user_id LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['character_id'=>$characterId,'user_id'=>$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) { return null; }
        // Class metadata is immutable during normal play; do not lock a shared
        // class row before the account mutex (cross-Champion deadlock risk).
        $class = $this->pdo->prepare('SELECT class_key FROM character_classes WHERE id = :class_id LIMIT 1');
        $class->execute(['class_id'=>$row['class_id']]);
        $row['class_key'] = $class->fetchColumn();
        return $row;
    }

    public function lockAccount(int $userId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE id = :user_id LIMIT 1 FOR UPDATE');
        $stmt->execute(['user_id'=>$userId]);
        if ($stmt->fetchColumn() === false) { throw new OutOfBoundsException('Champion unavailable.'); }
    }

    public function encounter(int $userId, bool $lock = false): ?array
    {
        $stmt = $this->pdo->prepare("SELECT ce.id,ce.status FROM combat_encounters ce INNER JOIN characters c ON c.id = ce.character_id WHERE c.user_id = :user_id AND ce.status IN ('active','victory_loot') ORDER BY ce.id DESC LIMIT 1");
        $stmt->execute(['user_id'=>$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$lock) { return is_array($row) ? $row : null; }
        // Account mutex is held. Lock only the encounter, never its other
        // Champion through the lookup JOIN (same-account lock inversion).
        $stmt = $this->pdo->prepare("SELECT id,status FROM combat_encounters WHERE id = :encounter_id AND status IN ('active','victory_loot') LIMIT 1 FOR UPDATE");
        $stmt->execute(['encounter_id'=>$row['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function unlocks(int $characterId): array
    {
        $stmt = $this->pdo->prepare('SELECT skill_key,unlocked_at FROM character_skills WHERE character_id = :character_id ORDER BY skill_key');
        $stmt->execute(['character_id'=>$characterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function loadout(int $characterId): array
    {
        $stmt = $this->pdo->prepare('SELECT slot,skill_key,skill_type FROM character_skill_loadout WHERE character_id = :character_id ORDER BY slot');
        $stmt->execute(['character_id'=>$characterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function receipt(int $characterId, string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT command_type,request_fingerprint,result_payload FROM skill_mutation_requests WHERE character_id = :character_id AND request_token = :token LIMIT 1 FOR UPDATE');
        $stmt->execute(['character_id'=>$characterId,'token'=>$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
    public function complete(int $characterId, string $token, string $command, string $fingerprint, array $payload): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO skill_mutation_requests (character_id,request_token,command_type,request_fingerprint,result_payload) VALUES (:character_id,:token,:command,:fingerprint,:payload)');
        $stmt->execute(['character_id'=>$characterId,'token'=>$token,'command'=>$command,'fingerprint'=>$fingerprint,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]);
    }
    public function unlock(int $characterId, string $key): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO character_skills (character_id,skill_key) VALUES (:character_id,:skill_key)');
        $stmt->execute(['character_id'=>$characterId,'skill_key'=>$key]);
    }

    /** Serialized by the owned Champion lock. Delete source and target then insert once. */
    public function assign(int $characterId, string $slot, string $key, string $type): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM character_skill_loadout WHERE character_id = :character_id AND (slot = :slot OR skill_key = :skill_key)');
        $stmt->execute(['character_id'=>$characterId,'slot'=>$slot,'skill_key'=>$key]);
        $stmt = $this->pdo->prepare('INSERT INTO character_skill_loadout (character_id,slot,skill_key,skill_type) VALUES (:character_id,:slot,:skill_key,:skill_type)');
        $stmt->execute(['character_id'=>$characterId,'slot'=>$slot,'skill_key'=>$key,'skill_type'=>$type]);
    }
    public function unassign(int $characterId, string $slot): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM character_skill_loadout WHERE character_id = :character_id AND slot = :slot');
        $stmt->execute(['character_id'=>$characterId,'slot'=>$slot]);
    }
}
