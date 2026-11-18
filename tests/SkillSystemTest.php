<?php
declare(strict_types=1);
require_once __DIR__ . '/fixtures/skill_catalogue.php';
foreach (['SkillCatalogue', 'SkillService', 'SkillRepository'] as $class) {
    $path = __DIR__ . '/../ascii-quest/lib/' . $class . '.php';
    if (is_file($path)) { require_once $path; }
}

return [
    'Task34B normalized catalogue has exactly four nodes per actual class and twelve prerequisite edges' => function (): void {
        [$rows, $edges] = task34bCatalogueRows();
        $catalogue = new SkillCatalogue($rows, $edges);
        foreach (['warrior','mage','rogue','cleric'] as $class) {
            $skills = $catalogue->forClass($class);
            assertSameValue(4, count($skills), 'Four class skills.');
            assertSameValue(1, count(array_filter($skills, static fn ($s) => $s['skill_type'] === 'ultimate')), 'One ultimate.');
            $positions = array_column($skills, null, 'tree_position');
            assertSameValue([], $positions['root']['prerequisites'], 'Root has no prerequisite.');
            assertSameValue([$positions['root']['skill_key']], $positions['branch_1']['prerequisites'], 'Root to B.');
            assertSameValue([$positions['root']['skill_key']], $positions['branch_2']['prerequisites'], 'Root to D.');
            assertSameValue([$positions['branch_1']['skill_key']], $positions['ultimate']['prerequisites'], 'B to ultimate.');
        }
    },
    'Task34B authoritative progression derived points and malformed level rejection' => function (): void {
        foreach ([1 => 0, 2 => 1, 5 => 4, 50 => 49] as $level => $earned) {
            $character = ['level' => $level,'experience' => 100 * ($level-1)**2,'life_state'=>'alive'];
            assertSameValue(['earned'=>$earned,'spent'=>0,'available'=>$earned], SkillService::points($character, 0), 'Point model.');
            assertSameValue(max(0,$earned-2), SkillService::points($character, 2)['available'], 'Spent clamped.');
        }
        foreach ([null,0,-1,51,1.5,'bad',true] as $bad) {
            task34aAssertRejected(fn () => SkillService::points(['level'=>$bad,'experience'=>0,'life_state'=>'alive'],0), InvalidArgumentException::class);
        }
        assertSameValue(0, SkillService::points(['level'=>50,'experience'=>0,'life_state'=>'alive'],0)['earned'], 'XP, not stale cache, is authority.');
    },
];
