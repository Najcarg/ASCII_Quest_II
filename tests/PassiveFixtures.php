<?php
declare(strict_types=1);
require_once __DIR__.'/../ascii-quest/lib/PassiveService.php';
require_once __DIR__.'/../ascii-quest/lib/PassiveStatAggregator.php';

/** Read catalogue SQL literals; never execute/apply the migration. */
function passiveFixtureRows(): array
{
    $sql=file_get_contents(__DIR__.'/../database/migrations/014_universal_passive_tree.sql');
    preg_match_all('/^    \((\'passive_r[^\n]+)\)[,;]$/m',$sql,$matches);
    $rows=[];
    foreach ($matches[1] as $tuple) {
        $values=str_getcsv($tuple,',',"'",'\\');
        $row=array_combine(['passive_key','row_number','lane_key','display_name','description','effect_json','is_major','is_active'],$values);
        foreach (['row_number','is_major','is_active'] as $key) { $row[$key]=(int)$row[$key]; }
        $rows[]=$row;
    }
    return $rows;
}
function passiveFixtureCharacter(int $level=90,string $class='warrior'): array
{
    return ['id'=>42,'user_id'=>7,'class_key'=>$class,'class_id'=>1,'level'=>$level,'experience'=>100*($level-1)**2,'life_state'=>'alive','current_hp'=>100,'current_mana'=>50,'strength'=>5,'dexterity'=>5,'vitality'=>5,'energy'=>5,'fate'=>5];
}
final class PassiveMemoryRepository
{
    public array $character;
    public array $allocations=[];
    public array $receipts=[];
    public ?array $combat=null;
    public bool $failComplete=false;
    public array $events=[];
    private ?array $backup=null;
    public function __construct(int $level=90,string $class='warrior') { $this->character=passiveFixtureCharacter($level,$class); }
    public function catalogue(): PassiveCatalogue { return new PassiveCatalogue(passiveFixtureRows()); }
    public function begin(): void { if ($this->backup!==null) { throw new LogicException('Nested concurrent transaction blocked.'); } $this->backup=[$this->allocations,$this->receipts]; $this->events[]='begin'; }
    public function commit(): void { $this->backup=null; $this->events[]='commit'; }
    public function rollback(): void { if ($this->backup!==null) { [$this->allocations,$this->receipts]=$this->backup; } $this->backup=null; $this->events[]='rollback'; }
    public function ownedCharacter(int $user,int $id,bool $lock=false): ?array { $this->events[]='character_lock'; return $user===7 && $id===42?$this->character:null; }
    public function lockAccount(int $user): void { $this->events[]='account_lock'; }
    public function encounter(int $user,bool $lock=false): ?array { $this->events[]='encounter'; return $this->combat; }
    public function unlocks(int $id): array { return $this->allocations; }
    public function receipt(int $id,string $token): ?array { return $this->receipts[$token]??null; }
    public function unlock(int $id,string $key,int $row,int $pass): void { $this->allocations[]=['passive_key'=>$key,'row_number'=>$row,'pass_number'=>$pass,'unlocked_at'=>'2026-01-01 00:00:00']; }
    public function complete(int $id,string $token,string $fingerprint,array $result): void
    {
        if ($this->failComplete) { throw new RuntimeException('Injected receipt failure.'); }
        $this->receipts[$token]=['request_fingerprint'=>$fingerprint,'result_payload'=>json_encode($result,JSON_THROW_ON_ERROR)];
    }
}
function passiveFixture(int $level=90,string $class='warrior'): array
{
    $repo=new PassiveMemoryRepository($level,$class);
    return [$repo,new PassiveService($repo,$repo->catalogue())];
}
function passiveToken(int $number): string { return sprintf('00000000-0000-4000-8000-%012d',$number); }
function passiveNode(int $row,string $lane='a'): array
{
    foreach ((new PassiveCatalogue(passiveFixtureRows()))->all() as $node) { if ($node['row']===$row && $node['lane']===$lane) { return $node; } }
    throw new LogicException('Missing fixture node.');
}
function passivePrefix(int $spent,string $first='a',string $second='b'): array
{
    $rows=[];
    for ($i=0;$i<$spent;$i++) {
        $row=$i%20+1; $pass=intdiv($i,20)+1;
        $node=passiveNode($row,$pass===1?$first:$second);
        $rows[]=['passive_key'=>$node['key'],'row_number'=>$row,'pass_number'=>$pass];
    }
    return $rows;
}
function passiveReject(callable $call,string $class=DomainException::class): void
{
    try { $call(); } catch (Throwable $e) { if ($e instanceof $class) { return; } throw $e; }
    throw new RuntimeException('Expected rejection '.$class);
}
