<?php
declare(strict_types=1);

final class InventoryService
{
    public const PAGE_SIZE = 25;

    public function __construct(
        private ItemInventoryReader $repository,
        private ItemProjector $projector,
    ) {}

    public function state(int $userId, int $characterId, int $page): array
    {
        if ($userId < 1 || $characterId < 1) {
            throw new OutOfBoundsException('Champion unavailable.');
        }
        if ($page < 1) {
            throw new InvalidArgumentException('Inventory page must be positive.');
        }

        $character = $this->repository->ownedCharacter($userId, $characterId);
        if ($character === null) {
            throw new OutOfBoundsException('Champion unavailable.');
        }

        $totalItems = $this->repository->countOwnedItems($userId, $characterId);
        $totalPages = max(1, (int) ceil($totalItems / self::PAGE_SIZE));
        if ($page > $totalPages) {
            throw new InvalidArgumentException('Inventory page is out of bounds.');
        }

        $rows = $this->repository->ownedItemsPage(
            $userId,
            $characterId,
            self::PAGE_SIZE,
            ($page - 1) * self::PAGE_SIZE,
        );
        $affixes = method_exists($this->repository, 'affixesForItems')
            ? $this->repository->affixesForItems(array_map(static fn (array $row): int => (int) $row['id'], $rows))
            : [];
        $items = array_map(
            fn (array $row): array => $this->projector->projectItem($row, $affixes[(int) $row['id']] ?? []),
            $rows,
        );
        [$locked, $reason] = $this->mutationLock($character);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => self::PAGE_SIZE,
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'has_previous' => $page > 1,
                'has_next' => $page < $totalPages,
            ],
            'equipment_locked' => $locked,
            'mutation_disabled_reason' => $reason,
        ];
    }

    public function claim(
        int $userId,
        int $characterId,
        int $dropId,
        string $requestToken,
    ): array {
        if ($userId < 1 || $characterId < 1 || $dropId < 1) {
            throw new OutOfBoundsException('Item drop unavailable.');
        }
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $requestToken) !== 1) {
            throw new InvalidArgumentException('Invalid item request token.');
        }
        foreach (['beginMutation', 'lockOwnedCharacterForMutation', 'lockMutationRequest', 'lockDropForClaim', 'createClaimRequest', 'claimDrop', 'completeClaimRequest', 'commitMutation', 'rollBackMutation'] as $method) {
            if (!method_exists($this->repository, $method)) {
                throw new LogicException('Item claim repository is unavailable.');
            }
        }
        $fingerprint = hash('sha256', json_encode([
            'command' => 'claim', 'character_id' => $characterId, 'drop_id' => $dropId,
        ], JSON_THROW_ON_ERROR));
        $this->repository->beginMutation();
        try {
            $character = $this->repository->lockOwnedCharacterForMutation($userId, $characterId);
            if ($character === null) {
                throw new OutOfBoundsException('Item drop unavailable.');
            }
            $receipt = $this->repository->lockMutationRequest($characterId, $requestToken);
            if ($receipt !== null) {
                if (($receipt['command_type'] ?? null) !== 'claim'
                    || ($receipt['request_fingerprint'] ?? null) !== $fingerprint) {
                    throw new DomainException('Item request token collision.');
                }
                $payload = json_decode((string) ($receipt['result_payload'] ?? ''), true);
                if (!is_array($payload) || ($receipt['completed_at'] ?? null) === null) {
                    throw new RuntimeException('Item claim is still pending. Please retry.');
                }
                $this->repository->commitMutation();
                return $payload;
            }
            if (($character['life_state'] ?? null) !== 'alive') {
                throw new DomainException('Item drop unavailable.');
            }
            $drop = $this->repository->lockDropForClaim($dropId);
            if ($drop === null
                || (int) ($drop['encounter_character_id'] ?? 0) !== $characterId
                || ($drop['encounter_status'] ?? null) !== 'victory_loot'
                || ($drop['claim_state'] ?? null) === 'none') {
                throw new DomainException('Item drop unavailable.');
            }
            $itemId = (int) ($drop['item_id'] ?? 0);
            $this->repository->createClaimRequest($characterId, $requestToken, $fingerprint, $itemId);
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            if (($drop['claim_state'] ?? null) === 'unclaimed') {
                if (!$this->repository->claimDrop($dropId, $itemId, $characterId, $now)) {
                    throw new RuntimeException('Item claim changed concurrently. Please retry.');
                }
                $drop['claim_state'] = 'claimed';
                $drop['item_character_id'] = $characterId;
            } elseif (($drop['claim_state'] ?? null) !== 'claimed'
                || (int) ($drop['item_character_id'] ?? 0) !== $characterId) {
                throw new DomainException('Item drop unavailable.');
            }
            $affixes = method_exists($this->repository, 'affixesForItems')
                ? ($this->repository->affixesForItems([$itemId])[$itemId] ?? [])
                : [];
            $payload = [
                'drop' => [
                    'id' => $dropId,
                    'claim_state' => 'claimed',
                    'item' => $this->projector->projectItem($drop, $affixes),
                ],
            ];
            $this->repository->completeClaimRequest($characterId, $requestToken, $payload, $now);
            $this->repository->commitMutation();
            return $payload;
        } catch (Throwable $exception) {
            $this->repository->rollBackMutation();
            throw $exception;
        }
    }

    private function mutationLock(array $character): array
    {
        if (($character['life_state'] ?? null) === 'dead') {
            return [true, 'champion_dead'];
        }
        return match ($character['encounter_status'] ?? null) {
            'active' => [true, 'combat_active'],
            'victory_loot' => [true, 'victory_loot_pending'],
            default => [false, null],
        };
    }
}
