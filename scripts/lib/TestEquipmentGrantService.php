<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../../ascii-quest/lib/ItemDefinitionRegistry.php';
require_once __DIR__ . '/../../ascii-quest/lib/ItemProjector.php';
require_once __DIR__ . '/../../ascii-quest/lib/EquipmentStatAggregator.php';

/** Maintenance only. Inventory snapshots use the ordinary production readers/stats. */
final class TestEquipmentGrantService
{
    public function __construct(private object $repository) {}

    /** Only test key, real base/affix keys, tier and fixed value are specified here. */
    public static function specification(): array
    {
        return [
            'accuracy' => ['worn_sword', 'keen', 2, 7],
            'critical_chance' => ['worn_sword', 'deadly', 2, 7],
            'critical_damage' => ['worn_sword', 'savage', 2, 20],
            'life_on_hit' => ['copper_ring', 'vampiric', 2, 7],
            'dodge' => ['hide_boots', 'of_evasion', 2, 7],
            'block_rate' => ['wooden_shield', 'of_guarding', 2, 5000],
            'fire_damage' => ['copper_amulet', 'flaming', 2, 7],
            'lightning_damage' => ['copper_amulet', 'stormcharged', 2, 7],
            'cold_damage' => ['copper_amulet', 'frosted', 2, 7],
            'poison' => ['copper_charm', 'venomous', 2, 7],
            'bleed' => ['copper_charm', 'bleeding', 2, 7],
            'burn' => ['copper_charm', 'scorching', 2, 7],
            'freeze' => ['copper_charm', 'freezing', 2, 7],
            'shock' => ['copper_charm', 'shocking', 2, 7],
        ];
    }

    public function execute(int $userId, int $characterId, bool $apply = false, bool $cleanup = false): array
    {
        if ($userId < 1 || $characterId < 1) { throw new OutOfBoundsException('Champion unavailable.'); }
        $this->repository->beginMutation();
        try {
            $character = $this->repository->lockOwnedCharacter($userId, $characterId);
            if ($character === null) { throw new OutOfBoundsException('Champion unavailable.'); }
            // Same existing users-row mutex as equipment/starter/combat; no bootstrap INSERT.
            $this->repository->lockAccountCombatMutex($userId);
            $encounter = $this->repository->lockUnresolvedEncounter($characterId);
            $result = [
                'character_id' => $characterId, 'user_id' => $userId,
                'mode' => ($cleanup ? 'cleanup_' : 'grant_') . ($apply ? 'apply' : 'preview'),
                'existing_task32' => null, 'created' => 0, 'would_create' => 0,
                'already_exists' => 0, 'retired_exists' => 0, 'retired' => 0, 'would_retire' => 0,
                'invalid_dependencies' => [], 'items' => [], 'final_count' => null,
            ];
            if (($character['life_state'] ?? null) !== 'alive') {
                $result['result'] = 'skipped_dead';
            } elseif (in_array($encounter['status'] ?? null, ['active', 'victory_loot'], true)) {
                $result['result'] = 'deferred_combat';
            } else {
                $existing = $this->repository->taskItems($characterId, $cleanup);
                $result['existing_task32'] = count($existing);
                $result['final_count'] = count(array_filter($existing, static fn (array $i): bool => $i['retired_at'] === null));
                if ($cleanup) {
                    // Inspect every active target before retirement; never automatically unequip.
                    foreach ($existing as $item) { $this->repository->assertCleanupSafe((int) $item['id']); }
                    if ($existing === []) {
                        $result['result'] = 'already_clean';
                    } elseif ($apply) {
                        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
                        $result['retired'] = $this->repository->retireTaskItems($characterId, $now);
                        if ($result['retired'] !== count($existing)) { throw new RuntimeException('Cleanup changed concurrently.'); }
                        $result['final_count'] = 0;
                        $result['result'] = 'retired';
                    } else {
                        $result['would_retire'] = count($existing);
                        $result['result'] = 'would_cleanup';
                    }
                } else {
                    $result = $this->grant($characterId, $apply, $existing, $result);
                }
            }
            // Preview ends in rollback even though all its queries are read/lock only.
            if ($apply && ($result['result'] ?? null) !== 'failed') { $this->repository->commitMutation(); }
            else { $this->repository->rollBackMutation(); }
            return $result;
        } catch (Throwable $e) {
            $this->repository->rollBackMutation();
            throw $e;
        }
    }

