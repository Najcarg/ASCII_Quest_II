<?php
declare(strict_types=1);

$progressionPath = __DIR__ . '/../ascii-quest/lib/CharacterProgression.php';
if (is_file($progressionPath)) { require_once $progressionPath; }

function task34aAssertRejected(callable $operation, string $exceptionClass): void
{
    try { $operation(); } catch (Throwable $e) {
        if ($e instanceof $exceptionClass) { return; }
        throw $e;
    }
    throw new RuntimeException('Expected ' . $exceptionClass);
}

return [
    'Task34A new Champion starts Level1 XP0 without stat awards' => function (): void {
        $state = CharacterProgression::project(['level' => 1, 'experience' => 0, 'life_state' => 'alive']);
        assertSameValue(1, $state['level'], 'Initial level.');
        assertSameValue(0, $state['total_xp'], 'Initial XP.');
        assertSameValue(100, $state['xp_to_next'], 'First interval.');
        assertSameValue(0, $state['progress_percent'], 'Initial progress.');
        assertSameValue(false, $state['is_max_level'], 'Not capped.');
        assertSameValue(2, CharacterProgression::levelForTotalXp('100'), 'PDO numeric string XP.');
        $creation = file_get_contents(__DIR__ . '/../ascii-quest/create_character.php');
        assertSameValue(1, preg_match('/VALUES\s*\(\s*:user_id,\s*:class_id,\s*:character_name,\s*1,\s*0,\s*0,/s', $creation), 'Real creation remains Level1/XP0/stat_points0.');
    },
    'Task34A integer table exhaustively covers below exact above every threshold' => function (): void {
        $table = require __DIR__ . '/../ascii-quest/config/character_progression.php';
        assertSameValue(range(1, 50), array_keys($table), 'Contiguous levels.');
        foreach ($table as $level => $threshold) {
            assertSameValue(100 * ($level - 1) ** 2, $threshold, 'Curve parity.');
            assertSameValue($level, CharacterProgression::levelForTotalXp($threshold), "Exact L{$level}.");
            assertSameValue($level, CharacterProgression::levelForTotalXp($threshold + 1), "Above L{$level}.");
            if ($level > 1) {
                assertSameValue($level - 1, CharacterProgression::levelForTotalXp($threshold - 1), "Below L{$level}.");
            }
        }
    },
    'Task34A every current forty XP victory crosses at most one level' => function (): void {
        for ($xp = 0; $xp <= 240100; $xp++) {
            if (CharacterProgression::levelForTotalXp($xp + 40) - CharacterProgression::levelForTotalXp($xp) > 1) {
                throw new RuntimeException('Prototype reward skips levels.');
            }
        }
    },
    'Task34A reconciliation derives alive level from cumulative XP but freezes dead history' => function (): void {
        foreach ([1, 2, 17, 50] as $stored) {
            $alive = ['level' => $stored, 'experience' => 1600, 'life_state' => 'alive'];
            assertSameValue(5, CharacterProgression::project($alive)['level'], 'Alive level reconciled.');
            assertSameValue(1600, CharacterProgression::project($alive)['total_xp'], 'Never lose XP.');
            $alive['life_state'] = 'dead';
            assertSameValue($stored, CharacterProgression::project($alive)['level'], 'Dead level frozen.');
            assertSameValue('historical', CharacterProgression::project($alive)['status'], 'Dead projection labelled.');
        }
    },
    'Task34A multi-level reward and cap retain cumulative XP and resources' => function (): void {
        $character = ['level' => 1, 'experience' => 0, 'life_state' => 'alive', 'current_hp' => 12, 'current_mana' => 13, 'stat_points' => 7];
        $before = $character;
        $reward = CharacterProgression::victoryReward($character, 1700);
        assertSameValue(1, $reward['old_level'], 'Old level.');
        assertSameValue(5, $reward['new_level'], 'Four crossings.');
        assertSameValue(1700, $reward['total_xp_after'], 'Cumulative reward.');
        assertSameValue($before, $character, 'Pure rules do not alter resources/stats.');
        $max = CharacterProgression::project(['level' => 50, 'experience' => 999999, 'life_state' => 'alive']);
        assertSameValue(50, $max['level'], 'Level cap.');
        assertSameValue(999999, $max['total_xp'], 'XP retained above cap.');
        assertSameValue(null, $max['next_level_total_xp'], 'No next threshold.');
        assertSameValue(100, $max['progress_percent'], 'Full MAX bar.');
    },
    'Task34A malformed XP overflow and dead rewards fail closed' => function (): void {
        foreach ([-1, 1.5, '1x', true, null, '9223372036854775808'] as $bad) {
            task6AssertRejected(fn () => CharacterProgression::levelForTotalXp($bad), 'Malformed XP.');
        }
        task6AssertRejected(fn () => CharacterProgression::victoryReward(['level' => 1, 'experience' => 0, 'life_state' => 'dead'], 40), 'Dead reward.');
        task34aAssertRejected(fn () => CharacterProgression::victoryReward(['level' => 50, 'experience' => PHP_INT_MAX, 'life_state' => 'alive'], 1), OverflowException::class);
    },
];
