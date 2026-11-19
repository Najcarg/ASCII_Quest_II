<?php
declare(strict_types=1);
require_once __DIR__.'/PassiveCatalogue.php';

final class PassiveService
{
    public function __construct(private object $repository,private PassiveCatalogue $catalogue) {}
    public static function points(array $character,int $spent): array
    {
        $stored=CharacterProgression::nonNegativeInteger($character['level']??null);
        if ($stored<1 || $stored>CharacterProgression::MAX_LEVEL || $spent<0 || $spent>40) { throw new InvalidArgumentException('Invalid passive progression.'); }
        $level=CharacterProgression::project($character)['level'];
        $earned=max(0,min(40,$level-50));
        return ['earned'=>$earned,'spent'=>$spent,'available'=>max(0,$earned-$spent)];
    }
    /** Validate persisted history as a prefix of the two sequential passes. */
    public function progress(array $unlocks): array
    {
        $owned=[]; $slots=[];
        foreach ($unlocks as $unlock) {
            $node=$this->catalogue->node($unlock['passive_key']??'')??throw new UnexpectedValueException('Unknown allocated passive.');
            $pass=CharacterProgression::nonNegativeInteger($unlock['pass_number']??null);
            $row=CharacterProgression::nonNegativeInteger($unlock['row_number']??null);
            $index=($pass-1)*20+$row;
            if (!in_array($pass,[1,2],true) || $row!==$node['row'] || isset($owned[$node['key']]) || isset($slots[$index])) { throw new UnexpectedValueException('Invalid passive history.'); }
            $owned[$node['key']]=$pass; $slots[$index]=true;
        }
        if ($slots!==[]) { ksort($slots); if (array_keys($slots)!==range(1,count($slots))) { throw new UnexpectedValueException('Nonsequential passive history.'); } }
        $spent=count($owned); $complete=$spent===40;
        return ['owned'=>$owned,'current_pass'=>$complete?null:intdiv($spent,20)+1,'next_row'=>$complete?null:($spent%20)+1,'completed_passes'=>intdiv($spent,20),'complete'=>$complete];
    }
    public function state(int $userId,int $characterId): array
    {
        $this->identity($userId,$characterId);
        $this->repository->begin();
        try {
            $character=$this->authority($userId,$characterId);
            $this->repository->lockAccount($userId);
            $encounter=$this->repository->encounter($userId,true);
            $this->catalogue=$this->repository->catalogue();
            $state=$this->project($character,$this->repository->unlocks($characterId),$encounter);
            $this->repository->commit(); return $state;
        } catch (Throwable $e) { $this->repository->rollback(); throw $e; }
    }
    public function unlock(int $userId,int $characterId,string $key,string $token): array
    {
        $this->identity($userId,$characterId);
        if (preg_match('/\Apassive_r[0-9]{2}_[a-d]_[a-z_]+\z/D',$key)!==1 || strlen($key)>64
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',$token)!==1) { throw new InvalidArgumentException('Invalid passive intent.'); }
        $fingerprint=hash('sha256',json_encode(['unlock',$characterId,$key],JSON_THROW_ON_ERROR));
        $this->repository->begin();
        try {
            $character=$this->authority($userId,$characterId);
            $this->repository->lockAccount($userId);
            $encounter=$this->repository->encounter($userId,true);
            $receipt=$this->repository->receipt($characterId,$token);
            if ($receipt!==null) {
                if (!hash_equals($receipt['request_fingerprint'],$fingerprint)) { throw new DomainException('Passive token collision.'); }
                $result=json_decode($receipt['result_payload'],true,16,JSON_THROW_ON_ERROR);
                if (!is_array($result)) { throw new UnexpectedValueException('Invalid passive receipt.'); }
                $this->repository->commit(); return $result;
            }
            $this->catalogue=$this->repository->catalogue();
            $unlocks=$this->repository->unlocks($characterId);
            $progress=$this->progress($unlocks); $points=self::points($character,count($unlocks));
            $node=$this->catalogue->node($key)??throw new DomainException('Passive unavailable.');
            $reason=$this->reason($character,$node,$progress,$points,$encounter);
            if ($reason!==null) { throw new DomainException('Passive unlock unavailable: '.$reason); }
            $this->repository->unlock($characterId,$key,$node['row'],$progress['current_pass']);
            $result=['result'=>'unlocked','passive_key'=>$key,'row'=>$node['row'],'pass_number'=>$progress['current_pass'],'cost'=>1];
            $this->repository->complete($characterId,$token,$fingerprint,$result);
            $this->repository->commit(); return $result;
        } catch (Throwable $e) { $this->repository->rollback(); throw $e; }
    }
    public function project(array $character,array $unlocks,?array $encounter=null): array
    {
        $progress=$this->progress($unlocks); $points=self::points($character,count($unlocks));
        $rows=[];
        foreach ($this->catalogue->all() as $node) {
            $pass=$progress['owned'][$node['key']]??null;
            $reason=$this->reason($character,$node,$progress,$points,$encounter);
            unset($node['active']);
            $node+=['state'=>$pass!==null?'unlocked':($reason===null?'available':'locked'),'reason'=>$reason,'unlocked_pass'=>$pass];
            $rows[$node['row']][]=$node;
        }
        unset($progress['owned']);
        $level=CharacterProgression::project($character)['level'];
        return ['champion'=>['id'=>(int)$character['id'],'level'=>$level,'life_state'=>$character['life_state']],
            'points'=>$points,'progress'=>$progress,'rows'=>array_map(static fn(int $row):array=>['row'=>$row,'nodes'=>$rows[$row]],range(1,20)),
            'ascendancy_unlocked'=>$level>=100];
    }
    private function reason(array $character,array $node,array $progress,array $points,?array $encounter): ?string
    {
        if (isset($progress['owned'][$node['key']])) { return 'already_unlocked'; }
        if (($character['life_state']??null)!=='alive' || (int)($character['current_hp']??0)<=0) { return 'dead'; }
        if ($encounter!==null) { return 'combat_locked'; }
        if (!$node['active']) { return 'inactive'; }
        if ($progress['complete']) { return 'tree_complete'; }
        if ($node['row']!==$progress['next_row']) { return 'row_required'; }
        return $points['available']<1?'no_points':null;
    }
    private function authority(int $userId,int $id): array
    {
        $character=$this->repository->ownedCharacter($userId,$id,true)??throw new OutOfBoundsException('Champion unavailable.');
        self::points($character,0); return $character;
    }
    private function identity(int $userId,int $id): void
    {
        if ($userId<1 || $id<1) { throw new OutOfBoundsException('Champion unavailable.'); }
    }
}
