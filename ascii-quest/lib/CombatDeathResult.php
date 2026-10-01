<?php
declare(strict_types=1);

/**
 * Immutable internal boundary for a future Slayer consumer.
 *
 * This value carries only persisted death facts. It performs no qualification,
 * counter, title, bounty, reward, or other Slayer-system work.
 */
final readonly class CombatDeathResult
{
    public function __construct(
        public int $championId,
        public int $encounterId,
        public string $killerEnemyKey,
        public string $diedAt,
    ) {
        if ($championId <= 0 || $encounterId <= 0) {
            throw new InvalidArgumentException('Combat death identity must be positive.');
        }
        if (preg_match('/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/D', $killerEnemyKey) !== 1) {
            throw new InvalidArgumentException('Combat death killer key is invalid.');
        }
        if ($diedAt === '') {
            throw new InvalidArgumentException('Combat death timestamp is required.');
        }
    }

    /** @return array{champion_id:int, encounter_id:int, killer_enemy_key:string, died_at:string} */
    public function toArray(): array
    {
        return [
            'champion_id' => $this->championId,
            'encounter_id' => $this->encounterId,
            'killer_enemy_key' => $this->killerEnemyKey,
            'died_at' => $this->diedAt,
        ];
    }
}
