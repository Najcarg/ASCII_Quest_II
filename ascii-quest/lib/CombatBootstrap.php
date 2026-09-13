<?php
declare(strict_types=1);

require_once __DIR__ . '/CombatAccessGuard.php';
require_once __DIR__ . '/CombatActionResolver.php';
require_once __DIR__ . '/CaveBrutePolicy.php';
require_once __DIR__ . '/CombatClock.php';
require_once __DIR__ . '/CombatDefinitionRegistry.php';
require_once __DIR__ . '/CombatEquipmentProvider.php';
require_once __DIR__ . '/CombatRandomSource.php';
require_once __DIR__ . '/CombatRepository.php';
require_once __DIR__ . '/CombatService.php';
require_once __DIR__ . '/CombatStateProjector.php';
require_once __DIR__ . '/CombatSynchronizer.php';
require_once __DIR__ . '/CombatTurnEngine.php';
require_once __DIR__ . '/PrototypeChampionDamageResolver.php';
require_once __DIR__ . '/PrototypeCombatEquipmentProvider.php';
require_once __DIR__ . '/PrototypeEnemyDefenseResolver.php';
require_once __DIR__ . '/PrototypeBlockResolver.php';
require_once __DIR__ . '/SystemCombatClock.php';
require_once __DIR__ . '/SystemCombatRandomSource.php';

final class CombatBootstrap
{
    public static function repository(PDO $pdo): CombatRepository
    {
        return new CombatRepository($pdo);
    }

    public static function guard(PDO $pdo): CombatAccessGuard
    {
        return new CombatAccessGuard(self::repository($pdo));
    }

    public static function guardForRepository(object $repository): CombatAccessGuard
    {
        return new CombatAccessGuard($repository);
    }

    public static function service(PDO $pdo): CombatService
    {
        return self::serviceForRepository(self::repository($pdo));
    }

    public static function serviceForRepository(
        object $repository,
        ?CombatClock $clock = null,
        ?CombatEquipmentProvider $equipmentProvider = null,
        ?CombatRandomSource $randomSource = null,
    ): CombatService
    {
        $definitions = CombatDefinitionRegistry::fromDefaultConfig();
        $clock ??= new SystemCombatClock();
        $equipmentProvider ??= new PrototypeCombatEquipmentProvider($definitions);
        $randomSource ??= new SystemCombatRandomSource();
        $turnEngine = new CombatTurnEngine($definitions->turnDurationSeconds());
        $actionResolver = new CombatActionResolver(
            $repository,
            $definitions,
            $equipmentProvider,
            new PrototypeEnemyDefenseResolver($randomSource),
            new PrototypeBlockResolver(
                new PrototypeChampionDamageResolver(),
                $randomSource,
                $definitions->playerReaction('basic_block') ?? throw new RuntimeException(
                    'Player Block reaction is unavailable.',
                ),
            ),
        );

        return new CombatService(
            $repository,
            $definitions,
            $clock,
            new CombatSynchronizer(
                $clock,
                $turnEngine,
                $definitions->maxDisconnectedCatchupSeconds(),
                null,
                $repository,
                $definitions,
                new CaveBrutePolicy($turnEngine),
                $actionResolver,
                $randomSource,
            ),
            $equipmentProvider,
            new CombatStateProjector($repository, $definitions),
        );
    }

    public static function validatedEncounterForMap(string $mapFile, array $map): ?array
    {
        $definition = null;
        foreach (CombatDefinitionRegistry::fromDefaultConfig()->encounters() as $candidate) {
            if ((string) $candidate['map_file'] === $mapFile) {
                $definition = $candidate;
                break;
            }
        }
        if ($definition === null) {
            return null;
        }
        if ((string) ($map['map_key'] ?? '') !== (string) $definition['map_key']) {
            throw new RuntimeException('Combat encounter map identity is invalid.');
        }

        $width = (int) ($map['width'] ?? 0);
        $height = (int) ($map['height'] ?? 0);
        $layout = $map['layout'] ?? null;
        $x = (int) $definition['x'];
        $y = (int) $definition['y'];
        if (
            $width <= 0 ||
            $height <= 0 ||
            !is_array($layout) ||
            $x < 0 ||
            $y < 0 ||
            $x >= $width ||
            $y >= $height ||
            !self::isPlainFloor($map, $x, $y)
        ) {
            throw new RuntimeException('Combat encounter position is not safe floor.');
        }

        $hasOrthogonalFloor = false;
        foreach ([[0, -1], [1, 0], [0, 1], [-1, 0]] as [$dx, $dy]) {
            if (self::isPlainFloor($map, $x + $dx, $y + $dy)) {
                $hasOrthogonalFloor = true;
                break;
            }
        }
        if (!$hasOrthogonalFloor) {
            throw new RuntimeException('Combat encounter has no orthogonal floor approach.');
        }

        return $definition;
    }

    private static function isPlainFloor(array $map, int $x, int $y): bool
    {
        $row = $map['layout'][$y] ?? null;
        if (!is_string($row) || $x < 0 || ($row[$x] ?? null) !== '.') {
            return false;
        }
        foreach (['transitions', 'objects'] as $collection) {
            foreach ($map[$collection] ?? [] as $entry) {
                if (
                    is_array($entry) &&
                    (int) ($entry['x'] ?? -1) === $x &&
                    (int) ($entry['y'] ?? -1) === $y
                ) {
                    return false;
                }
            }
        }
        $warp = $map['warp'] ?? null;
        if (
            is_array($warp) &&
            (int) ($warp['x'] ?? -1) === $x &&
            (int) ($warp['y'] ?? -1) === $y
        ) {
            return false;
        }

        return true;
    }
}
