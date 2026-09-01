<?php
declare(strict_types=1);

$warpDefinitionPath = __DIR__ . '/../ascii-quest/lib/WarpDefinitionRegistry.php';
$warpServicePath = __DIR__ . '/../ascii-quest/lib/WarpService.php';

if (is_file($warpDefinitionPath)) {
    require_once $warpDefinitionPath;
}
if (is_file($warpServicePath)) {
    require_once $warpServicePath;
}

function warpMap(string $key, ?array $warp = null): array
{
    $map = [
        'map_key' => $key,
        'map_name' => ucwords(str_replace('_', ' ', $key)),
        'width' => 5,
        'height' => 5,
        'start_x' => 1,
        'start_y' => 1,
        'layout' => [
            '#####',
            '#...#',
            '#...#',
            '#...#',
            '#####',
        ],
        'transitions' => [],
    ];

    if ($warp !== null) {
        $map['warp'] = array_replace([
            'id' => $key,
            'name' => $map['map_name'],
            'x' => 2,
            'y' => 2,
            'arrival_x' => 2,
            'arrival_y' => 1,
            'cost' => 5,
            'glyph' => '⬡',
        ], $warp);
    }

    return $map;
}

function warpRegistry(array $mapOverrides = []): object
{
    if (!class_exists('WarpDefinitionRegistry')) {
        throw new RuntimeException('WarpDefinitionRegistry must exist.');
    }

    $maps = array_replace([
        'deep_cave.json' => warpMap('deep_cave', [
            'name' => 'Deep Cave',
            'cost' => 5,
        ]),
        'forgotten_cave.json' => warpMap('forgotten_cave', [
            'name' => 'Forgotten Cave',
            'cost' => 10,
        ]),
    ], $mapOverrides);

    return WarpDefinitionRegistry::fromMapData($maps);
}

function assertWarpRejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException | RuntimeException) {
        return;
    }

    throw new RuntimeException($message . ' Expected rejection.');
}

final class FakeWarpRepository
{
    public array $characters;
    public array $unlocks;
    public array $maps;
    public int $travelUpdates = 0;
    private ?array $snapshot = null;

    public function __construct()
    {
        $this->characters = [
            42 => [
                'id' => 42,
                'user_id' => 7,
                'current_map_id' => 1,
                'current_map_key' => 'deep_cave',
                'current_map_file' => 'deep_cave.json',
                'pos_x' => 2,
                'pos_y' => 1,
                'gold' => 20,
                'current_hp' => 145,
                'current_mana' => 80,
            ],
            84 => [
                'id' => 84,
                'user_id' => 8,
                'current_map_id' => 1,
                'current_map_key' => 'deep_cave',
                'current_map_file' => 'deep_cave.json',
                'pos_x' => 2,
                'pos_y' => 1,
                'gold' => 20,
                'current_hp' => 100,
                'current_mana' => 60,
            ],
        ];
        $this->unlocks = [];
        $this->maps = [
            'deep_cave' => ['id' => 1, 'map_key' => 'deep_cave', 'map_file' => 'deep_cave.json'],
            'forgotten_cave' => ['id' => 2, 'map_key' => 'forgotten_cave', 'map_file' => 'forgotten_cave.json'],
        ];
    }

    public function findOwnedCharacter(int $userId, int $characterId, bool $forUpdate = false): ?array
    {
        $character = $this->characters[$characterId] ?? null;

        return $character !== null && $character['user_id'] === $userId
            ? $character
            : null;
    }

    public function unlock(int $characterId, string $warpId): bool
    {
        $alreadyUnlocked = isset($this->unlocks[$characterId][$warpId]);
        $this->unlocks[$characterId][$warpId] = true;

        return !$alreadyUnlocked;
    }

    public function unlockedWarpIds(int $characterId): array
    {
        return array_keys($this->unlocks[$characterId] ?? []);
    }

    public function hasUnlocked(int $characterId, string $warpId): bool
    {
        return isset($this->unlocks[$characterId][$warpId]);
    }

    public function findMapByKey(string $mapKey): ?array
    {
        return $this->maps[$mapKey] ?? null;
    }

    public function beginTransaction(): void
    {
        $this->snapshot = [$this->characters, $this->unlocks, $this->travelUpdates];
    }

    public function commit(): void
    {
        $this->snapshot = null;
    }

    public function rollBack(): void
    {
        if ($this->snapshot !== null) {
            [$this->characters, $this->unlocks, $this->travelUpdates] = $this->snapshot;
            $this->snapshot = null;
        }
    }

