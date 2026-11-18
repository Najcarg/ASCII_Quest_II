<?php
declare(strict_types=1);
require_once __DIR__ . '/SkillCatalogue.php';

final class SkillService
{
    public function __construct(private object $repository, private SkillCatalogue $catalogue) {}
    public function catalogue(): SkillCatalogue { return $this->catalogue; }

    public static function points(array $character, int $spent): array
    {
        $stored = CharacterProgression::nonNegativeInteger($character['level'] ?? null);
        if ($stored < 1 || $stored > CharacterProgression::MAX_LEVEL || $spent < 0) { throw new InvalidArgumentException('Invalid skill progression.'); }
        $level = CharacterProgression::project($character)['level'];
        $earned = max(0, $level - 1);
        return ['earned'=>$earned,'spent'=>$spent,'available'=>max(0,$earned-$spent)];
    }

    public function state(int $userId, int $characterId): array
    {
        $this->validateIdentity($userId,$characterId);
        $this->repository->begin();
        try {
            $character = $this->authority($userId,$characterId);
            $this->repository->lockAccount($userId);
            $character=$this->resolveClass($character);
            $encounter = $this->repository->encounter($userId,true);
            $this->catalogue = $this->repository->catalogue();
            $state = $this->project($character,$encounter);
            $this->repository->commit();
            return $state;
        } catch (Throwable $e) { $this->repository->rollback(); throw $e; }
    }

    public function mutate(int $userId, int $characterId, string $command, ?string $key, ?string $slot, string $token): array
    {
        $this->validateIdentity($userId,$characterId);
        if (!in_array($command,['unlock','assign','unassign'],true)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',$token)!==1
            || ($command !== 'unassign' && (!is_string($key) || preg_match('/\A[a-z][a-z0-9_]{1,63}\z/D',$key)!==1))
            || ($command === 'unlock' && $slot !== null) || ($command === 'unassign' && $key !== null)
            || ($command !== 'unlock' && !in_array($slot,SkillCatalogue::SLOTS,true))) {
            throw new InvalidArgumentException('Invalid skill intent.');
        }
        $fingerprint = hash('sha256',json_encode([$command,$characterId,$key,$slot],JSON_THROW_ON_ERROR));
        $this->repository->begin();
        try {
            $character = $this->authority($userId,$characterId);
            $this->repository->lockAccount($userId);
            $character=$this->resolveClass($character);
            $encounter = $this->repository->encounter($userId,true);
            $receipt = $this->repository->receipt($characterId,$token);
            if ($receipt !== null) {
                if ($receipt['command_type'] !== $command || !hash_equals($receipt['request_fingerprint'],$fingerprint)) { throw new DomainException('Skill token collision.'); }
                $result = json_decode($receipt['result_payload'],true,16,JSON_THROW_ON_ERROR);
                if (!is_array($result)) { throw new UnexpectedValueException('Invalid skill receipt.'); }
                $this->repository->commit();
                return $result;
            }
            if (($character['life_state'] ?? null) !== 'alive' || (int) ($character['current_hp'] ?? 0) <= 0) { throw new DomainException('Champion unavailable.'); }
            $this->catalogue = $this->repository->catalogue();
            $unlocks = $this->repository->unlocks($characterId);
            $learned = array_column($unlocks,'skill_key');
            $points = self::points($character,count($unlocks));
            $loadout = $this->loadoutMap($this->repository->loadout($characterId));
            if ($command === 'unlock') {
                $skill = $this->catalogue->skill((string)$key) ?? throw new DomainException('Skill unavailable.');
                $reason = $this->unlockReason($character,$skill,$learned,$points);
                if ($reason !== null) { throw new DomainException('Skill unlock unavailable: '.$reason); }
                $this->repository->unlock($characterId,(string)$key);
                $result = ['result'=>'unlocked','skill_key'=>$key,'cost'=>1];
            } else {
                if ($encounter !== null) { throw new DomainException('Loadout unavailable during combat.'); }
                if ($command === 'assign') {
                    $skill = $this->catalogue->skill((string)$key) ?? throw new DomainException('Skill unavailable.');
                    $reason = $this->useReason($character,$skill,$learned);
                    if ($reason !== null || !SkillCatalogue::compatible($skill['skill_type'],(string)$slot)) { throw new DomainException('Skill assignment unavailable.'); }
                    $source = array_search($key,$loadout,true);
                    $displaced = $loadout[$slot] ?? null;
                    if ($source !== $slot) { $this->repository->assign($characterId,(string)$slot,(string)$key,$skill['skill_type']); }
                    $result = ['result'=>'assigned','skill_key'=>$key,'slot'=>$slot,'source_slot'=>$source===false?null:$source,'displaced_skill_key'=>$displaced===$key?null:$displaced];
                } else {
                    $old = $loadout[$slot] ?? null;
                    if ($old !== null) {
                        $oldSkill=$this->catalogue->skill($old) ?? throw new DomainException('Skill unavailable.');
                        if ($this->useReason($character,$oldSkill,$learned)!==null || !SkillCatalogue::compatible($oldSkill['skill_type'],(string)$slot)) { throw new DomainException('Skill unassignment unavailable.'); }
                    }
                    $this->repository->unassign($characterId,(string)$slot);
                    $result = ['result'=>'unassigned','slot'=>$slot,'skill_key'=>$old];
                }
            }
            $this->repository->complete($characterId,$token,$command,$fingerprint,$result);
            $this->repository->commit();
            return $result;
        } catch (Throwable $e) { $this->repository->rollback(); throw $e; }
    }

