<?php
declare(strict_types=1);

final class WarpRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findOwnedCharacter(
        int $userId,
        int $characterId,
        bool $forUpdate = false,
    ): ?array {
        $sql = '
            SELECT
                c.id,
                c.user_id,
                c.current_map_id,
                c.pos_x,
                c.pos_y,
                c.gold,
                c.current_hp,
                c.current_mana,
                gm.map_key AS current_map_key,
                gm.map_file AS current_map_file
            FROM characters c
            INNER JOIN game_maps gm
                ON gm.id = c.current_map_id
            WHERE c.id = :character_id
              AND c.user_id = :user_id
            LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'character_id' => $characterId,
            'user_id' => $userId,
        ]);
        $character = $stmt->fetch();

        return is_array($character) ? $character : null;
    }

    public function unlock(int $characterId, string $warpId): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT IGNORE INTO character_warps (
                character_id,
                warp_id
            ) VALUES (
                :character_id,
                :warp_id
            )
        ');
        $stmt->execute([
            'character_id' => $characterId,
            'warp_id' => $warpId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function unlockedWarpIds(int $characterId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT warp_id
            FROM character_warps
            WHERE character_id = :character_id
            ORDER BY unlocked_at, warp_id
        ');
        $stmt->execute(['character_id' => $characterId]);

        return array_map(
            static fn (array $row): string => (string) $row['warp_id'],
            $stmt->fetchAll(),
        );
    }

    public function hasUnlocked(int $characterId, string $warpId): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT 1
            FROM character_warps
            WHERE character_id = :character_id
              AND warp_id = :warp_id
            LIMIT 1
        ');
        $stmt->execute([
            'character_id' => $characterId,
            'warp_id' => $warpId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function findMapByKey(string $mapKey): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, map_key, map_file
            FROM game_maps
            WHERE map_key = :map_key
            LIMIT 1
        ');
        $stmt->execute(['map_key' => $mapKey]);
        $map = $stmt->fetch();

        return is_array($map) ? $map : null;
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
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
        $stmt = $this->pdo->prepare('
            UPDATE characters
            SET gold = gold - :deduction_cost,
                current_map_id = :map_id,
                pos_x = :pos_x,
                pos_y = :pos_y
            WHERE id = :character_id
              AND user_id = :user_id
              AND gold >= :minimum_gold
        ');
        $stmt->execute([
            'deduction_cost' => $cost,
            'minimum_gold' => $cost,
            'map_id' => $mapId,
            'pos_x' => $x,
            'pos_y' => $y,
            'character_id' => $characterId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() === 1;
    }
}
