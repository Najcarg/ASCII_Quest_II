<?php
declare(strict_types=1);
require_once __DIR__.'/PassiveFixtures.php';
$tests=[];
$tests['Task35 passive accuracy crit dodge toughness and block use canonical resolver boundaries']=function():void{
    [$pdo,,$reader,$provider]=task25Fixture([],[]);$character=$pdo->characters[42];
    $reader->passives=['accuracy'=>5,'critical_chance'=>4,'critical_damage'=>10,'dodging'=>4,'toughness'=>8,'block_rate_bp'=>1500];
    $offense=$provider->offensiveSnapshot($character,'prototype_weapon_attack');
    [$hit]=task25Resolve($offense,[70,100,19]);assertSameValue('CRITICAL',$hit['outcome'],'Accuracy+5 allows70 and Crit15+4 includes19.');
    [$miss]=task25Resolve($offense,[71]);assertSameValue('MISS',$miss['outcome'],'Above70 misses.');
    $defense=$provider->currentDefense($character);$config=CombatDefinitionRegistry::fromDefaultConfig();
    $resolver=new PrototypeBlockResolver(new PrototypeChampionDamageResolver(),new Task6SequenceRandomSource([16]),$config->playerReaction('basic_block'));
    assertSameValue('DODGE',$resolver->resolve(['snapshot_base_damage'=>100,'snapshot_damage_type'=>'physical'],$defense,true)['outcome'],'Dodge12+4 includes16.');
    foreach([[23,true],[24,false]] as [$roll,$blocked]){
        $resolver=new PrototypeBlockResolver(new PrototypeChampionDamageResolver(),new Task6SequenceRandomSource([100,$roll]),$config->playerReaction('basic_block'));
        assertSameValue($blocked,$resolver->resolve(['snapshot_base_damage'=>100,'snapshot_damage_type'=>'physical'],$defense,true)['blocked'],'Base20*1.15=23, never+15points.');
    }
    $damage=(new PrototypeChampionDamageResolver())->resolve(['snapshot_base_damage'=>120,'snapshot_damage_type'=>'physical'],$defense);
    assertSameValue(100,$damage['applied_damage'],'Strength5 toughness12 +8 =20, reduction20/120.');
};
$tests['Task35 passive LoH still skips MISS skills dead and caps overheal']=function():void{
    foreach([[66,100,100],[1,198,200]] as [$roll,$hp,$expected]){
        [$pdo,$clock,$reader,$provider,$random]=task25Fixture([], $roll===66?[66]:[1,100,100],['current_hp'=>$hp]);$reader->passives=['life_on_hit'=>10,'healing_power_percent'=>100];
        $service=CombatBootstrap::serviceForRepository(new CombatRepository($pdo),$clock,$provider,$random);
        $service->startPlayerAction(7,42,'prototype_weapon_attack',passiveToken(1));task26Advance($clock);$service->state(7,42);
        assertSameValue($expected,$pdo->characters[42]['current_hp'],'MISS/cap semantics preserved.');
    }
    [$pdo,$clock,$reader,$provider,$random]=task25Fixture([], [1,100,100]);$reader->passives=['life_on_hit'=>10,'healing_power_percent'=>100];
    $service=CombatBootstrap::serviceForRepository(new CombatRepository($pdo),$clock,$provider,$random);
    $service->startPlayerAction(7,42,'prototype_flame_strike',passiveToken(2));task26Advance($clock,1.5);$service->state(7,42);
    assertSameValue(145,$pdo->characters[42]['current_hp'],'Skills do not gain passive LoH.');
    $pdo->characters[42]['current_hp']=0;$pdo->characters[42]['life_state']='dead';
    passiveReject(fn()=>$service->startPlayerAction(7,42,'prototype_weapon_attack',passiveToken(3)));
    assertSameValue(0,$pdo->characters[42]['current_hp'],'Never revive.');
};
$tests['Task35 Healing Power does not amplify potion healing or change Mana']=function():void{
    [$pdo,$clock,$reader,$provider,$random]=task25Fixture([],[],['current_hp'=>100]);task8Encounter($pdo);
    $reader->passives=['healing_power_percent'=>100];$beforeMana=$pdo->characters[42]['current_mana'];
    $service=CombatBootstrap::serviceForRepository(new CombatRepository($pdo),$clock,$provider,$random);$service->usePotion(7,42,passiveToken(1));
    $amount=CombatDefinitionRegistry::fromDefaultConfig()->potion('prototype_health_potion')['prototype_healing'];
    assertSameValue(100+$amount,$pdo->characters[42]['current_hp'],'Potion fixed amount not multiplied by2.');
    assertSameValue($beforeMana,$pdo->characters[42]['current_mana'],'No Mana restoration invented.');
};
foreach([['mage',0,'burn'],['mage',1,'freeze'],['mage',2,'shock'],['rogue',1,'poison'],['rogue',2,'bleed']] as [$class,$index,$status]){
    $tests["Task35 frozen intrinsic $status receives only elemental chance bonus and unchanged cooldown"]=function()use($class,$index,$status):void{
        [$pdo,$clock,,$skillRepo,$skills,$keys]=task34bCombat($class);
        $definition=$skills->catalogue()->skill($keys[$index]);
        // The authoritative catalogue determines status identity for each skill.
        if($definition['status_key']!==$status){throw new RuntimeException('Fixture status identity mismatch');}
        $baseChance=SkillCatalogue::effectDisplay($definition)['chance_percent'];$elemental=in_array($status,['burn','freeze','shock'],true);$roll=(int)$baseChance+10;
        $reader=new FakeCombatEquipmentReader([]);$reader->passives=['elemental_status_chance_percent'=>10,'cast_rate_bp'=>1000];
        $provider=new PersistentCombatEquipmentProvider($reader,CombatDefinitionRegistry::withSkillCatalogue($skills->catalogue()),new EquipmentStatAggregator());
        $random=new Task6SequenceRandomSource([1,100,100,$roll]);
        $service=CombatBootstrap::serviceForRepository(new CombatRepository($pdo),$clock,$provider,$random,null,$skills);
        $service->startPlayerAction(7,42,$keys[$index],passiveToken(1),SkillCatalogue::SLOTS[$index]);$action=$pdo->actions[1];
        assertSameValue((int)round($definition['duration_ms']/1.1),$action['resolves_timeline_ms'],'Skill duration uses current cast rate.');
        assertSameValue($definition['cooldown_ms'],$action['cooldown_ready_timeline_ms'],'Base cooldown untouched.');
        $reader->passives=['elemental_status_chance_percent'=>100];$clock->set((new DateTimeImmutable('2026-09-01 12:00:00.000000',new DateTimeZone('UTC')))->modify('+'.$action['resolves_timeline_ms'].' milliseconds'));
        $service->state(7,42);assertSameValue($elemental?[$status]:[],array_column(task29Active($pdo),'status_key'),'Frozen elemental-only bonus; Poison/Bleed unchanged.');
    };
}
$tests['Task35 elemental status bonus never creates absent statuses capped90 legacy NULL unchanged']=function():void{
    [$pdo,$clock,$reader,$provider,$random]=task25Fixture([],[1,100,100]);$reader->passives=['elemental_status_chance_percent'=>10];
    $service=CombatBootstrap::serviceForRepository(new CombatRepository($pdo),$clock,$provider,$random);$service->startPlayerAction(7,42,'prototype_weapon_attack',passiveToken(1));task26Advance($clock);$service->state(7,42);
    assertSameValue([],$pdo->statuses,'Requires an existing status source.');assertSameValue(3,$random->integerCalls,'No new random rolls.');
    assertFloatValue(90.0,CharacterStats::elementalStatusChance(90,'burn',10),'Existing status cap90.');assertFloatValue(25.0,CharacterStats::elementalStatusChance(25,'poison',10),'Poison no bonus.');
    [$pdo,$clock,$reader,$provider,$random]=task29Fixture(['burn'=>5],[1,100,100,26]);$reader->passives=['elemental_status_chance_percent'=>100];
    $service=CombatBootstrap::serviceForRepository(new CombatRepository($pdo),$clock,$provider,$random);$service->startPlayerAction(7,42,'prototype_weapon_attack',passiveToken(2));$pdo->actions[1]['snapshot_elemental_status_chance']=null;task26Advance($clock);$service->state(7,42);
    assertSameValue([],task29Active($pdo),'Legacy NULL is0, never current bonus substitution.');
};
return $tests;
