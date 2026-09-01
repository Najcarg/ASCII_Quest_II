<?php
declare(strict_types=1);

final class CombatDefinitionRegistry
{
    private float $turnDurationSeconds;
    private float $maxDisconnectedCatchupSeconds;

    /** @var array<string, array<string, mixed>> */
    private array $encounters;

    /** @var array<string, array<string, mixed>> */
    private array $enemies;

    /** @var array<string, array<string, mixed>> */
    private array $playerActions;

    /** @var array<string, array<string, mixed>> */
    private array $potions;

    public function __construct(array $config)
    {
        if (($config['foundation_only'] ?? null) !== true) {
            throw new RuntimeException('Combat configuration must be marked foundation-only.');
        }

        $this->turnDurationSeconds = self::positiveNumber(
            $config,
            'turn_duration_seconds',
        );
        $this->maxDisconnectedCatchupSeconds = self::positiveNumber(
            $config,
            'max_disconnected_catchup_seconds',
        );

        $prototypeBalance = self::requiredArray($config, 'prototype_balance');
        $this->playerActions = self::validatePlayerActions(
            self::requiredArray($prototypeBalance, 'player_actions'),
        );
        $this->potions = self::validatePotions(
            self::requiredArray($prototypeBalance, 'potions'),
        );
        $this->enemies = self::validateEnemies(
            self::requiredArray($prototypeBalance, 'enemies'),
        );
        $this->encounters = self::validateEncounters(
            self::requiredArray($config, 'prototype_encounters'),
            $this->enemies,
        );
    }

    public static function fromDefaultConfig(): self
    {
        return new self(require __DIR__ . '/../config/combat.php');
    }

    public function turnDurationSeconds(): float
    {
        return $this->turnDurationSeconds;
    }

    public function maxDisconnectedCatchupSeconds(): float
    {
        return $this->maxDisconnectedCatchupSeconds;
    }

    public function enemy(string $key): ?array
    {
        return $this->enemies[$key] ?? null;
    }

    public function playerAction(string $key): ?array
    {
        return $this->playerActions[$key] ?? null;
    }

    public function potion(string $key): ?array
    {
        return $this->potions[$key] ?? null;
    }

    public function encounter(string $id): ?array
    {
        return $this->encounters[$id] ?? null;
    }

    public function encounters(): array
    {
        return array_values($this->encounters);
    }

    private static function validatePlayerActions(array $actions): array
    {
        if ($actions === []) {
            throw new RuntimeException('At least one prototype player action is required.');
        }

        foreach ($actions as $key => $action) {
            self::assertDefinitionIdentity($key, $action, 'player action');
            self::assertNonEmptyString($action, 'name', 'Player action');
            self::assertNonEmptyString($action, 'kind', 'Player action');
            self::assertNonEmptyString($action, 'damage_type', 'Player action');
            self::positiveNumber($action, 'duration_seconds');
            self::positiveNumber($action, 'cooldown_seconds');
            self::positiveInteger($action, 'prototype_damage');

            if (array_key_exists('effect', $action)) {
                $effect = self::requiredArray($action, 'effect');
                self::assertCanonicalIdentifier(
                    $effect['key'] ?? null,
                    'Player action effect',
                );
                self::positiveNumber($effect, 'duration_seconds');
            }
        }

        return $actions;
    }

    private static function validatePotions(array $potions): array
    {
        if ($potions === []) {
            throw new RuntimeException('At least one prototype potion is required.');
        }

        foreach ($potions as $key => $potion) {
            self::assertDefinitionIdentity($key, $potion, 'potion');
            self::assertNonEmptyString($potion, 'name', 'Potion');
            self::positiveInteger($potion, 'charges');
            self::positiveInteger($potion, 'prototype_healing');
        }

        return $potions;
    }

