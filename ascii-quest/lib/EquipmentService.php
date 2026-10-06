<?php
declare(strict_types=1);

require_once __DIR__ . '/CharacterStats.php';
require_once __DIR__ . '/EquipmentStatAggregator.php';

final class EquipmentService
{
    private const SLOTS = ['helm', 'gloves', 'chest', 'ring', 'weapon', 'off-hand', 'amulet', 'belt', 'charm', 'boots'];

    public function __construct(
        private object $repository,
        private EquipmentStatAggregator $aggregator,
    ) {}

    public function equip(int $userId, int $characterId, int $itemId, string $slot, string $requestToken): array
    {
        $this->validateIntent($userId, $characterId, $itemId, $requestToken);
        if (!in_array($slot, self::SLOTS, true)) {
            throw new InvalidArgumentException('Invalid equipment slot.');
        }
        $fingerprint = $this->fingerprint('equip', $characterId, $itemId, $slot);

        $this->repository->beginMutation();
        try {
            [$character, $encounter] = $this->lockAuthority($userId, $characterId);
            $replay = $this->replay($characterId, $requestToken, 'equip', $fingerprint);
            if ($replay !== null) {
                $this->repository->commitMutation();
                return $replay;
            }
            $this->assertMutable($character, $encounter);

            $item = $this->repository->lockOwnedItem($characterId, $itemId);
            if ($item === null) {
                throw new OutOfBoundsException('Item unavailable.');
            }
            if (($item['snapshot_equipment_slot'] ?? null) !== $slot) {
                throw new DomainException('Item is incompatible with that equipment slot.');
            }
            $equipped = $this->repository->lockEquippedItems($characterId);
            foreach ($equipped as $row) {
                if ((int) ($row['id'] ?? 0) === $itemId && ($row['equipment_slot'] ?? null) !== $slot) {
                    throw new DomainException('Item is already equipped elsewhere.');
                }
            }

            $this->repository->createEquipmentRequest($characterId, $requestToken, 'equip', $fingerprint, $itemId, null, $slot);
            $displaced = $this->repository->equipItem($characterId, $itemId, $slot);
            $this->clampResources($characterId, $character);
            $payload = [
                'result' => $displaced === $itemId ? 'already_equipped' : 'equipped',
                'item_id' => $itemId,
                'slot' => $slot,
                'displaced_item_id' => $displaced !== null && $displaced !== $itemId ? $displaced : null,
            ];
            $this->complete($characterId, $requestToken, $payload);
            $this->repository->commitMutation();
            return $payload;
        } catch (Throwable $exception) {
            $this->repository->rollBackMutation();
            throw $exception;
        }
    }

    public function unequip(int $userId, int $characterId, int $itemId, string $requestToken): array
    {
        $this->validateIntent($userId, $characterId, $itemId, $requestToken);
        $fingerprint = $this->fingerprint('unequip', $characterId, $itemId, null);

        $this->repository->beginMutation();
        try {
            [$character, $encounter] = $this->lockAuthority($userId, $characterId);
            $replay = $this->replay($characterId, $requestToken, 'unequip', $fingerprint);
            if ($replay !== null) {
                $this->repository->commitMutation();
                return $replay;
            }
            $this->assertMutable($character, $encounter);
            if ($this->repository->lockOwnedItem($characterId, $itemId) === null) {
                throw new OutOfBoundsException('Item unavailable.');
            }
            $equipped = $this->repository->lockEquippedItems($characterId);
            $sourceSlot = null;
            foreach ($equipped as $row) {
                if ((int) ($row['id'] ?? 0) === $itemId) {
                    $sourceSlot = $row['equipment_slot'] ?? null;
                    break;
                }
            }
            if (!is_string($sourceSlot) || !in_array($sourceSlot, self::SLOTS, true)) {
                throw new DomainException('Item is not equipped.');
            }

            $this->repository->createEquipmentRequest($characterId, $requestToken, 'unequip', $fingerprint, $itemId, $sourceSlot, null);
            if ($this->repository->unequipItem($characterId, $itemId) !== $sourceSlot) {
                throw new RuntimeException('Equipment changed concurrently. Please retry.');
            }
            $this->clampResources($characterId, $character);
            $payload = ['result' => 'unequipped', 'item_id' => $itemId, 'slot' => $sourceSlot, 'displaced_item_id' => null];
            $this->complete($characterId, $requestToken, $payload);
            $this->repository->commitMutation();
            return $payload;
        } catch (Throwable $exception) {
            $this->repository->rollBackMutation();
            throw $exception;
        }
    }

    private function lockAuthority(int $userId, int $characterId): array
    {
        $character = $this->repository->lockOwnedCharacterForEquipment($userId, $characterId);
        if ($character === null) {
            throw new OutOfBoundsException('Champion unavailable.');
        }
        $this->repository->lockAccountCombatMutex($userId);
        return [$character, $this->repository->lockUnresolvedEncounter($characterId)];
    }

    private function assertMutable(array $character, ?array $encounter): void
    {
        if (($character['life_state'] ?? null) !== 'alive') {
            throw new DomainException('Equipment is unavailable.');
        }
        if (in_array($encounter['status'] ?? null, ['active', 'victory_loot'], true)) {
            throw new DomainException('Equipment is unavailable during combat.');
        }
    }

    private function replay(int $characterId, string $token, string $command, string $fingerprint): ?array
    {
        $receipt = $this->repository->lockMutationRequest($characterId, $token);
        if ($receipt === null) {
            return null;
        }
        if (($receipt['command_type'] ?? null) !== $command || ($receipt['request_fingerprint'] ?? null) !== $fingerprint) {
            throw new DomainException('Item request token collision.');
        }
        $payload = json_decode((string) ($receipt['result_payload'] ?? ''), true);
        if (!is_array($payload) || ($receipt['completed_at'] ?? null) === null) {
            throw new RuntimeException('Equipment mutation is still pending. Please retry.');
        }
        return $payload;
    }

    private function clampResources(int $characterId, array $character): void
    {
        $modifiers = $this->aggregator->aggregate($this->repository->lockEquippedItems($characterId));
        $stats = CharacterStats::calculate($character, $modifiers);
        $currentHp = max(0, min((int) $character['current_hp'], (int) $stats['resources']['max_life']));
        $currentMana = max(0, min((int) $character['current_mana'], (int) $stats['resources']['max_mana']));
        $this->repository->clampResources($characterId, $currentHp, $currentMana);
    }

    private function complete(int $characterId, string $token, array $payload): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->repository->completeEquipmentRequest($characterId, $token, $payload, $now);
    }

    private function validateIntent(int $userId, int $characterId, int $itemId, string $token): void
    {
        if ($userId < 1 || $characterId < 1 || $itemId < 1) {
            throw new OutOfBoundsException('Item unavailable.');
        }
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $token) !== 1) {
            throw new InvalidArgumentException('Invalid item request token.');
        }
    }

    private function fingerprint(string $command, int $characterId, int $itemId, ?string $slot): string
    {
        return hash('sha256', json_encode([
            'command' => $command,
            'character_id' => $characterId,
            'item_id' => $itemId,
            'slot' => $slot,
        ], JSON_THROW_ON_ERROR));
    }
}
