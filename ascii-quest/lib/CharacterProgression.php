<?php
declare(strict_types=1);

/** Cumulative XP is authoritative. This service never changes stats/resources. */
final class CharacterProgression
{
    public const MAX_LEVEL = 50;
    private static ?array $thresholds = null;

    public static function levelForTotalXp(mixed $totalXp): int
    {
        $xp = self::nonNegativeInteger($totalXp);
        $table = self::thresholds();
        $low = 1;
        $high = self::MAX_LEVEL;
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if ($xp >= $table[$mid]) { $low = $mid; } else { $high = $mid - 1; }
        }
        return $low;
    }

    public static function project(array $character): array
    {
        $xp = self::nonNegativeInteger($character['experience'] ?? null);
        $life = $character['life_state'] ?? null;
        if (!in_array($life, ['alive', 'dead'], true)) {
            throw new InvalidArgumentException('Invalid progression lifecycle.');
        }
        $level = $life === 'dead'
            ? self::nonNegativeInteger($character['level'] ?? null)
            : self::levelForTotalXp($xp);
        if ($level < 1) { throw new InvalidArgumentException('Invalid historical level.'); }
        $max = $level >= self::MAX_LEVEL;
        $floor = self::thresholds()[min($level, self::MAX_LEVEL)];
        $next = $max ? null : self::thresholds()[$level + 1];
        // Historical dead levels may intentionally not correspond to XP. Never
        // reconcile them on read; clamp the display only, not XP or stored level.
        $into = $max ? 0 : max(0, min($next - $floor, $xp - $floor));
        return [
            'level' => $level,
            'max_level' => self::MAX_LEVEL,
            'total_xp' => $xp,
            'level_total_xp' => $floor,
            'next_level_total_xp' => $next,
            'xp_into_level' => $into,
            'xp_for_next_level' => $max ? null : $next - $floor,
            'xp_to_next' => $max ? 0 : max(0, $next - $xp),
            'progress_percent' => $max ? 100 : intdiv($into * 100, $next - $floor),
            'is_max_level' => $max,
            'status' => $life === 'dead' ? 'historical' : 'active',
        ];
    }

    /** Immutable encounter receipt. Caller persists this and the XP/level together. */
    public static function victoryReward(array $character, mixed $gainedXp): array
    {
        if (($character['life_state'] ?? null) !== 'alive') {
            throw new DomainException('Dead Champion cannot progress.');
        }
        $before = self::nonNegativeInteger($character['experience'] ?? null);
        $gained = self::nonNegativeInteger($gainedXp);
        if ($gained > PHP_INT_MAX - $before) {
            throw new OverflowException('Cumulative XP exceeds integer capacity.');
        }
        $after = $before + $gained;
        return [
            'xp_gained' => $gained,
            'total_xp_before' => $before,
            'total_xp_after' => $after,
            'old_level' => self::levelForTotalXp($before),
            'new_level' => self::levelForTotalXp($after),
        ];
    }

    /** Validate immutable reward JSON before public projection; never infer history from today's XP. */
    public static function rewardSnapshot(?string $json): ?array
    {
        if ($json === null) { return null; }
        $snapshot = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        $keys = is_array($snapshot) ? array_keys($snapshot) : [];
        sort($keys);
        $expected = ['xp_gained', 'total_xp_before', 'total_xp_after', 'old_level', 'new_level'];
        sort($expected);
        if ($keys !== $expected) { throw new UnexpectedValueException('Invalid progression receipt shape.'); }
        foreach ($snapshot as &$value) { $value = self::nonNegativeInteger($value); }
        unset($value);
        $calculated = self::victoryReward([
            'experience' => $snapshot['total_xp_before'], 'life_state' => 'alive',
        ], $snapshot['xp_gained']);
        foreach ($calculated as $key => $value) {
            if ($snapshot[$key] !== $value) { throw new UnexpectedValueException('Invalid progression receipt accounting.'); }
        }
        return $calculated;
    }

    public static function nonNegativeInteger(mixed $value): int
    {
        if (is_string($value) && preg_match('/\A[0-9]+\z/D', $value) === 1) {
            $digits = ltrim($value, '0');
            $maximum = (string) PHP_INT_MAX;
            if (strlen($digits) < strlen($maximum)
                || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) <= 0)) {
                $value = (int) $value;
            }
        }
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Invalid non-negative progression integer.');
        }
        return $value;
    }

    private static function thresholds(): array
    {
        if (self::$thresholds === null) {
            $table = require __DIR__ . '/../config/character_progression.php';
            if (array_keys($table) !== range(1, self::MAX_LEVEL) || $table[1] !== 0) {
                throw new LogicException('Invalid progression table.');
            }
            $previous = -1;
            foreach ($table as $value) {
                if (!is_int($value) || $value <= $previous) {
                    throw new LogicException('Progression thresholds must be monotonic integers.');
                }
                $previous = $value;
            }
            self::$thresholds = $table;
        }
        return self::$thresholds;
    }
}
