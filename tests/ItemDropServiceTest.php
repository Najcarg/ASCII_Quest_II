<?php
declare(strict_types=1);

require_once __DIR__ . '/../ascii-quest/lib/ItemDropService.php';
require_once __DIR__ . '/../ascii-quest/lib/ItemRepository.php';
require_once __DIR__ . '/../ascii-quest/lib/ItemProjector.php';
require_once __DIR__ . '/../ascii-quest/lib/InventoryService.php';

final class DropServiceRepositoryFake
{
    public array $drops = [];
    public array $items = [];
    public bool $marked = false;
    public array $unclaimed = [];
    public array $claimed = [];
    public function createNoItemDrop(int $encounterId, int $slot, int $base, int $bonus, int $effective, int $roll, string $at): void { $this->drops[] = compact('encounterId', 'slot', 'base', 'bonus', 'effective', 'roll', 'at') + ['outcome' => 'none']; }
    public function createGeneratedItemDrop(int $encounterId, int $slot, array $item, string $sourceKey, int $base, int $bonus, int $effective, int $roll, string $at): int { $this->items[] = $item + compact('sourceKey'); $this->drops[] = compact('encounterId', 'slot', 'base', 'bonus', 'effective', 'roll', 'at') + ['outcome' => 'unclaimed', 'item_id' => count($this->items)]; return count($this->items); }
    public function markItemDropsGenerated(int $encounterId, string $at): bool { $this->marked = true; return true; }
    public function lockUnclaimedItemDrops(int $encounterId): array { return $this->unclaimed; }
    public function claimLockedItemDrop(int $dropId, int $itemId, int $characterId, string $claimedAt): bool { $this->claimed[] = compact('dropId', 'itemId', 'characterId', 'claimedAt'); return true; }
}

final class ClaimRepositoryFake implements ItemInventoryReader
{
    public array $character = ['id' => 42, 'user_id' => 7, 'life_state' => 'alive'];
    public array $drop;
    public array $receipts = [];
    public int $claimWrites = 0;
    public function __construct()
    {
        $this->drop = [
            'drop_id' => 7, 'claim_state' => 'unclaimed', 'item_id' => 55,
            'encounter_character_id' => 42, 'encounter_status' => 'victory_loot',
            'item_character_id' => null, 'id' => 55, 'item_level' => 1,
            'rarity' => 'normal', 'display_name' => 'Axe', 'snapshot_category' => 'weapon',
            'snapshot_subtype' => 'axe', 'snapshot_equipment_slot' => 'weapon',
            'snapshot_damage_type' => 'physical', 'snapshot_damage_min' => 5,
            'snapshot_damage_max' => 8, 'snapshot_toughness' => 0,
            'snapshot_attack_rate_modifier_bp' => 0, 'snapshot_cast_rate_modifier_bp' => 0,
            'snapshot_block_rate_modifier_bp' => 0, 'glyph' => '/',
        ];
    }
    public function ownedCharacter(int $userId, int $characterId): ?array { return $this->character; }
    public function countOwnedItems(int $userId, int $characterId): int { return 0; }
    public function ownedItemsPage(int $userId, int $characterId, int $limit, int $offset): array { return []; }
    public function definition(string $definitionKey): ?array { return null; }
    public function beginMutation(): void {}
    public function commitMutation(): void {}
    public function rollBackMutation(): void {}
    public function lockOwnedCharacterForMutation(int $userId, int $characterId): ?array { return $userId === 7 && $characterId === 42 ? $this->character : null; }
    public function lockMutationRequest(int $characterId, string $token): ?array { return $this->receipts[$token] ?? null; }
    public function lockDropForClaim(int $dropId): ?array { return $dropId === 7 ? $this->drop : null; }
    public function createClaimRequest(int $characterId, string $token, string $fingerprint, int $itemId): void { $this->receipts[$token] = ['command_type' => 'claim', 'request_fingerprint' => $fingerprint, 'result_payload' => null, 'completed_at' => null]; }
    public function claimDrop(int $dropId, int $itemId, int $characterId, string $at): bool { $this->claimWrites++; $this->drop['claim_state'] = 'claimed'; $this->drop['item_character_id'] = $characterId; return true; }
    public function completeClaimRequest(int $characterId, string $token, array $payload, string $at): void { $this->receipts[$token]['result_payload'] = json_encode($payload); $this->receipts[$token]['completed_at'] = $at; }
    public function affixesForItems(array $ids): array { return []; }
}