    private function grant(int $characterId, bool $apply, array $existing, array $result): array
    {
        $spec = self::specification();
        $byKey = [];
        foreach ($existing as $item) {
            $key = substr((string) $item['source_key'], strlen('task32:'));
            if (!isset($spec[$key]) || isset($byKey[$key])
                || (int) $item['character_id'] !== $characterId
                || $item['definition_key'] !== $spec[$key][0]
                || count($item['affixes']) !== 1
                || $item['affixes'][0]['affix_key'] !== $spec[$key][1]
                || (int) $item['affixes'][0]['tier'] !== $spec[$key][2]) {
                throw new RuntimeException('Task32 provenance identity mismatch.');
            }
            $byKey[$key] = $item; // Historical names/values/stats are immutable, never repaired.
        }
        $snapshots = [];
        // Validate the whole set when any insertion is needed. Existing immutable
        // identities (including retired ones) do not depend on today's catalogue.
        $dependenciesToValidate = array_diff_key($spec, $byKey) === [] ? [] : $spec;
        foreach ($dependenciesToValidate as $key => [$base, $affix, $tier, $value]) {
            try {
                $snapshots[$key] = $this->snapshot($key, $base, $affix, $tier, $value);
            } catch (PDOException $e) {
                throw $e; // Database/lock failures are not catalogue-validation failures.
            } catch (Throwable $e) {
                $result['invalid_dependencies'][] = $key;
            }
        }
        if ($result['invalid_dependencies'] !== []) {
            $result['result'] = 'failed';
            return $result; // No insertion can have occurred; full dependency validation first.
        }
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        foreach ($spec as $key => $_) {
            $snapshot = $snapshots[$key] ?? null;
            if (isset($byKey[$key])) {
                $code = $byKey[$key]['retired_at'] !== null ? 'retired_exists' : 'already_exists';
                $result[$code]++;
            } elseif ($apply) {
                $this->repository->createItem($characterId, $snapshot, $now);
                $code = 'created';
                $result['created']++;
            } else {
                $code = 'would_create';
                $result['would_create']++;
            }
            $result['items'][$key] = ['result' => $code, 'display_name' => $byKey[$key]['display_name'] ?? $snapshot['display_name']];
        }
        $result['final_count'] += $result['created'];
        $result['planned_final_count'] = $result['final_count'] + $result['would_create'];
        $result['result'] = $apply ? ($result['created'] > 0 ? 'created' : ($result['retired_exists'] > 0 ? 'retired_exists' : 'already_exists')) : 'preview';
        return $result;
    }

    private function snapshot(string $key, string $base, string $affixKey, int $tierNumber, int $value): array
    {
        [$d, $a, $tier] = $this->repository->dependencies($base, $affixKey, $tierNumber);
        if ($d === null || $a === null || $tier === null
            || (int) ($d['is_active'] ?? 0) !== 1 || (int) ($a['is_active'] ?? 0) !== 1
            || $d['definition_key'] !== $base || $a['affix_key'] !== $affixKey
            || !in_array($d['maximum_rarity'], ['magic', 'rare'], true)
            || (int) $d['minimum_item_level'] > 10
            || (int) $tier['tier'] !== $tierNumber || (int) $tier['minimum_item_level'] > 10
            || $value < (int) $tier['minimum_value'] || $value > (int) $tier['maximum_value']) {
            throw new RuntimeException('Invalid dependency.');
        }
        $registry = new ItemDefinitionRegistry([$d], [$a], ['magic' => 1]);
        if ($registry->eligibleAffixes($d, $a['position'], 10, []) === []) {
            throw new RuntimeException('Ineligible affix.');
        }
        $affix = array_intersect_key($a, array_flip(['affix_key', 'family_key', 'position', 'display_fragment', 'modifier_type', 'modifier_operation']));
        $affix += ['tier' => $tierNumber, 'rolled_value' => $value];
        $item = [
            'definition_key' => $base, 'item_level' => 10, 'rarity' => 'magic',
            'display_name' => $a['position'] === 'prefix' ? $a['display_fragment'] . ' ' . $d['display_name'] : $d['display_name'] . ' ' . $a['display_fragment'],
            'glyph' => $d['glyph'], 'source_type' => 'maintenance_test', 'source_key' => 'task32:' . $key,
            'affixes' => [$affix],
        ];
        foreach (['category', 'subtype', 'equipment_slot', 'damage_type'] as $field) {
            if (!array_key_exists($field, $d)) { throw new RuntimeException('Missing base snapshot field.'); }
            $item['snapshot_' . $field] = $d[$field];
        }
        foreach (['damage_min', 'damage_max', 'toughness', 'attack_rate_modifier_bp', 'cast_rate_modifier_bp', 'block_rate_modifier_bp'] as $field) {
            if (!array_key_exists('base_' . $field, $d)) { throw new RuntimeException('Missing base snapshot field.'); }
            $item['snapshot_' . $field] = $d['base_' . $field];
        }
        // Validate against the real public and stat contracts before ANY insert.
        (new ItemProjector())->projectItem(['id' => 1] + $item, [$affix]);
        (new EquipmentStatAggregator())->aggregate([['id' => 1] + $item]);
        return $item;
    }
}