    /** Called only inside CombatAccessGuard's owned Champion aggregate transaction. */
    public function assignedAction(array $character, string $key, ?string $slot): array
    {
        if ($slot === null || !in_array($slot,SkillCatalogue::SLOTS,true)) { throw new DomainException('Assigned skill slot required.'); }
        foreach ($this->combatSlots($character) as $candidate) {
            if ($candidate['slot']===$slot && $candidate['key']===$key && $candidate['disabled_reason']===null) { return $candidate['definition']; }
        }
        throw new DomainException('Skill is not currently assigned and usable.');
    }

    public function combatSlots(array $lockedCharacter): array
    {
        $character = $this->repository->ownedCharacter((int)$lockedCharacter['user_id'],(int)$lockedCharacter['id']);
        if ($character === null) { throw new OutOfBoundsException('Champion unavailable.'); }
        // Use synchronized values (including death/victory) while trusting only DB class identity.
        foreach (['level','experience','life_state','current_hp'] as $field) {
            if (array_key_exists($field,$lockedCharacter)) { $character[$field]=$lockedCharacter[$field]; }
        }
        self::points($character,0); // Reject malformed progression even with an empty loadout.
        $learned = array_column($this->repository->unlocks((int)$character['id']),'skill_key');
        $map = $this->loadoutMap($this->repository->loadout((int)$character['id']));
        $slots = [];
        foreach (SkillCatalogue::SLOTS as $slot) {
            $key = $map[$slot] ?? null;
            $skill = $key===null?null:$this->catalogue->skill($key);
            $reason = $key===null?'empty_slot':($skill===null?'definition_unavailable':$this->useReason($character,$skill,$learned));
            if ($skill !== null && !SkillCatalogue::compatible($skill['skill_type'],$slot)) { $reason='incompatible_slot'; }
            $visible = $skill!==null && $skill['class_key']===$character['class_key'];
            $slots[] = ['slot'=>$slot,'key'=>$visible?$key:null,'name'=>$visible?$skill['display_name']:null,
                'disabled_reason'=>$reason,'definition'=>$visible?$this->catalogue->action($key,$slot):null,
                'effect'=>$visible?SkillCatalogue::effectDisplay($skill):null];
        }
        return $slots;
    }

    /** Shared authoritative projection inside the already-owned combat transaction. */
    public function stateForCombat(array $lockedCharacter,array $encounter): array
    {
        $character=$this->repository->ownedCharacter((int)$lockedCharacter['user_id'],(int)$lockedCharacter['id']);
        if ($character===null) { throw new OutOfBoundsException('Champion unavailable.'); }
        foreach (['level','experience','life_state','current_hp'] as $field) {
            if (array_key_exists($field,$lockedCharacter)) { $character[$field]=$lockedCharacter[$field]; }
        }
        return $this->project($character,in_array($encounter['status']??null,['active','victory_loot'],true)?$encounter:null);
    }

