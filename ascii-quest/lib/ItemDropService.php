<?php
declare(strict_types=1);

require_once __DIR__ . '/ItemDefinitionRegistry.php';
require_once __DIR__ . '/ItemGenerator.php';
require_once __DIR__ . '/ItemRandomSource.php';

final class ItemDropService
{
    public function __construct(
        private object $repository,
        private ItemGenerator $generator,
        private ItemRandomSource $random,
        private int $rewardSlots,
        private int $baseDropChanceBp,
    ) {
        if ($rewardSlots < 1 || $baseDropChanceBp < 0 || $baseDropChanceBp > 10000) {
            throw new InvalidArgumentException('Invalid physical drop configuration.');
        }
    }

    public static function effectiveChanceBasisPoints(int $baseChanceBp, int $itemFindBonusBp): int
    {
        if ($baseChanceBp < 0 || $baseChanceBp > 10000 || $itemFindBonusBp < 0) {
            throw new InvalidArgumentException('Invalid item drop chance.');
        }
        return min(10000, intdiv($baseChanceBp * (10000 + $itemFindBonusBp), 10000));
    }

    public function generateForVictory(array &$encounter, string $generatedAt, int $itemFindBonusBp = 0): void
    {
        if (($encounter['loot_source_level'] ?? null) === null
            || ($encounter['item_drops_generated_at'] ?? null) !== null) {
            return;
        }
        $encounterId = self::positiveInteger($encounter, 'id');
        $itemLevel = self::positiveInteger($encounter, 'loot_source_level');
        $effectiveChance = self::effectiveChanceBasisPoints($this->baseDropChanceBp, $itemFindBonusBp);
        for ($slot = 1; $slot <= $this->rewardSlots; $slot++) {
            $chanceRollBp = $this->random->integer(1, 10000);
            if ($chanceRollBp > $effectiveChance) {
                $this->repository->createNoItemDrop(
                    $encounterId, $slot, $this->baseDropChanceBp,
                    $itemFindBonusBp, $effectiveChance, $chanceRollBp, $generatedAt,
                );
                continue;
            }
            $generated = $this->generator->generate($itemLevel, $this->random);
            $this->repository->createGeneratedItemDrop(
                $encounterId,
                $slot,
                $generated,
                'encounter:' . $encounterId . ':reward:' . $slot,
                $this->baseDropChanceBp,
                $itemFindBonusBp,
                $effectiveChance,
                $chanceRollBp,
                $generatedAt,
            );
        }
        if (!$this->repository->markItemDropsGenerated($encounterId, $generatedAt)) {
            throw new RuntimeException('Physical drop generation changed concurrently. Please retry.');
        }
        $encounter['item_drops_generated_at'] = $generatedAt;
    }

    public function autoClaimAll(int $encounterId, int $characterId, string $claimedAt): void
    {
        foreach ($this->repository->lockUnclaimedItemDrops($encounterId) as $drop) {
            if (!$this->repository->claimLockedItemDrop(
                self::positiveInteger($drop, 'id'),
                self::positiveInteger($drop, 'item_id'),
                $characterId,
                $claimedAt,
            )) {
                throw new RuntimeException('Physical item auto-claim changed concurrently. Please retry.');
            }
        }
    }

    private static function positiveInteger(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException('Invalid item drop ' . $key . '.');
        }
        return $value;
    }
}
