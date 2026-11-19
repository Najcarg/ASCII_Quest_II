<?php
declare(strict_types=1);
require_once __DIR__.'/PassiveCatalogue.php';
require_once __DIR__.'/PassiveService.php';

final class PassiveStatAggregator
{
    public function aggregate(PassiveCatalogue $catalogue,array $unlocks): array
    {
        // Fail closed on corrupt/out-of-sequence history; no class filtering.
        (new PassiveService(new stdClass(),$catalogue))->progress($unlocks);
        $totals=[];
        usort($unlocks,static fn(array $a,array $b):int=>strcmp($a['passive_key'],$b['passive_key']));
        foreach ($unlocks as $unlock) {
            $node=$catalogue->node($unlock['passive_key']);
            // Allocations are immutable history, inactive definitions stop applying.
            if (!$node['active']) { continue; }
            foreach ($node['effects'] as $key=>$value) { $totals[$key]=($totals[$key]??0)+$value; }
        }
        return $totals;
    }
    public static function merge(array $equipment,array $passives): array
    {
        foreach ($passives as $key=>$value) {
            if (!in_array($key,PassiveCatalogue::MODIFIERS,true) || !is_int($value) || !is_int($equipment[$key]??0)
                || abs($value)>1000000000 || abs(($equipment[$key]??0)+$value)>1000000000) { throw new UnexpectedValueException('Invalid combined passive modifier.'); }
            $equipment[$key]=($equipment[$key]??0)+$value;
        }
        return $equipment;
    }
}
