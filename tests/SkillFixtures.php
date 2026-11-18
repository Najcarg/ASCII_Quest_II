<?php
declare(strict_types=1);
require_once __DIR__ . '/../ascii-quest/lib/SkillBootstrap.php';
require_once __DIR__ . '/fixtures/skill_catalogue.php';

final class FakeSkillRepository
{
    public array $characters=[];
    public array $definitions;
    public array $edges;
    public array $learned=[];
    public array $assignments=[];
    public array $receipts=[];
    public ?array $battle=null;
    public bool $failInsert=false;
    public bool $failReceipt=false;
    public bool $failAssignment=false;
    public array $locks=[];
    public bool $resolveClassAfterMutex=false;
    private ?array $snapshot=null;
    public function __construct(string $class='warrior',int $level=5)
    {
        [$this->definitions,$this->edges]=task34bCatalogueRows();
        $this->characters[42]=['id'=>42,'user_id'=>7,'class_key'=>$class,'level'=>$level,'experience'=>100*($level-1)**2,'life_state'=>'alive','current_hp'=>145];
        $this->characters[43]=['id'=>43,'user_id'=>7,'class_key'=>$class,'level'=>1,'experience'=>0,'life_state'=>'alive','current_hp'=>145];
    }
    public function catalogue(): SkillCatalogue { return new SkillCatalogue($this->definitions,$this->edges); }
    public function begin(): void
    {
        if ($this->snapshot!==null) { throw new RuntimeException('Concurrent Champion transaction waits/retries.'); }
        $this->snapshot=[$this->characters,$this->learned,$this->assignments,$this->receipts];
    }
    public function commit(): void { $this->snapshot=null; }
    public function rollback(): void
    {
        if ($this->snapshot!==null) { [$this->characters,$this->learned,$this->assignments,$this->receipts]=$this->snapshot; $this->snapshot=null; }
    }
    public function ownedCharacter(int $user,int $id,bool $lock=false): ?array
    {
        if ($lock) { $this->locks[]='character'; }
        $row=$this->characters[$id]??null;
        if ($row===null || $row['user_id']!==$user) { return null; }
        if ($lock && $this->resolveClassAfterMutex) {
            $row['class_id']=array_search($row['class_key'],SkillCatalogue::CLASSES,true)+1;unset($row['class_key']);
        }
        return $row;
    }
    public function classKey(int $id): string {
        if (end($this->locks)!=='account') { throw new RuntimeException('Repeatable-read snapshot before account mutex.'); }
        $this->locks[]='class';return SkillCatalogue::CLASSES[$id-1];
    }
    public function lockAccount(int $id): void { $this->locks[]='account'; }
    public function encounter(int $user,bool $lock=false): ?array { if ($lock) { $this->locks[]='encounter'; } return $this->battle; }
    public function unlocks(int $id): array { return array_map(static fn($k)=>['skill_key'=>$k,'unlocked_at'=>'fixture'], $this->learned[$id]??[]); }
    public function loadout(int $id): array { return array_values($this->assignments[$id]??[]); }
    public function receipt(int $id,string $token): ?array { return $this->receipts[$id][$token]??null; }
    public function complete(int $id,string $token,string $cmd,string $fp,array $result): void
    {
        if ($this->failReceipt) { throw new RuntimeException('Injected receipt failure.'); }
        if (isset($this->receipts[$id][$token])) { throw new RuntimeException('Unique receipt collision.'); }
        $this->receipts[$id][$token]=['command_type'=>$cmd,'request_fingerprint'=>$fp,'result_payload'=>json_encode($result)];
    }
    public function unlock(int $id,string $key): void
    {
        if ($this->failInsert) { throw new RuntimeException('Injected unlock failure.'); }
        if (in_array($key,$this->learned[$id]??[],true)) { throw new RuntimeException('Unique unlock violation.'); }
        $this->learned[$id][]=$key;
    }
    public function assign(int $id,string $slot,string $key,string $type): void
    {
        foreach ($this->assignments[$id]??[] as $oldSlot=>$row) {
            if ($oldSlot===$slot || $row['skill_key']===$key) { unset($this->assignments[$id][$oldSlot]); }
        }
        if ($this->failAssignment) { throw new RuntimeException('Injected replacement failure.'); }
        $this->assignments[$id][$slot]=['slot'=>$slot,'skill_key'=>$key,'skill_type'=>$type];
    }
    public function unassign(int $id,string $slot): void { unset($this->assignments[$id][$slot]); }
}
function task34bToken(int $i=1): string { return '34b00000-0000-4000-8000-'.str_pad((string)$i,12,'0',STR_PAD_LEFT); }
function task34bService(?FakeSkillRepository $repo=null): array
{
    $repo??=new FakeSkillRepository();
    return [$repo,new SkillService($repo,$repo->catalogue())];
}
function task34bLearnClass(FakeSkillRepository $repo,SkillService $service): array
{
    $keys=array_column($service->catalogue()->forClass($repo->characters[42]['class_key']),'skill_key');
    foreach ($keys as $i=>$key) { $service->mutate(7,42,'unlock',$key,null,task34bToken(100+$i)); }
    return $keys;
}
