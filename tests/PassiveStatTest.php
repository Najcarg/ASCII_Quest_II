<?php
declare(strict_types=1);
require_once __DIR__.'/PassiveFixtures.php';
require_once __DIR__.'/../ascii-quest/lib/CharacterStats.php';
// Independent design contract. Runtime PHP/JS NEVER use this test-only table.
$board=[
 [['strength'=>3],['energy'=>3],['dexterity'=>3],['vitality'=>3]],
 [['maximum_life'=>25],['maximum_mana'=>20],['accuracy'=>5],['maximum_life'=>40]],
 [['physical_damage'=>5],['fire_damage'=>5],['critical_chance'=>4],['healing_power_percent'=>5]],
 [['block_rate_bp'=>1000],['cold_damage'=>5],['critical_damage'=>10],['life_on_hit'=>3]],
 [['toughness'=>4],['lightning_damage'=>5],['dodging'=>4],['fire_resistance'=>4,'cold_resistance'=>4,'lightning_resistance'=>4]],
 [['physical_damage'=>10],['fire_resistance'=>5],['poison_damage'=>5],['healing_power_percent'=>10]],
 [['maximum_life'=>50],['cold_resistance'=>5],['bleed_damage'=>5],['block_rate_bp'=>1500]],
 [['strength'=>5],['lightning_resistance'=>5],['accuracy'=>8],['vitality'=>5]],
 [['life_on_hit'=>5],['maximum_mana'=>35],['critical_chance'=>6],['maximum_life'=>60]],
 [['physical_damage'=>10],['fire_damage'=>10],['critical_damage'=>15],['healing_power_percent'=>10]],
 [['toughness'=>6],['cold_damage'=>10],['poison_damage'=>10],['life_on_hit'=>5]],
 [['block_rate_bp'=>1500],['lightning_damage'=>10],['bleed_damage'=>10],['fire_resistance'=>6,'cold_resistance'=>6,'lightning_resistance'=>6]],
 [['strength'=>7],['energy'=>7],['dexterity'=>7],['vitality'=>7]],
 [['maximum_life'=>75],['maximum_mana'=>50],['accuracy'=>10],['healing_power_percent'=>15]],
 [['physical_damage'=>20],['fire_damage'=>20],['critical_chance'=>8],['life_on_hit'=>8]],
 [['toughness'=>8],['cold_damage'=>20],['critical_damage'=>20],['maximum_life'=>100]],
 [['block_rate_bp'=>2000],['lightning_damage'=>20],['poison_damage'=>20],['healing_power_percent'=>20]],
 [['life_on_hit'=>10],['cast_rate_bp'=>1000],['bleed_damage'=>20],['fire_resistance'=>10,'cold_resistance'=>10,'lightning_resistance'=>10]],
 [['physical_damage'=>20],['elemental_status_chance_percent'=>10],['critical_damage'=>25],['maximum_life'=>125]],
 [['physical_damage'=>30],['fire_damage'=>20,'cold_damage'=>20,'lightning_damage'=>20],['critical_damage'=>40],['maximum_life'=>200,'healing_power_percent'=>25]],
];
$tests=[];
foreach ($board as $index=>$lanes) foreach ($lanes as $laneIndex=>$effects) {
    $row=$index+1;$lane=['a','b','c','d'][$laneIndex];
    $tests["Task35 exact catalogue row$row lane$lane and deterministic aggregation delta"]=function()use($row,$lane,$effects):void {
        $node=passiveNode($row,$lane);assertSameValue($effects,$node['effects'],'Exact independent V1 design values.');
        $catalogue=new PassiveCatalogue(passiveFixtureRows());$aggregator=new PassiveStatAggregator();
        $before=passivePrefix($row-1);$after=$before;
        $after[]=['passive_key'=>$node['key'],'row_number'=>$row,'pass_number'=>1];
        $old=$aggregator->aggregate($catalogue,$before);$new=$aggregator->aggregate($catalogue,$after);
        foreach (array_unique(array_merge(array_keys($old),array_keys($new))) as $key) { assertSameValue($effects[$key]??0,($new[$key]??0)-($old[$key]??0),'Only selected node contributes.'); }
        assertSameValue($new,$aggregator->aggregate($catalogue,array_reverse($after)),'Deterministic sorted order.');
    };
}
$paths=['strength'=>['main','strength'],'energy'=>['main','energy'],'dexterity'=>['main','dexterity'],'vitality'=>['main','vitality'],'maximum_life'=>['resources','max_life'],'maximum_mana'=>['resources','max_mana'],'accuracy'=>['combat','accuracy'],'physical_damage'=>['combat','melee_damage'],'fire_damage'=>['utility','fire_damage'],'cold_damage'=>['utility','cold_damage'],'lightning_damage'=>['utility','lightning_damage'],'critical_chance'=>['combat','critical_chance'],'critical_damage'=>['combat','critical_damage'],'toughness'=>['combat','toughness'],'dodging'=>['combat','dodging'],'fire_resistance'=>['resistances','fire'],'cold_resistance'=>['resistances','cold'],'lightning_resistance'=>['resistances','lightning'],'poison_damage'=>['utility','poison_damage'],'bleed_damage'=>['utility','bleed_damage'],'life_on_hit'=>['utility','life_on_hit'],'healing_power_percent'=>['utility','healing_power_percent'],'elemental_status_chance_percent'=>['utility','elemental_status_chance_percent']];
foreach($paths as $key=>[$group,$field]) {
    $tests["Task35 CharacterStats $key aggregates base equipment passive once"]=function()use($key,$group,$field):void {
        $character=passiveFixtureCharacter();$base=CharacterStats::calculate($character,[$key=>2]);
        $stats=CharacterStats::calculate($character,[$key=>2],null,[$key=>3]);
        assertFloatValue(3.0,(float)($stats[$group][$field]-$base[$group][$field]),'Additive stat before snapshots.');
        assertSameValue(100,$character['current_hp'],'No HP restoration.');assertSameValue(50,$character['current_mana'],'No Mana restoration.');
    };
}
$tests['Task35 physical bonus strengthens physical weapon and unarmed never converts to elemental']=function():void {
    $character=passiveFixtureCharacter();
    $unarmed=CharacterStats::calculate($character,[],null,['strength'=>3,'physical_damage'=>20]);
    assertSameValue(28,$unarmed['combat']['attack_damage']['min'],'1Strength=1 plus passive20.');
    $weapon=task25Mace();$stats=CharacterStats::calculate($character,[],$weapon,['physical_damage'=>20]);
    assertSameValue([30,32],[$stats['combat']['attack_damage']['min'],$stats['combat']['attack_damage']['max']],'Weapon5–7 + Strength5 +20.');
    $weapon['snapshot_damage_type']='fire';$stats=CharacterStats::calculate($character,[],$weapon,['physical_damage'=>20]);
    assertSameValue([5,7],[$stats['combat']['attack_damage']['min'],$stats['combat']['attack_damage']['max']],'Physical bonus not fire bonus.');
};
$tests['Task35 block additive multipliers cast action duration and unchanged cooldown allowance']=function():void {
    [$pdo,,$reader,$provider]=task25Fixture([],[]);
    $reader->passives=['block_rate_bp'=>1500,'cast_rate_bp'=>1000];
    $stats=$provider->characterStats($pdo->characters[42]);
    assertFloatValue(1.15,$stats['rates']['block_rate'],'+15%=factor1.15, not15 points.');
    assertSameValue(1,$stats['rates']['action'],'Discrete per-turn action allowance unchanged.');
    assertSameValue(1000,$provider->effectiveDurationMs($pdo->characters[42],'prototype_weapon_attack',1000),'Weapon duration unchanged.');
    assertSameValue(1000,$provider->effectiveDurationMs($pdo->characters[42],'prototype_flame_strike',1100),'Skill action rate1.1.');
    $mixed=CharacterStats::calculate(passiveFixtureCharacter(),['block_rate_bp'=>1000,'cast_rate_bp'=>1000],null,['block_rate_bp'=>1500,'cast_rate_bp'=>1000]);
    assertFloatValue(1.25,$mixed['rates']['block_rate'],'Equipment10%+passive15%=1.25.');
    assertFloatValue(1.20,$mixed['rates']['cast_rate'],'Same additive basis-point machinery.');
};
$tests['Task35 compound majors elemental resist no poison Healing Power projection only']=function():void {
    $character=passiveFixtureCharacter();$base=CharacterStats::calculate($character);
    $mastery=CharacterStats::calculate($character,[],null,passiveNode(20,'b')['effects']);
    foreach(['fire','cold','lightning'] as $key)assertSameValue(20,$mastery['utility'][$key.'_damage'],'Major gives exact20 direct.');
    assertSameValue(0,$mastery['utility']['poison_damage'],'No poison in mastery.');
    $divine=CharacterStats::calculate($character,[],null,passiveNode(20,'d')['effects']);
    assertSameValue(200,$divine['resources']['max_life']-$base['resources']['max_life'],'Major200 life.');
    assertSameValue(25,$divine['utility']['healing_power_percent'],'Major healing25.');
    assertSameValue(0,$divine['utility']['life_on_hit'],'No LoH invented.');
    $resist=CharacterStats::calculate($character,[],null,passiveNode(18,'d')['effects']);
    assertFloatValue(0.0,$resist['resistances']['poison']-$base['resistances']['poison'],'Generic elemental excludes poison.');
    foreach(['fire','cold','lightning'] as $key)assertFloatValue(10.0,$resist['resistances'][$key]-$base['resistances'][$key],'Elemental+10.');
};
$tests['Task35 frozen passive damage elements statuses crit and accuracy survive later allocations']=function():void {
    [$pdo,$clock,$reader,$provider,$random]=task25Fixture([task28Ring(['life_on_hit'=>2])],[1,100,100,1,1]);
    $reader->passives=['physical_damage'=>20,'fire_damage'=>5,'cold_damage'=>10,'lightning_damage'=>20,'poison_damage'=>5,'bleed_damage'=>10,'accuracy'=>5,'critical_chance'=>4,'critical_damage'=>10];
    $pdo->encounters[91]['enemy_current_hp']=100000;$pdo->encounters[91]['enemy_max_hp']=100000;
    $service=CombatBootstrap::serviceForRepository(new CombatRepository($pdo),$clock,$provider,$random);
    $service->startPlayerAction(7,42,'prototype_weapon_attack',passiveToken(777));
    $before=$pdo->actions[1];$reader->passives=['physical_damage'=>999,'fire_damage'=>999];
    task26Advance($clock);$service->state(7,42);
    foreach(['snapshot_base_damage','snapshot_fire_damage','snapshot_cold_damage','snapshot_lightning_damage','snapshot_poison_status_damage','snapshot_bleed_status_damage','snapshot_accuracy','snapshot_critical_chance','snapshot_critical_damage'] as $key)assertSameValue($before[$key],$pdo->actions[1][$key],'Pending '.$key.' frozen.');
    assertSameValue(25,$before['snapshot_base_damage'],'Physical5+20.');
    assertSameValue(['poison','bleed'],array_column(task29Active($pdo),'status_key'),'Canonical poison/bleed path.');
};
foreach(['poison','bleed','burn','freeze','shock'] as $status) {
    foreach([35,36] as $roll) {
        $tests["Task35 elemental-only frozen chance $status roll$roll"]=function()use($status,$roll):void {
            $chance=in_array($status,['burn','freeze','shock'],true)?35:25;
            [$pdo,$clock,$reader,$provider,$random]=task29Fixture([$status=>5],[1,100,100,$roll]);
            $reader->passives=['elemental_status_chance_percent'=>10];
            $service=CombatBootstrap::serviceForRepository(new CombatRepository($pdo),$clock,$provider,$random);
            $service->startPlayerAction(7,42,'prototype_weapon_attack',passiveToken(1));
            assertSameValue(10,$pdo->actions[1]['snapshot_elemental_status_chance'],'Frozen at action start.');
            $reader->passives=['elemental_status_chance_percent'=>100];task26Advance($clock);$service->state(7,42);
            assertSameValue($roll<=$chance?[$status]:[],array_column(task29Active($pdo),'status_key'),'Existing engine bonus restricted to elemental.');
        };
    }
}
$tests['Task35 passive LoH stacks with equipment contact only and Healing Power never scales it']=function():void {
    [$pdo,$clock,$reader,$provider,$random]=task25Fixture([task28Ring(['life_on_hit'=>2])],[1,100,100]);
    $reader->passives=['life_on_hit'=>3,'healing_power_percent'=>100];
    $pdo->characters[42]['current_hp']=100;
    $service=CombatBootstrap::serviceForRepository(new CombatRepository($pdo),$clock,$provider,$random);
    $service->startPlayerAction(7,42,'prototype_weapon_attack',passiveToken(1));task26Advance($clock);$service->state(7,42);
    assertSameValue(105,$pdo->characters[42]['current_hp'],'LoH2+3, not10 from Healing Power.');
};
return $tests;
