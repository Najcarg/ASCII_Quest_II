<?php
declare(strict_types=1);

require_once __DIR__ . '/WarpDefinitionRegistry.php';
require_once __DIR__ . '/WarpRepository.php';
require_once __DIR__ . '/WarpService.php';

final class WarpBootstrap
{
    private const MAP_FILES = [
        'deep_cave.json',
        'forgotten_cave.json',
    ];

    public static function definitions(): WarpDefinitionRegistry
    {
        return WarpDefinitionRegistry::fromMapFiles(self::MAP_FILES);
    }

    public static function service(
        PDO $pdo,
        ?WarpDefinitionRegistry $definitions = null,
    ): WarpService {
        return new WarpService(
            $definitions ?? self::definitions(),
            new WarpRepository($pdo),
        );
    }
}
