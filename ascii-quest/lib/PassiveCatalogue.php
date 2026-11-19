<?php
declare(strict_types=1);
require_once __DIR__.'/CharacterProgression.php';

/** Values come exclusively from Migration014's persisted catalogue. */
final class PassiveCatalogue
{
    public const MODIFIERS = ['strength','energy','dexterity','vitality','maximum_life','maximum_mana','accuracy','physical_damage','fire_damage','cold_damage','lightning_damage','critical_chance','critical_damage','block_rate_bp','toughness','dodging','fire_resistance','cold_resistance','lightning_resistance','poison_damage','bleed_damage','life_on_hit','healing_power_percent','cast_rate_bp','elemental_status_chance_percent'];
    private array $nodes=[];
    public function __construct(array $rows)
    {
        $positions=[];
        foreach ($rows as $row) {
            $key=$row['passive_key']??null;
            $number=CharacterProgression::nonNegativeInteger($row['row_number']??null);
            $lane=$row['lane_key']??null;
            if ($number<1 || $number>20 || !in_array($lane,['a','b','c','d'],true)
                || !is_string($key) || preg_match('/\Apassive_r'.sprintf('%02d',$number).'_'.$lane.'_[a-z_]+\z/D',$key)!==1
                || strlen($key)>64 || isset($this->nodes[$key]) || isset($positions[$number.$lane])) {
                throw new UnexpectedValueException('Invalid passive catalogue position.');
            }
            foreach (['display_name','description'] as $field) {
                if (!is_string($row[$field]??null) || trim($row[$field])==='') { throw new UnexpectedValueException('Invalid passive text.'); }
            }
            $major=CharacterProgression::nonNegativeInteger($row['is_major']??null);
            $active=CharacterProgression::nonNegativeInteger($row['is_active']??null);
            if ($major!==($number===20?1:0) || !in_array($active,[0,1],true)) { throw new UnexpectedValueException('Invalid passive flags.'); }
            $effects=json_decode($row['effect_json']??'',true,16,JSON_THROW_ON_ERROR);
            if (!is_array($effects) || $effects===[] || array_is_list($effects)) { throw new UnexpectedValueException('Invalid passive effects.'); }
            foreach ($effects as $stat=>$value) {
                if (!in_array($stat,self::MODIFIERS,true) || !is_int($value) || $value<1 || $value>10000) { throw new UnexpectedValueException('Invalid passive modifier.'); }
            }
            $positions[$number.$lane]=true;
            $this->nodes[$key]=['key'=>$key,'row'=>$number,'lane'=>$lane,'name'=>$row['display_name'],'description'=>$row['description'],'effects'=>$effects,'major'=>$major===1,'active'=>$active===1];
        }
        if (count($this->nodes)!==80) { throw new UnexpectedValueException('Incomplete universal passive catalogue.'); }
        uasort($this->nodes,static fn(array $a,array $b):int=>[$a['row'],$a['lane']]<=>[$b['row'],$b['lane']]);
    }
    public function node(string $key): ?array { return $this->nodes[$key]??null; }
    public function all(): array { return array_values($this->nodes); }
}
