<?php
declare(strict_types=1);
require_once __DIR__.'/SkillRepository.php';
require_once __DIR__.'/PassiveCatalogue.php';

final class PassiveRepository
{
    private SkillRepository $authority;
    public function __construct(private PDO $pdo) { $this->authority=new SkillRepository($pdo); }
    public function begin(): void { $this->authority->begin(); }
    public function commit(): void { $this->authority->commit(); }
    public function rollback(): void { $this->authority->rollback(); }
    public function ownedCharacter(int $userId,int $id,bool $lock=false): ?array { return $this->authority->ownedCharacter($userId,$id,$lock); }
    public function lockAccount(int $userId): void { $this->authority->lockAccount($userId); }
    public function encounter(int $userId,bool $lock=false): ?array { return $this->authority->encounter($userId,$lock); }
    public function catalogue(): PassiveCatalogue
    {
        return new PassiveCatalogue($this->pdo->query('SELECT * FROM passive_definitions ORDER BY `row_number`,lane_key')->fetchAll(PDO::FETCH_ASSOC));
    }
    public function unlocks(int $id): array
    {
        $stmt=$this->pdo->prepare('SELECT passive_key,`row_number`,pass_number,unlocked_at FROM character_passives WHERE character_id=:id ORDER BY pass_number,`row_number`');
        $stmt->execute(['id'=>$id]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function receipt(int $id,string $token): ?array
    {
        $stmt=$this->pdo->prepare('SELECT request_fingerprint,result_payload FROM passive_mutation_requests WHERE character_id=:id AND request_token=:token FOR UPDATE');
        $stmt->execute(['id'=>$id,'token'=>$token]); $row=$stmt->fetch(PDO::FETCH_ASSOC); return is_array($row)?$row:null;
    }
    public function unlock(int $id,string $key,int $row,int $pass): void
    {
        $stmt=$this->pdo->prepare('INSERT INTO character_passives (character_id,passive_key,`row_number`,pass_number) VALUES (:id,:key,:row,:pass)');
        $stmt->execute(['id'=>$id,'key'=>$key,'row'=>$row,'pass'=>$pass]);
    }
    public function complete(int $id,string $token,string $fingerprint,array $result): void
    {
        $stmt=$this->pdo->prepare('INSERT INTO passive_mutation_requests (character_id,request_token,request_fingerprint,result_payload) VALUES (:id,:token,:fingerprint,:payload)');
        $stmt->execute(['id'=>$id,'token'=>$token,'fingerprint'=>$fingerprint,'payload'=>json_encode($result,JSON_THROW_ON_ERROR)]);
    }
}