return [
    'Drop chance uses bounded server-side basis point formula' => function (): void {
        assertSameValue(625, ItemDropService::effectiveChanceBasisPoints(500, 2500), '25 percent bonus.');
        assertSameValue(10000, ItemDropService::effectiveChanceBasisPoints(9000, 5000), 'Cap.');
    },

    'Victory persists an explicit no-drop outcome and never rerolls marked state' => function (): void {
        $repo = new DropServiceRepositoryFake();
        $service = new ItemDropService($repo, new ItemGenerator(generatorRegistry()), new QueuedItemRandomSource([5001]), 1, 5000);
        $encounter = ['id' => 9, 'loot_source_level' => 1, 'item_drops_generated_at' => null];
        $service->generateForVictory($encounter, '2026-10-05 12:00:00.000000');
        assertSameValue('none', $repo->drops[0]['outcome'] ?? null, 'Durable none.');
        assertSameValue(true, $repo->marked, 'Generation marker.');
        $service->generateForVictory($encounter, '2026-10-05 12:00:01.000000');
        assertSameValue(1, count($repo->drops), 'No refresh reroll.');
    },

    'Victory persists a complete immutable item using encounter source level' => function (): void {
        $repo = new DropServiceRepositoryFake();
        $random = new QueuedItemRandomSource([1, 1, 91, 1, 1, 1, 1, 1, 5]);
        $service = new ItemDropService($repo, new ItemGenerator(generatorRegistry()), $random, 1, 5000);
        $encounter = ['id' => 9, 'loot_source_level' => 1, 'item_drops_generated_at' => null];
        $service->generateForVictory($encounter, '2026-10-05 12:00:00.000000');
        assertSameValue('unclaimed', $repo->drops[0]['outcome'] ?? null, 'Durable item outcome.');
        assertSameValue(1, $repo->items[0]['item_level'] ?? null, 'Source-bound item level.');
        assertSameValue('encounter:9:reward:1', $repo->items[0]['sourceKey'] ?? null, 'Stable provenance.');
    },

    'Legacy encounters with NULL source level never generate retroactive drops' => function (): void {
        $repo = new DropServiceRepositoryFake();
        $service = new ItemDropService($repo, new ItemGenerator(generatorRegistry()), new QueuedItemRandomSource([]), 1, 5000);
        $encounter = ['id' => 9, 'loot_source_level' => null, 'item_drops_generated_at' => null];
        $service->generateForVictory($encounter, '2026-10-05 12:00:00.000000');
        assertSameValue([], $repo->drops, 'No legacy outcome.');
        assertSameValue(false, $repo->marked, 'No retroactive marker.');
    },

    'Close auto-claim enumerates server-side unclaimed drops exactly once' => function (): void {
        $repo = new DropServiceRepositoryFake();
        $repo->unclaimed = [['id' => 7, 'item_id' => 55]];
        $service = new ItemDropService($repo, new ItemGenerator(generatorRegistry()), new QueuedItemRandomSource([]), 1, 500);
        $service->autoClaimAll(9, 42, '2026-10-05 12:00:00.000000');
        assertSameValue([['dropId' => 7, 'itemId' => 55, 'characterId' => 42, 'claimedAt' => '2026-10-05 12:00:00.000000']], $repo->claimed, 'Server-selected transfer.');
    },

    'Explicit claim transfers once and UUID replay returns the stable result' => function (): void {
        $repo = new ClaimRepositoryFake();
        $service = new InventoryService($repo, new ItemProjector());
        $token = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $first = $service->claim(7, 42, 7, $token);
        $replay = $service->claim(7, 42, 7, $token);
        assertSameValue('claimed', $first['drop']['claim_state'] ?? null, 'Claimed.');
        assertSameValue($first, $replay, 'Stable replay.');
        assertSameValue(1, $repo->claimWrites, 'One ownership transfer.');
    },

    'Claim token collision and cross-Champion access are rejected generically' => function (): void {
        $repo = new ClaimRepositoryFake();
        $service = new InventoryService($repo, new ItemProjector());
        $token = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $service->claim(7, 42, 7, $token);
        try { $service->claim(7, 42, 8, $token); throw new RuntimeException('Expected collision.'); }
        catch (DomainException) {}
        $repo = new ClaimRepositoryFake();
        $repo->drop['encounter_character_id'] = 99;
        try { (new InventoryService($repo, new ItemProjector()))->claim(7, 42, 7, $token); throw new RuntimeException('Expected ownership rejection.'); }
        catch (DomainException $exception) { assertSameValue('Item drop unavailable.', $exception->getMessage(), 'Generic denial.'); }
    },

    'DEAD and non-victory Champions cannot claim gameplay drops' => function (): void {
        $token = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $dead = new ClaimRepositoryFake(); $dead->character['life_state'] = 'dead';
        try { (new InventoryService($dead, new ItemProjector()))->claim(7, 42, 7, $token); throw new RuntimeException('Expected DEAD rejection.'); } catch (DomainException) {}
        $active = new ClaimRepositoryFake(); $active->drop['encounter_status'] = 'active';
        try { (new InventoryService($active, new ItemProjector()))->claim(7, 42, 7, $token); throw new RuntimeException('Expected state rejection.'); } catch (DomainException) {}
        assertSameValue(0, $dead->claimWrites + $active->claimWrites, 'No ownership changes.');
    },

    'Affix projection exposes display stats but hides generation and ownership internals' => function (): void {
        $repo = new ClaimRepositoryFake();
        $item = (new ItemProjector())->projectItem($repo->drop, [[
            'position' => 'prefix', 'tier' => 1, 'display_fragment' => 'Hunter',
            'modifier_type' => 'flat_damage', 'modifier_operation' => 'flat', 'rolled_value' => 3,
            'affix_key' => 'hunter', 'family_key' => 'damage_flat',
            'minimum_value' => 1, 'maximum_value' => 3,
        ]]);
        assertSameValue('+3 Damage', $item['stat_lines'][0] ?? null, 'Public derived line.');
        $serialized = json_encode($item, JSON_THROW_ON_ERROR);
        foreach (['affix_key', 'family_key', 'minimum_value', 'maximum_value', 'character_id', 'source_key', 'chance_roll_bp'] as $hidden) {
            assertSameValue(false, str_contains($serialized, $hidden), $hidden . ' remains private.');
        }
    },
];