    public function updateTravel(
        int $userId,
        int $characterId,
        int $mapId,
        int $x,
        int $y,
        int $cost,
    ): bool {
        $character = $this->characters[$characterId] ?? null;
        if ($character === null || $character['user_id'] !== $userId || $character['gold'] < $cost) {
            return false;
        }

        $map = array_values(array_filter(
            $this->maps,
            static fn (array $candidate): bool => $candidate['id'] === $mapId,
        ))[0] ?? null;
        if ($map === null) {
            return false;
        }

        $this->characters[$characterId]['gold'] -= $cost;
        $this->characters[$characterId]['current_map_id'] = $mapId;
        $this->characters[$characterId]['current_map_key'] = $map['map_key'];
        $this->characters[$characterId]['current_map_file'] = $map['map_file'];
        $this->characters[$characterId]['pos_x'] = $x;
        $this->characters[$characterId]['pos_y'] = $y;
        $this->travelUpdates++;

        return true;
    }
}

function warpService(?FakeWarpRepository $repository = null): array
{
    if (!class_exists('WarpService')) {
        throw new RuntimeException('WarpService must exist.');
    }

    $repository ??= new FakeWarpRepository();

    return [new WarpService(warpRegistry(), $repository), $repository];
}

return [
    'Production test maps expose valid authoritative Warps' => function (): void {
        $registry = WarpDefinitionRegistry::fromMapFiles([
            'deep_cave.json',
            'forgotten_cave.json',
        ]);

        $deepCave = $registry->byId('deep_cave');
        $forgottenCave = $registry->byId('forgotten_cave');

        assertSameValue([4, 2, 4, 1, 5], [
            $deepCave['x'],
            $deepCave['y'],
            $deepCave['arrival_x'],
            $deepCave['arrival_y'],
            $deepCave['cost'],
        ], 'Deep Cave Warp definition.');
        assertSameValue('⬡', $deepCave['glyph'], 'Deep Cave Warp glyph.');
        assertSameValue([4, 2, 4, 1, 10], [
            $forgottenCave['x'],
            $forgottenCave['y'],
            $forgottenCave['arrival_x'],
            $forgottenCave['arrival_y'],
            $forgottenCave['cost'],
        ], 'Forgotten Cave Warp definition.');
        assertSameValue('⬡', $forgottenCave['glyph'], 'Forgotten Cave Warp glyph.');
    },

    'Warp position is authoritative occupied space for movement' => function (): void {
        $registry = warpRegistry();

        assertSameValue(
            true,
            $registry->isWarpPosition('deep_cave.json', 2, 2),
            'Warp tile must be occupied.',
        );
        assertSameValue(
            false,
            $registry->isWarpPosition('deep_cave.json', 2, 1),
            'Adjacent floor must not be occupied by the Warp.',
        );
    },

    'Map without warp is valid' => function (): void {
        $registry = WarpDefinitionRegistry::fromMapData([
            'plain.json' => warpMap('plain'),
        ]);

        assertSameValue([], $registry->all(), 'A map may omit its warp.');
    },

    'Map with one valid warp is loaded' => function (): void {
        $warp = warpRegistry()->byId('deep_cave');

        assertSameValue('Deep Cave', $warp['name'], 'Warp name.');
        assertSameValue(5, $warp['cost'], 'Warp cost.');
        assertSameValue('deep_cave.json', $warp['map_file'], 'Authoritative map file.');
    },

    'Duplicate warp IDs are rejected' => function (): void {
        assertWarpRejected(
            fn (): object => warpRegistry([
                'forgotten_cave.json' => warpMap('forgotten_cave', [
                    'id' => 'deep_cave',
                ]),
            ]),
            'Duplicate IDs.',
        );
    },

    'Malformed warp is rejected safely' => function (): void {
        assertWarpRejected(
            fn (): object => warpRegistry([
                'deep_cave.json' => warpMap('deep_cave', ['cost' => -1]),
            ]),
            'Negative cost.',
        );
    },

    'Empty Warp ID is rejected' => function (): void {
        assertWarpRejected(
            fn (): object => warpRegistry([
                'deep_cave.json' => warpMap('deep_cave', ['id' => '']),
            ]),
            'Empty Warp ID.',
        );
    },

    'Warp ID longer than database column is rejected' => function (): void {
        assertWarpRejected(
            fn (): object => warpRegistry([
                'deep_cave.json' => warpMap('deep_cave', [
                    'id' => str_repeat('a', 65),
                ]),
            ]),
            '65-character Warp ID.',
        );
    },

    'Uppercase non-canonical Warp ID is rejected' => function (): void {
        assertWarpRejected(
            fn (): object => warpRegistry([
                'deep_cave.json' => warpMap('deep_cave', ['id' => 'Deep_Cave']),
            ]),
            'Uppercase Warp ID.',
        );
    },

    'Whitespace and unsafe Warp ID characters are rejected' => function (): void {
        foreach ([' deep_cave', 'deep cave', 'deep.cave'] as $invalidId) {
            assertWarpRejected(
                fn (): object => warpRegistry([
                    'deep_cave.json' => warpMap('deep_cave', ['id' => $invalidId]),
                ]),
                'Unsafe Warp ID ' . $invalidId . '.',
            );
        }
    },

    'Warp on wall is rejected' => function (): void {
        assertWarpRejected(
            fn (): object => warpRegistry([
                'deep_cave.json' => warpMap('deep_cave', [
                    'x' => 0,
                    'y' => 0,
                ]),
            ]),
            'Wall Warp position.',
        );
    },

    'Warp position overlapping transition is rejected' => function (): void {
        $map = warpMap('deep_cave', []);
        $map['transitions'][] = [
            'type' => 'stairs_down',
            'x' => 2,
            'y' => 2,
            'glyph' => '>',
            'target_map_key' => 'forgotten_cave',
            'target_x' => 1,
            'target_y' => 1,
            'message' => 'Descend.',
        ];

        assertWarpRejected(
            fn (): object => WarpDefinitionRegistry::fromMapData([
                'deep_cave.json' => $map,
            ]),
            'Transition Warp position.',
        );
    },

    'Warp position overlapping object is rejected' => function (): void {
        $map = warpMap('deep_cave', []);
        $map['objects'][] = [
            'type' => 'chest',
            'x' => 2,
            'y' => 2,
            'glyph' => 'O',
        ];

        assertWarpRejected(
            fn (): object => WarpDefinitionRegistry::fromMapData([
                'deep_cave.json' => $map,
            ]),
            'Object Warp position.',
        );
    },

    'Warp without orthogonal floor interaction position is rejected' => function (): void {
        $map = warpMap('deep_cave', [
            'arrival_x' => 1,
            'arrival_y' => 1,
        ]);
        $map['layout'] = [
            '#####',
            '#.###',
            '##.##',
            '#####',
            '#####',
        ];

        assertWarpRejected(
            fn (): object => WarpDefinitionRegistry::fromMapData([
                'deep_cave.json' => $map,
            ]),
            'Inaccessible Warp interaction.',
        );
    },

    'Arrival on transition metadata is rejected' => function (): void {
        $map = warpMap('deep_cave', []);
        $map['transitions'][] = [
            'type' => 'stairs_down',
            'x' => 2,
            'y' => 1,
            'glyph' => '>',
            'target_map_key' => 'forgotten_cave',
            'target_x' => 1,
            'target_y' => 1,
            'message' => 'Descend.',
        ];

        assertWarpRejected(
            fn (): object => WarpDefinitionRegistry::fromMapData([
                'deep_cave.json' => $map,
            ]),
            'Transition arrival.',
        );
    },

    'Arrival on object metadata is rejected' => function (): void {
        $map = warpMap('deep_cave', []);
        $map['objects'][] = [
            'type' => 'trap',
            'x' => 2,
            'y' => 1,
            'glyph' => '^',
        ];

        assertWarpRejected(
            fn (): object => WarpDefinitionRegistry::fromMapData([
                'deep_cave.json' => $map,
            ]),
            'Object arrival.',
        );
    },

    'Warp adjacency accepts only four direct neighbours' => function (): void {
        foreach ([[2, 1], [2, 3], [1, 2], [3, 2]] as [$x, $y]) {
            assertSameValue(true, WarpService::isAdjacent($x, $y, 2, 2), 'Direct adjacency.');
        }

        assertSameValue(false, WarpService::isAdjacent(1, 1, 2, 2), 'Diagonal.');
        assertSameValue(false, WarpService::isAdjacent(0, 2, 2, 2), 'Distance two.');
        assertSameValue(false, WarpService::isAdjacent(2, 2, 2, 2), 'Same tile.');
    },

    'E interaction finds an orthogonally adjacent Warp' => function (): void {
        [$service] = warpService();

        foreach ([[2, 1], [2, 3], [1, 2], [3, 2]] as [$x, $y]) {
            $warp = $service->findInteractableWarp('deep_cave.json', $x, $y);
            assertSameValue('deep_cave', $warp['id'] ?? null, 'Direct E interaction.');
        }
    },

    'E interaction ignores diagonal and distant Warps' => function (): void {
        [$service] = warpService();

        assertSameValue(
            null,
            $service->findInteractableWarp('deep_cave.json', 1, 1),
            'Diagonal E interaction.',
        );
        assertSameValue(
            null,
            $service->findInteractableWarp('deep_cave.json', 0, 2),
            'Distant E interaction.',
        );
        assertSameValue(
            null,
            $service->findInteractableWarp('deep_cave.json', 2, 2),
            'Same-tile E interaction.',
        );
    },

    'Adjacent owner unlocks for free without resource changes' => function (): void {
        [$service, $repository] = warpService();
        $before = $repository->characters[42];

        $result = $service->unlock(7, 42, 'deep_cave');

        assertSameValue(true, $result['newly_unlocked'], 'First discovery.');
        assertSameValue(['deep_cave'], $repository->unlockedWarpIds(42), 'Stored unlock.');
        assertSameValue($before, $repository->characters[42], 'Discovery must change no Champion state.');
    },

    'Remote unlock is rejected' => function (): void {
        [$service, $repository] = warpService();
        $repository->characters[42]['pos_x'] = 4;
        $repository->characters[42]['pos_y'] = 4;

        assertWarpRejected(
            fn (): array => $service->unlock(7, 42, 'deep_cave'),
            'Remote discovery.',
        );
        assertSameValue([], $repository->unlockedWarpIds(42), 'No remote unlock row.');
    },

    'Another Champion cannot be unlocked' => function (): void {
        [$service, $repository] = warpService();

        assertWarpRejected(
            fn (): array => $service->unlock(7, 84, 'deep_cave'),
            'Ownership.',
        );
        assertSameValue([], $repository->unlockedWarpIds(84), 'No cross-owner unlock.');
    },

    'Repeated unlock is idempotent' => function (): void {
        [$service, $repository] = warpService();

        $service->unlock(7, 42, 'deep_cave');
        $second = $service->unlock(7, 42, 'deep_cave');

        assertSameValue(false, $second['newly_unlocked'], 'Repeated discovery.');
        assertSameValue(['deep_cave'], $repository->unlockedWarpIds(42), 'One unlock row.');
    },

    'Listings are Champion-specific and use JSON cost and current location' => function (): void {
        [$service, $repository] = warpService();
        $repository->unlocks[42] = ['deep_cave' => true, 'forgotten_cave' => true];
        $repository->unlocks[84] = ['forgotten_cave' => true];

        $ownerList = $service->listDestinations(7, 42);
        $otherList = $service->listDestinations(8, 84);

        assertSameValue(2, count($ownerList), 'Owner destinations.');
        assertSameValue('deep_cave', $ownerList[0]['id'], 'First destination.');
        assertSameValue(5, $ownerList[0]['cost'], 'Deep Cave JSON cost.');
        assertSameValue(true, $ownerList[0]['current_location'], 'Current location.');
        assertSameValue(['forgotten_cave'], array_column($otherList, 'id'), 'Independent unlock list.');
    },

    'Locked warp travel is rejected' => function (): void {
        [$service] = warpService();

        assertWarpRejected(
            fn (): array => $service->travel(7, 42, 'forgotten_cave'),
            'Locked destination.',
        );
    },

    'Travel uses authoritative JSON cost and arrival while preserving resources' => function (): void {
        [$service, $repository] = warpService();
        $repository->unlocks[42] = ['forgotten_cave' => true];

        $result = $service->travel(7, 42, 'forgotten_cave');

        assertSameValue(10, $repository->characters[42]['gold'], 'JSON cost deducted once.');
        assertSameValue(2, $repository->characters[42]['current_map_id'], 'Destination map.');
        assertSameValue(2, $repository->characters[42]['pos_x'], 'Arrival X.');
        assertSameValue(1, $repository->characters[42]['pos_y'], 'Arrival Y.');
        assertSameValue(145, $repository->characters[42]['current_hp'], 'HP unchanged.');
        assertSameValue(80, $repository->characters[42]['current_mana'], 'Mana unchanged.');
        assertSameValue(10, $result['character_updates']['gold'], 'Authoritative response Gold.');
    },

    'Insufficient Gold rejects travel without partial updates' => function (): void {
        [$service, $repository] = warpService();
        $repository->unlocks[42] = ['forgotten_cave' => true];
        $repository->characters[42]['gold'] = 9;
        $before = $repository->characters[42];

        assertWarpRejected(
            fn (): array => $service->travel(7, 42, 'forgotten_cave'),
            'Insufficient Gold.',
        );
        assertSameValue($before, $repository->characters[42], 'Atomic rollback.');
    },

    'Current-location travel does not charge or update twice' => function (): void {
        [$service, $repository] = warpService();
        $repository->unlocks[42] = ['deep_cave' => true];

        $result = $service->travel(7, 42, 'deep_cave');

        assertSameValue(20, $repository->characters[42]['gold'], 'No current-map charge.');
        assertSameValue(0, $repository->travelUpdates, 'No current-map update.');
        assertSameValue(true, $result['current_location'], 'Current-location response.');
    },
];