    private static function validateEnemies(array $enemies): array
    {
        if ($enemies === []) {
            throw new RuntimeException('At least one prototype enemy is required.');
        }

        foreach ($enemies as $key => $enemy) {
            self::assertDefinitionIdentity($key, $enemy, 'enemy');
            self::assertNonEmptyString($enemy, 'name', 'Enemy');
            self::assertNonEmptyString($enemy, 'glyph', 'Enemy');
            self::positiveInteger($enemy, 'maximum_hp');
            self::positiveInteger($enemy, 'action');

            $actions = self::requiredArray($enemy, 'actions');
            if ($key === 'cave_brute') {
                if (
                    count($actions) !== 2 ||
                    !array_key_exists('smash', $actions) ||
                    !array_key_exists('fire_slam', $actions)
                ) {
                    throw new RuntimeException(
                        'Cave Brute requires exactly Smash and Fire Slam.',
                    );
                }
                if (($actions['smash']['kind'] ?? null) !== 'attack') {
                    throw new RuntimeException('Cave Brute Smash must be an attack.');
                }
                if (($actions['fire_slam']['kind'] ?? null) !== 'skill') {
                    throw new RuntimeException('Cave Brute Fire Slam must be a skill.');
                }
            }

            foreach ($actions as $actionKey => $action) {
                self::assertDefinitionIdentity($actionKey, $action, 'enemy action');
                self::assertNonEmptyString($action, 'name', 'Enemy action');
                self::assertNonEmptyString($action, 'kind', 'Enemy action');
                self::assertNonEmptyString($action, 'damage_type', 'Enemy action');
                self::positiveNumber($action, 'duration_seconds');
                if (array_key_exists('cooldown_seconds', $action)) {
                    throw new RuntimeException('Enemy cooldowns must remain server-only.');
                }

                $serverOnly = self::requiredArray($action, 'server_only');
                self::positiveNumber($serverOnly, 'cooldown_seconds');
                self::positiveInteger($serverOnly, 'prototype_damage');
            }

            $block = self::requiredArray($enemy, 'block');
            self::assertCanonicalIdentifier($block['key'] ?? null, 'Enemy Block');
            self::assertNonEmptyString($block, 'name', 'Enemy Block');
            $blockServerOnly = self::requiredArray($block, 'server_only');
            self::positiveInteger($blockServerOnly, 'prototype_chance_percent');
            self::positiveInteger($blockServerOnly, 'prototype_reduction_percent');

            $serverOnly = self::requiredArray($enemy, 'server_only');
            self::positiveInteger($serverOnly, 'prototype_gold_reward');
            self::positiveInteger($serverOnly, 'prototype_raw_exp_reward');
        }

        return $enemies;
    }

    private static function validateEncounters(array $encounters, array $enemies): array
    {
        if (count($encounters) !== 1) {
            throw new RuntimeException('Combat foundation requires exactly one prototype encounter.');
        }

        foreach ($encounters as $id => $encounter) {
            self::assertDefinitionIdentity($id, $encounter, 'encounter');
            self::assertCanonicalIdentifier($encounter['map_key'] ?? null, 'Encounter map');
            self::assertCanonicalIdentifier($encounter['enemy_key'] ?? null, 'Encounter enemy');
            if (!isset($enemies[$encounter['enemy_key']])) {
                throw new RuntimeException('Encounter enemy definition is unavailable.');
            }
            if (
                !is_string($encounter['map_file'] ?? null) ||
                basename($encounter['map_file']) !== $encounter['map_file'] ||
                !str_ends_with($encounter['map_file'], '.json')
            ) {
                throw new RuntimeException('Encounter map file is invalid.');
            }
            foreach (['x', 'y'] as $coordinate) {
                if (!is_int($encounter[$coordinate] ?? null) || $encounter[$coordinate] < 0) {
                    throw new RuntimeException('Encounter coordinates must be non-negative integers.');
                }
            }
            self::assertNonEmptyString($encounter, 'glyph', 'Encounter');
            if (($encounter['stationary'] ?? null) !== true) {
                throw new RuntimeException('Prototype encounter must be stationary.');
            }
            if (($encounter['fighting_range'] ?? null) !== 1) {
                throw new RuntimeException('Prototype encounter fighting range must be one.');
            }
            if (($encounter['range_shape'] ?? null) !== 'orthogonal') {
                throw new RuntimeException('Prototype encounter range must be orthogonal.');
            }
        }

        return $encounters;
    }

    private static function assertDefinitionIdentity(
        mixed $index,
        mixed $definition,
        string $label,
    ): void {
        self::assertCanonicalIdentifier($index, ucfirst($label));
        if (!is_array($definition) || ($definition['key'] ?? $definition['id'] ?? null) !== $index) {
            throw new RuntimeException(ucfirst($label) . ' key must match its index.');
        }
    }

    private static function assertCanonicalIdentifier(mixed $value, string $label): void
    {
        if (
            !is_string($value) ||
            preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $value) !== 1
        ) {
            throw new RuntimeException($label . ' identifier is invalid.');
        }
    }

    private static function assertNonEmptyString(
        array $definition,
        string $key,
        string $label,
    ): void {
        if (!is_string($definition[$key] ?? null) || trim($definition[$key]) === '') {
            throw new RuntimeException($label . ' ' . $key . ' is invalid.');
        }
    }

    private static function positiveNumber(array $definition, string $key): float
    {
        $value = $definition[$key] ?? null;
        if (!is_int($value) && !is_float($value)) {
            throw new RuntimeException("Combat {$key} must be numeric.");
        }

        $number = (float) $value;
        if (!is_finite($number) || $number <= 0) {
            throw new RuntimeException("Combat {$key} must be positive.");
        }

        return $number;
    }

    private static function positiveInteger(array $definition, string $key): int
    {
        $value = $definition[$key] ?? null;
        if (!is_int($value) || $value <= 0) {
            throw new RuntimeException("Combat {$key} must be a positive integer.");
        }

        return $value;
    }

    private static function requiredArray(array $definition, string $key): array
    {
        $value = $definition[$key] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException("Combat {$key} definition is invalid.");
        }

        return $value;
    }
}
