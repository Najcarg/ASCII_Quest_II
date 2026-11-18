<?php
declare(strict_types=1);
require_once __DIR__ . '/CharacterProgression.php';
require_once __DIR__ . '/CharacterStats.php';

/** Validated DB catalogue. No independent PHP catalogue/balance/prerequisite rules. */
final class SkillCatalogue
{
    public const SLOTS = ['skill_1','skill_2','skill_3','ultimate'];
    public const CLASSES = ['warrior','mage','rogue','cleric'];
    public const STATUS_KEYS = ['poison','bleed','burn','freeze','shock'];
    private array $skills = [];

    public function __construct(array $definitions, array $prerequisites)
    {
        foreach ($definitions as $row) {
            $key = $row['skill_key'] ?? null;
            if (!is_string($key) || preg_match('/\A[a-z][a-z0-9_]{1,63}\z/D', $key) !== 1 || isset($this->skills[$key])) {
                throw new UnexpectedValueException('Invalid skill identity.');
            }
            $class = $row['class_key'] ?? null;
            if (!in_array($class, self::CLASSES, true) || !str_starts_with($key, $class . '_')) { throw new UnexpectedValueException('Invalid skill class.'); }
            foreach (['class_id','minimum_level','base_damage','duration_ms','cooldown_ms','status_power','is_active'] as $field) {
                $row[$field] = CharacterProgression::nonNegativeInteger($row[$field] ?? null);
            }
            if ($row['class_id'] < 1 || !in_array($row['tree_position'] ?? null, ['root','branch_1','branch_2','ultimate'], true)
                || !in_array($row['skill_type'] ?? null, ['normal','ultimate'], true)
                || (($row['tree_position'] === 'ultimate') !== ($row['skill_type'] === 'ultimate'))
                || $row['minimum_level'] < 2 || $row['minimum_level'] > 50 || $row['base_damage'] < 1 || $row['base_damage'] > 10000
                || $row['duration_ms'] < 100 || $row['duration_ms'] > 10000 || $row['cooldown_ms'] < 100 || $row['cooldown_ms'] > 600000
                || !in_array($row['damage_type'] ?? null, ['physical','fire','cold','lightning','poison'], true)
                || !in_array($row['is_active'], [0,1], true)
                || !is_string($row['display_name'] ?? null) || trim($row['display_name']) === ''
                || !is_string($row['description'] ?? null) || trim($row['description']) === '') {
                throw new UnexpectedValueException('Invalid skill definition.');
            }
            if (($row['status_key'] ?? null) === null) {
                if ($row['status_power'] !== 0) { throw new UnexpectedValueException('Invalid empty skill status.'); }
                $row['status_key'] = null;
            } elseif (!in_array($row['status_key'], self::STATUS_KEYS, true) || $row['status_power'] < 1 || $row['status_power'] > 100) {
                throw new UnexpectedValueException('Invalid intrinsic skill status.');
            }
            $row['prerequisites'] = [];
            $this->skills[$key] = $row;
        }
        foreach ($prerequisites as $edge) {
            $child = $edge['skill_key'] ?? ''; $parent = $edge['prerequisite_key'] ?? '';
            if (!isset($this->skills[$child], $this->skills[$parent]) || $child === $parent
                || $this->skills[$child]['class_id'] !== $this->skills[$parent]['class_id']
                || in_array($parent, $this->skills[$child]['prerequisites'], true)) {
                throw new UnexpectedValueException('Invalid skill prerequisite.');
            }
            $this->skills[$child]['prerequisites'][] = $parent;
        }
        foreach (array_keys($this->skills) as $key) { $this->assertAcyclic($key, []); }
        foreach (self::CLASSES as $class) {
            $nodes = $this->forClass($class);
            $positions = array_column($nodes, 'tree_position'); sort($positions);
            if ($positions !== ['branch_1','branch_2','root','ultimate']) { throw new UnexpectedValueException('Incomplete class skill tree.'); }
        }
    }

    public function skill(string $key): ?array { return $this->skills[$key] ?? null; }
    public function all(): array { return array_values($this->skills); }
    public function forClass(string $key): array
    {
        $order = ['root'=>0,'branch_1'=>1,'branch_2'=>2,'ultimate'=>3];
        $rows = array_values(array_filter($this->skills, static fn (array $s): bool => $s['class_key'] === $key));
        usort($rows, static fn (array $a, array $b): int => $order[$a['tree_position']] <=> $order[$b['tree_position']]);
        return $rows;
    }

