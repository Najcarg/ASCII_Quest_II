<?php
declare(strict_types=1);

final class WarpDefinitionRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $definitionsById;

    /** @var array<string, array<string, mixed>> */
    private array $definitionsByMapFile;

    private function __construct(array $definitionsById, array $definitionsByMapFile)
    {
        $this->definitionsById = $definitionsById;
        $this->definitionsByMapFile = $definitionsByMapFile;
    }

    public static function fromMapFiles(array $mapFiles): self
    {
        require_once __DIR__ . '/../map_loader.php';

        $maps = [];
        foreach ($mapFiles as $mapFile) {
            if (!is_string($mapFile) || basename($mapFile) !== $mapFile) {
                throw new RuntimeException('Invalid Warp map file.');
            }

            $maps[$mapFile] = loadMapFromFile($mapFile);
        }

        return self::fromMapData($maps);
    }

    public static function fromMapData(array $maps): self
    {
        $definitionsById = [];
        $definitionsByMapFile = [];

        foreach ($maps as $mapFile => $map) {
            if (!is_string($mapFile) || !is_array($map)) {
                throw new RuntimeException('Invalid Warp map definition.');
            }

            if (!array_key_exists('warp', $map)) {
                continue;
            }

            if (!is_array($map['warp'])) {
                throw new RuntimeException('Invalid Warp definition.');
            }

            $warp = self::validateWarp($mapFile, $map, $map['warp']);
            if (isset($definitionsById[$warp['id']])) {
                throw new RuntimeException('Duplicate Warp identifier.');
            }

            $definitionsById[$warp['id']] = $warp;
            $definitionsByMapFile[$mapFile] = $warp;
        }

        return new self($definitionsById, $definitionsByMapFile);
    }

    public function all(): array
    {
        return array_values($this->definitionsById);
    }

    public function byId(string $warpId): ?array
    {
        return $this->definitionsById[$warpId] ?? null;
    }

    public function forMapFile(string $mapFile): ?array
    {
        return $this->definitionsByMapFile[$mapFile] ?? null;
    }

    private static function validateWarp(
        string $mapFile,
        array $map,
        array $warp,
    ): array {
        foreach ([
            'id',
            'name',
            'x',
            'y',
            'arrival_x',
            'arrival_y',
            'cost',
            'glyph',
        ] as $field) {
            if (!array_key_exists($field, $warp)) {
                throw new RuntimeException('Warp definition is missing a required field.');
            }
        }

        if (
            !is_string($warp['id']) ||
            preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $warp['id']) !== 1
        ) {
            throw new RuntimeException('Warp identifier is invalid.');
        }
        if (!is_string($warp['name']) || trim($warp['name']) === '') {
            throw new RuntimeException('Warp name is invalid.');
        }
        if (!is_string($warp['glyph']) || $warp['glyph'] !== '◈') {
            throw new RuntimeException('Warp glyph is invalid.');
        }

        foreach (['x', 'y', 'arrival_x', 'arrival_y', 'cost'] as $field) {
            if (!is_int($warp[$field])) {
                throw new RuntimeException('Warp coordinates and cost must be integers.');
            }
        }

        if ($warp['cost'] < 0) {
            throw new RuntimeException('Warp cost cannot be negative.');
        }

        $width = (int) ($map['width'] ?? 0);
        $height = (int) ($map['height'] ?? 0);
        $layout = $map['layout'] ?? null;
        if ($width <= 0 || $height <= 0 || !is_array($layout)) {
            throw new RuntimeException('Warp map layout is invalid.');
        }

        self::assertCoordinateInMap($warp['x'], $warp['y'], $width, $height);
        self::assertCoordinateInMap(
            $warp['arrival_x'],
            $warp['arrival_y'],
            $width,
            $height,
        );

        if ($warp['x'] === $warp['arrival_x'] && $warp['y'] === $warp['arrival_y']) {
            throw new RuntimeException('Warp arrival cannot be the Warp tile.');
        }

        if (!self::isPlainFloorPosition($map, $warp['x'], $warp['y'])) {
            throw new RuntimeException('Warp position is not a plain floor tile.');
        }

        if (!self::isPlainFloorPosition(
            $map,
            $warp['arrival_x'],
            $warp['arrival_y'],
        )) {
            throw new RuntimeException('Warp arrival tile is not a safe floor tile.');
        }

        $hasInteractionPosition = false;
        foreach ([[0, -1], [0, 1], [-1, 0], [1, 0]] as [$dx, $dy]) {
            if (self::isPlainFloorPosition(
                $map,
                $warp['x'] + $dx,
                $warp['y'] + $dy,
            )) {
                $hasInteractionPosition = true;
                break;
            }
        }
        if (!$hasInteractionPosition) {
            throw new RuntimeException(
                'Warp has no valid orthogonal interaction position.',
            );
        }

        return [
            'id' => $warp['id'],
            'name' => trim($warp['name']),
            'x' => $warp['x'],
            'y' => $warp['y'],
            'arrival_x' => $warp['arrival_x'],
            'arrival_y' => $warp['arrival_y'],
            'cost' => $warp['cost'],
            'glyph' => $warp['glyph'],
            'map_file' => $mapFile,
            'map_key' => (string) ($map['map_key'] ?? ''),
            'map_name' => (string) ($map['map_name'] ?? ''),
        ];
    }

    private static function assertCoordinateInMap(
        int $x,
        int $y,
        int $width,
        int $height,
    ): void {
        if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
            throw new RuntimeException('Warp coordinate is outside its map.');
        }
    }

    private static function isPlainFloorPosition(array $map, int $x, int $y): bool
    {
        $layout = $map['layout'] ?? [];
        $row = $layout[$y] ?? null;
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

        return true;
    }
}
