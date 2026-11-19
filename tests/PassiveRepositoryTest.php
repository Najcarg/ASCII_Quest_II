<?php
declare(strict_types=1);
require_once __DIR__.'/PassiveFixtures.php';
require_once __DIR__.'/../ascii-quest/lib/PassiveRepository.php';
require_once __DIR__.'/../ascii-quest/lib/EquipmentBootstrap.php';
/** SQL-boundary adapter. Never opens a DB or applies Migration014. */
final class PassiveFixturePdo extends PDO
{
    public array $character;public array $allocations=[];public array $receipts=[];public array $sql=[];public array $items=[];
    private ?array $backup=null;
    public function __construct(){ $this->character=passiveFixtureCharacter(); }
    public function beginTransaction():bool {if($this->backup!==null)throw new LogicException('Nested transaction');$this->backup=[$this->allocations,$this->receipts];return true;}
    public function inTransaction():bool{return $this->backup!==null;}
    public function commit():bool{$this->backup=null;return true;}
    public function rollBack():bool{[$this->allocations,$this->receipts]=$this->backup;$this->backup=null;return true;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{$this->sql[]=$query;return new PassiveFixtureStatement($this,$query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{$stmt=$this->prepare($query);$stmt->execute();return $stmt;}
}
final class PassiveFixtureStatement extends PDOStatement
{
    private array $rows=[];
    public function __construct(private PassiveFixturePdo $pdo,private string $sql){}
    public function execute(?array $params=null):bool
    {
        $sql=$this->sql;$p=$params??[];$this->rows=[];
        if(str_starts_with($sql,'SELECT * FROM passive_definitions')){$this->rows=passiveFixtureRows();}
        elseif(str_contains($sql,'FROM characters c WHERE c.id')){if(($p['character_id']??null)===42&&($p['user_id']??null)===7)$this->rows=[$this->pdo->character];}
        elseif(str_starts_with($sql,'SELECT id FROM users')){$this->rows=[['id'=>7]];}
        elseif(str_contains($sql,'FROM combat_encounters ce INNER JOIN')){$this->rows=[];}
        elseif(str_starts_with($sql,'SELECT passive_key,`row_number`')){$this->rows=$this->pdo->allocations;}
        elseif(str_starts_with($sql,'SELECT request_fingerprint,result_payload')){if(isset($this->pdo->receipts[$p['token']]))$this->rows=[$this->pdo->receipts[$p['token']]];}
        elseif(str_starts_with($sql,'INSERT INTO character_passives')){
            foreach($this->pdo->allocations as $row)if($row['passive_key']===$p['key']||($row['row_number']===$p['row']&&$row['pass_number']===$p['pass']))throw new RuntimeException('Unique violation');
            if(!in_array($p['pass'],[1,2],true))throw new RuntimeException('CHECK violation');
            if(passiveNode($p['row'],substr($p['key'],12,1))['key']!==$p['key'])throw new RuntimeException('Composite FK violation');
            $this->pdo->allocations[]=['passive_key'=>$p['key'],'row_number'=>$p['row'],'pass_number'=>$p['pass']];
        }
        elseif(str_starts_with($sql,'INSERT INTO passive_mutation_requests')){if(isset($this->pdo->receipts[$p['token']]))throw new RuntimeException('Token unique violation');$this->pdo->receipts[$p['token']]=['request_fingerprint'=>$p['fingerprint'],'result_payload'=>$p['payload']];}
        elseif(str_starts_with($sql,'SELECT ci.*, ce.equipment_slot')){$this->rows=$this->pdo->items;}
        elseif(str_starts_with($sql,'SELECT item_id, modifier_type')){$this->rows=[];}
        else throw new RuntimeException('Unexpected passive SQL '.$sql);
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return array_shift($this->rows)??false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
    public function fetchColumn(int $column=0):mixed{$row=array_shift($this->rows);return $row===null?false:array_values($row)[$column];}
}
$tests=[];
$tests['Task35 production PassiveRepository persists two passes replay and clean rollback through SQL boundary']=function():void{
    $pdo=new PassiveFixturePdo();$repo=new PassiveRepository($pdo);$service=new PassiveService($repo,$repo->catalogue());
    for($i=0;$i<40;$i++){$node=passiveNode($i%20+1,$i<20?'b':'c');$result=$service->unlock(7,42,$node['key'],passiveToken($i+1));assertSameValue($result,$service->unlock(7,42,$node['key'],passiveToken($i+1)),'Fresh repository durable replay.');}
    assertSameValue(40,count($pdo->allocations),'40 persisted rows.');assertSameValue(40,count($pdo->receipts),'40 durable receipts.');assertSameValue(true,$service->state(7,42)['progress']['complete'],'SQL read projection.');
    $saved=[$pdo->allocations,$pdo->receipts];passiveReject(fn()=>$service->unlock(7,42,passiveNode(20,'d')['key'],passiveToken(99)));assertSameValue($saved,[$pdo->allocations,$pdo->receipts],'No failed receipt.');
    $locks=array_values(array_filter($pdo->sql,static fn($sql)=>str_contains($sql,'FOR UPDATE')));
    assertSameValue(true,str_contains($locks[0],'FROM characters c'),'First Character lock.');assertSameValue(true,str_contains($locks[1],'FROM users'),'Then account lock.');
};
$tests['Task35 production EquipmentBootstrap repository aggregator stats and combat provider see same passives once']=function():void{
    $pdo=new PassiveFixturePdo();$pdo->allocations=passivePrefix(20);$catalogue=new PassiveCatalogue(passiveFixtureRows());
    $expected=(new PassiveStatAggregator())->aggregate($catalogue,$pdo->allocations);
    $stats=EquipmentBootstrap::stats($pdo,$pdo->character);
    $provider=new PersistentCombatEquipmentProvider(new EquipmentRepository($pdo),CombatDefinitionRegistry::fromDefaultConfig(),new EquipmentStatAggregator());
    assertSameValue($stats,$provider->characterStats($pdo->character),'UI and combat shared result.');
    assertSameValue(CharacterStats::calculate($pdo->character,[],null,$expected),$stats,'Exact single aggregation.');
    assertSameValue($expected,(new CombatRepository($pdo))->passiveModifiers(42),'Combat repository shared aggregator.');
};
return $tests;