    public static function compatible(string $type, string $slot): bool
    {
        return in_array($slot, self::SLOTS, true) && (($slot === 'ultimate') === ($type === 'ultimate')) && in_array($type, ['normal','ultimate'], true);
    }

    public function action(string $key, ?string $slot = null): array
    {
        $row = $this->skill($key) ?? throw new DomainException('Skill unavailable.');
        $slot ??= $row['skill_type'] === 'ultimate' ? 'ultimate' : 'skill_1';
        if (!self::compatible($row['skill_type'], $slot)) { throw new DomainException('Skill slot incompatible.'); }
        return [
            'key' => $row['skill_key'], 'name' => $row['display_name'], 'description' => $row['description'],
            'kind' => 'skill', 'skill_type' => $row['skill_type'], 'class_key' => $row['class_key'],
            'slot' => $slot, 'damage_type' => $row['damage_type'], 'prototype_damage' => $row['base_damage'],
            'duration_seconds' => $row['duration_ms'] / 1000, 'cooldown_seconds' => $row['cooldown_ms'] / 1000,
            'intrinsic_status' => $row['status_key'] === null ? null : [
                'key' => $row['status_key'], 'power' => $row['status_power'], 'duration_ms' => 4000,
            ],
        ];
    }

    public function actions(): array
    {
        $result = [];
        foreach ($this->skills as $key => $_) { $result[$key] = $this->action($key); }
        return $result;
    }

    public static function encodeSnapshot(array $action): string
    {
        return json_encode(['version'=>1,'action'=>$action], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** Frozen semantic validation only. Never looks up today's catalogue/loadout. */
    public static function decodeSnapshot(string $json): array
    {
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        $a = $data['action'] ?? null;
        if (($data['version'] ?? null) !== 1 || !is_array($a) || ($a['kind'] ?? null) !== 'skill'
            || !in_array($a['class_key'] ?? null, self::CLASSES, true)
            || !is_string($a['key'] ?? null) || !str_starts_with($a['key'], $a['class_key'] . '_')
            || preg_match('/\A[a-z][a-z0-9_]{1,63}\z/D', $a['key']) !== 1
            || !is_string($a['name'] ?? null) || trim($a['name']) === ''
            || !is_string($a['description'] ?? null) || !self::compatible((string) ($a['skill_type'] ?? ''), (string) ($a['slot'] ?? ''))
            || !in_array($a['damage_type'] ?? null, ['physical','fire','cold','lightning','poison'], true)
            || !is_int($a['prototype_damage'] ?? null) || $a['prototype_damage'] < 1 || $a['prototype_damage'] > 10000) {
            throw new UnexpectedValueException('Invalid frozen skill action.');
        }
        foreach (['duration_seconds'=>10,'cooldown_seconds'=>600] as $field => $maximum) {
            $value = $a[$field] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0.1 || $value > $maximum) {
                throw new UnexpectedValueException('Invalid frozen skill timing.');
            }
        }
        $status = $a['intrinsic_status'] ?? null;
        if ($status !== null && (!is_array($status) || !in_array($status['key'] ?? null, self::STATUS_KEYS, true)
            || !is_int($status['power'] ?? null) || $status['power'] < 1 || $status['power'] > 100 || ($status['duration_ms'] ?? null) !== 4000)) {
            throw new UnexpectedValueException('Invalid frozen intrinsic status.');
        }
        // Return only fields owned by this frozen schema, never an injected legacy effect.
        return array_intersect_key($a, array_flip(['key','name','description','kind','skill_type','class_key','slot','damage_type','prototype_damage','duration_seconds','cooldown_seconds','intrinsic_status']));
    }

    public static function effectDisplay(array $skill): ?array
    {
        if (($skill['status_key'] ?? null) === null) { return null; }
        $values = array_fill_keys(self::STATUS_KEYS, 0); $values[$skill['status_key']] = $skill['status_power'];
        return ['key'=>$skill['status_key'],'power'=>$skill['status_power'],'duration_ms'=>4000,'chance_percent'=>CharacterStats::statusEffectChance($values)];
    }

    private function assertAcyclic(string $key, array $path): void
    {
        if (isset($path[$key])) { throw new UnexpectedValueException('Cyclic skill prerequisite.'); }
        $path[$key] = true;
        foreach ($this->skills[$key]['prerequisites'] as $parent) { $this->assertAcyclic($parent, $path); }
    }
}