    private function project(array $character, ?array $encounter): array
    {
        $id=(int)$character['id'];
        $unlocks=$this->repository->unlocks($id); $learned=array_column($unlocks,'skill_key');
        $points=self::points($character,count($unlocks));
        $map=$this->loadoutMap($this->repository->loadout($id));
        $skills=[];
        foreach ($this->catalogue->forClass($character['class_key']) as $skill) {
            $key=$skill['skill_key']; $unlocked=in_array($key,$learned,true);
            $reason=$this->unlockReason($character,$skill,$learned,$points);
            $assignmentReason=$this->useReason($character,$skill,$learned) ?? ($encounter!==null?'combat_locked':null);
            $assigned=array_search($key,$map,true);
            $skills[]=[
                'key'=>$key,'name'=>$skill['display_name'],'description'=>$skill['description'],'kind'=>$skill['skill_type'],
                'type'=>$skill['damage_type'],'damage'=>$skill['base_damage'],'effect'=>SkillCatalogue::effectDisplay($skill),
                'duration_ms'=>$skill['duration_ms'],'cooldown_ms'=>$skill['cooldown_ms'],'minimum_level'=>$skill['minimum_level'],
                'ultimate'=>$skill['skill_type']==='ultimate','tree_position'=>$skill['tree_position'],
                'prerequisites'=>array_map(fn (string $p): array => ['key'=>$p,'name'=>$this->catalogue->skill($p)['display_name']],$skill['prerequisites']),
                'state'=>$unlocked?'unlocked':($reason===null?'available':'locked'),'reason'=>$reason,'assignment_reason'=>$assignmentReason,
                'assigned_slot'=>$assigned===false?null:$assigned,
            ];
        }
        $loadout=[];
        foreach ($this->combatSlots($character) as $slot) { $loadout[$slot['slot']]=['key'=>$slot['key'],'name'=>$slot['name'],'reason'=>$slot['disabled_reason']]; }
        return ['champion'=>['id'=>$id,'class_key'=>$character['class_key'],'level'=>CharacterProgression::project($character)['level'],'life_state'=>$character['life_state']],
            'points'=>$points,'skills'=>$skills,'loadout'=>$loadout,'loadout_locked'=>$encounter!==null || $character['life_state']!=='alive',
            'loadout_reason'=>$character['life_state']!=='alive'?'dead':($encounter!==null?'combat_locked':null)];
    }

    private function unlockReason(array $character, array $skill, array $learned, array $points): ?string
    {
        if (in_array($skill['skill_key'],$learned,true)) { return 'already_unlocked'; }
        if (($character['life_state']??null)!=='alive' || (int)($character['current_hp']??0)<=0) { return 'dead'; }
        if ($skill['class_key']!==$character['class_key']) { return 'wrong_class'; }
        if ($skill['is_active']!==1) { return 'inactive'; }
        if (CharacterProgression::project($character)['level']<$skill['minimum_level']) { return 'level_required'; }
        if (array_diff($skill['prerequisites'],$learned)!==[]) { return 'prerequisite_required'; }
        return $points['available']<1?'no_points':null;
    }
    private function useReason(array $character, array $skill, array $learned): ?string
    {
        if (($character['life_state']??null)!=='alive' || (int)($character['current_hp']??0)<=0) { return 'dead'; }
        if ($skill['class_key']!==$character['class_key']) { return 'wrong_class'; }
        if ($skill['is_active']!==1) { return 'inactive'; }
        if (!in_array($skill['skill_key'],$learned,true)) { return 'not_unlocked'; }
        if (CharacterProgression::project($character)['level']<$skill['minimum_level']) { return 'level_required'; }
        return array_diff($skill['prerequisites'],$learned)!==[]?'prerequisite_required':null;
    }
    private function loadoutMap(array $rows): array
    {
        $result=[]; $keys=[];
        foreach ($rows as $row) {
            if (!in_array($row['slot']??null,SkillCatalogue::SLOTS,true) || isset($result[$row['slot']]) || isset($keys[$row['skill_key']])) { throw new UnexpectedValueException('Invalid persisted loadout.'); }
            $result[$row['slot']]=$row['skill_key']; $keys[$row['skill_key']]=true;
        }
        return $result;
    }
    private function authority(int $userId,int $id): array
    {
        $character=$this->repository->ownedCharacter($userId,$id,true) ?? throw new OutOfBoundsException('Champion unavailable.');
        self::points($character,0);
        return $character;
    }
    private function resolveClass(array $character): array
    {
        $character['class_key'] ??= $this->repository->classKey((int)$character['class_id']);
        if (!in_array($character['class_key'],SkillCatalogue::CLASSES,true)) { throw new DomainException('Champion class unavailable.'); }
        return $character;
    }

    private function validateIdentity(int $userId,int $id): void
    {
        if ($userId<1 || $id<1) { throw new OutOfBoundsException('Champion unavailable.'); }
    }
}
