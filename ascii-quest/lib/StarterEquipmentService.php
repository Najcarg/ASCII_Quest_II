<?php
declare(strict_types=1);

final class StarterEquipmentService
{
    private const STARTERS = [
        'warrior' => 'basic_sword',
        'mage' => 'basic_wand',
        'rogue' => 'basic_dagger',
        'cleric' => 'basic_mace',
    ];

    public function __construct(private object $repository) {}

    public function grantForNewLockedChampion(array $lockedCharacter, string $classKey): array
    {
        $characterId = filter_var($lockedCharacter['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($characterId === false || !isset(self::STARTERS[$classKey])) {
            throw new InvalidArgumentException('Invalid starter Champion or class.');
        }
        $existing = $this->repository->starterItem($characterId);
        if ($existing !== null) {
            $equipped = $this->repository->equippedUsableWeapon($characterId);
            if ($equipped === null) {
                $this->repository->equipStarterWeapon($characterId, (int) $existing['id']);
            } elseif ((int) $equipped['id'] !== (int) $existing['id']) {
                return ['result' => 'unchanged', 'item_id' => (int) $existing['id']];
            }
            return ['result' => 'unchanged', 'item_id' => (int) $existing['id']];
        }

        $definitionKey = self::STARTERS[$classKey];
        $definition = $this->repository->starterDefinition($definitionKey);
        if ($definition === null
            || ($definition['equipment_slot'] ?? null) !== 'weapon'
            || (int) ($definition['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('Starter item definition is unavailable.');
        }
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $item = $this->repository->createStarterItem($characterId, $definition, $now);
        $this->repository->equipStarterWeapon($characterId, (int) $item['id']);
        return ['result' => 'granted', 'item_id' => (int) $item['id'], 'definition_key' => $definitionKey];
    }

    public function bootstrapExisting(int $userId, int $characterId, bool $apply): array
    {
        if ($userId < 1 || $characterId < 1) {
            throw new OutOfBoundsException('Champion unavailable.');
        }
        $this->repository->beginMutation();
        try {
            $character = $this->repository->lockOwnedCharacterForStarter($userId, $characterId);
            if ($character === null) {
                throw new OutOfBoundsException('Champion unavailable.');
            }
            $this->repository->lockAccountCombatMutex($userId);
            $encounter = $this->repository->lockUnresolvedEncounter($characterId);
            if (($character['life_state'] ?? null) !== 'alive') {
                $result = ['result' => 'skipped_dead', 'character_id' => $characterId];
            } elseif (in_array($encounter['status'] ?? null, ['active', 'victory_loot'], true)) {
                $result = ['result' => 'deferred_combat', 'character_id' => $characterId];
            } elseif ($this->repository->equippedUsableWeapon($characterId) !== null) {
                $result = ['result' => 'unchanged', 'character_id' => $characterId];
            } elseif ($this->repository->ownedUsableWeapon($characterId) !== null) {
                $result = ['result' => 'owned_weapon_available', 'character_id' => $characterId];
            } elseif (!$apply) {
                $result = [
                    'result' => 'would_grant',
                    'character_id' => $characterId,
                    'definition_key' => self::STARTERS[$character['class_key'] ?? ''] ?? null,
                ];
                if ($result['definition_key'] === null) {
                    throw new RuntimeException('Champion class has no starter mapping.');
                }
            } else {
                $result = $this->grantForNewLockedChampion($character, (string) ($character['class_key'] ?? ''));
                $result['character_id'] = $characterId;
            }
            $this->repository->commitMutation();
            return $result;
        } catch (Throwable $exception) {
            $this->repository->rollBackMutation();
            throw $exception;
        }
    }
}
