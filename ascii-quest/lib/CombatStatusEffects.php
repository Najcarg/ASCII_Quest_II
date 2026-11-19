<?php
declare(strict_types=1);
require_once __DIR__ . '/CombatStatusRules.php';

require_once __DIR__ . '/CharacterStats.php';
require_once __DIR__ . '/SkillCatalogue.php';
require_once __DIR__ . '/CombatRepository.php';
require_once __DIR__ . '/CombatRandomSource.php';

/** Enemy-only equipment statuses. No wall clock, gear reads or process-local state. */
final class CombatStatusEffects
{
    public const KEYS = CombatStatusRules::KEYS;
    private const LABELS = ['poison' => 'Poisoned', 'bleed' => 'Bleeding', 'burn' => 'Burning', 'freeze' => 'Frozen', 'shock' => 'Shocked'];
    private const TICK_LABELS = ['poison' => 'Poison', 'bleed' => 'Bleed', 'burn' => 'Burn'];

    public function __construct(private object $repository) {}

    public function applyContact(array $encounter, array $action, array $damage, array $enemy, CombatRandomSource $random): void
    {
        if (($encounter['status'] ?? null) !== 'active' || ($action['actor'] ?? null) !== 'player' ||
            ($action['action_kind'] ?? null) !== 'weapon' || ($action['state'] ?? null) !== 'pending' ||
            !in_array($damage['outcome'] ?? null, ['HIT', 'CRITICAL', 'BLOCK'], true)) {
            return;
        }
        $powers = [];
        foreach (self::KEYS as $key) {
            $column = 'snapshot_' . $key . '_status_damage';
            if (!array_key_exists($column, $action)) { throw new DomainException('Missing combat status snapshot.'); }
            $powers[$key] = $action[$column];
        }
        $this->applyPowers($encounter,$action,$damage,$enemy,$random,$powers,CombatStatusRules::DURATION_MS);
    }

    /** Explicit intrinsic skill statuses only; never read equipment status snapshots. */
    public function applySkillContact(array $encounter, array $action, array $damage, array $enemy, CombatRandomSource $random): void
    {
        if (($encounter['status'] ?? null) !== 'active' || ($action['actor'] ?? null) !== 'player'
            || ($action['action_kind'] ?? null) !== 'skill' || ($action['state'] ?? null) !== 'pending'
            || ($action['snapshot_skill_definition'] ?? null) === null
            || !in_array($damage['outcome'] ?? null,['HIT','CRITICAL','BLOCK'],true)) { return; }
        $definition = SkillCatalogue::decodeSnapshot($action['snapshot_skill_definition']);
        $status = $definition['intrinsic_status'] ?? null;
        if ($status === null) { return; }
        $powers = array_fill_keys(self::KEYS,0);
        $powers[$status['key']] = $status['power'];
        $this->applyPowers($encounter,$action,$damage,$enemy,$random,$powers,$status['duration_ms'],[$status['key']=>$status['chance_percent']]);
    }

    private function applyPowers(array $encounter,array $action,array $damage,array $enemy,CombatRandomSource $random,array $powers,int $durationMs,array $frozenChances=[]): void
    {
        foreach (self::KEYS as $key) {
            $value = $powers[$key];
            if ($value === null) { continue; } // Immutable pre-status legacy snapshot.
            if (is_string($value) && ctype_digit($value)) { $value = (int) $value; }
            if (!is_int($value) || $value < 0) { throw new DomainException('Invalid combat status snapshot.'); }
            if ($value === 0) { continue; }
            $chances = array_fill_keys(self::KEYS,0); $chances[$key] = $value;
            $chance = $frozenChances[$key] ?? CharacterStats::statusEffectChance($chances);
            $bonus = $action['snapshot_elemental_status_chance'] ?? 0; // NULL legacy actions retain old odds.
            if (is_string($bonus) && ctype_digit($bonus)) { $bonus = (int)$bonus; }
            if (!is_int($bonus)) { throw new DomainException('Invalid elemental status chance snapshot.'); }
            $chance = CharacterStats::elementalStatusChance($chance,$key,$bonus);
            if ($random->integer(1,100) > $chance) { continue; }
            if ($this->repository->applyStatusEffect((int)$encounter['id'],(int)$action['id'],$key,$value,(int)$action['resolves_timeline_ms'],$durationMs)) {
                $this->repository->appendEvent((int)$encounter['id'],'status_applied_'.$key,$enemy['name'].' is '.self::LABELS[$key].'.',null);
            }
        }
    }

    public function potency(int $encounterId, string $key, int $now): int
    {
        foreach ($this->repository->lockStatusEffectsForEncounter($encounterId) as $status) {
            if ($status['status_key'] === $key && (int) $status['applied_timeline_ms'] <= $now && (int) $status['expires_timeline_ms'] > $now) {
                return (int) $status['potency'];
            }
        }
        return 0;
    }

    public function nextDue(int $encounterId): ?int
    {
        $due = [];
        foreach ($this->repository->lockStatusEffectsForEncounter($encounterId) as $status) {
            $due[] = $status['next_tick_timeline_ms'] === null ? (int) $status['expires_timeline_ms'] : (int) $status['next_tick_timeline_ms'];
        }
        return $due === [] ? null : min($due);
    }

    /** Ties: status row id before action resolution, inclusive fourth tick then expiry. */
    public function processAt(array $encounter, array $enemy, int $now): array
    {
        if (($encounter['status'] ?? null) !== 'active' || (int) $encounter['enemy_current_hp'] === 0) { return $encounter; }
        $id = (int) $encounter['id'];
        foreach ($this->repository->lockStatusEffectsForEncounter($id) as $status) {
            if ((int) $encounter['enemy_current_hp'] === 0) { break; }
            $next = $status['next_tick_timeline_ms'];
            $expires = (int) $status['expires_timeline_ms'];
            if ($next !== null && (int) $next <= $now) {
                if ((int) $next !== $now || $now > $expires) {
                    throw new DomainException('Combat status schedule is behind its timeline.');
                }
                $key = (string) $status['status_key'];
                $resistance = match ($key) {
                    'poison' => (int) $enemy['server_only']['resistances']['poison'],
                    'burn' => (int) $enemy['server_only']['resistances']['fire'],
                    'bleed' => 0,
                    default => throw new DomainException('Non-damaging status has a tick.'),
                };
                $damage = max(0, (int) round((int) $status['potency'] * (1 - $resistance / 100)));
                $encounter['enemy_current_hp'] = max(0, (int) $encounter['enemy_current_hp'] - $damage);
                $this->repository->advanceStatusEffect($id, $status, $now < $expires ? $now + 1000 : null, $now < $expires ? 'active' : 'expired');
                $this->repository->appendEvent($id, 'status_tick_' . $key, self::TICK_LABELS[$key] . " deals {$damage} damage.", null);
            } elseif ($expires <= $now) {
                $this->repository->advanceStatusEffect($id, $status, null, 'expired');
            }
        }
        return $encounter;
    }

    public function project(array $encounter): array
    {
        if (($encounter['status'] ?? null) !== 'active') { return []; }
        $now = (int) $encounter['timeline_elapsed_ms'];
        $result = [];
        foreach ($this->repository->statusEffectsForEncounter((int) $encounter['id']) as $status) {
            if ((int) $status['applied_timeline_ms'] <= $now && (int) $status['expires_timeline_ms'] > $now) {
                $result[] = ['key' => (string) $status['status_key'], 'potency' => (int) $status['potency'], 'remaining_ms' => (int) $status['expires_timeline_ms'] - $now];
            }
        }
        return $result;
    }
}
