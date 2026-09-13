<?php
declare(strict_types=1);

require_once __DIR__ . '/BlockResolver.php';
require_once __DIR__ . '/ChampionDamageResolver.php';
require_once __DIR__ . '/CombatRandomSource.php';

final class PrototypeBlockResolver implements BlockResolver
{
    private int $chancePercent;
    private int $reductionPercent;

    public function __construct(
        private ChampionDamageResolver $championDamage,
        private CombatRandomSource $random,
        array $blockDefinition,
    ) {
        $serverOnly = $blockDefinition['server_only'] ?? null;
        if (!is_array($serverOnly)) {
            throw new InvalidArgumentException('Prototype Block definition is invalid.');
        }

        $this->chancePercent = self::percentage(
            $serverOnly,
            'prototype_chance_percent',
        );
        $this->reductionPercent = self::percentage(
            $serverOnly,
            'prototype_reduction_percent',
        );
    }

    public function resolve(
        array $incomingAction,
        array $currentDefense,
        bool $attempted,
    ): array {
        $damage = $this->championDamage->resolve($incomingAction, $currentDefense);
        $incomingDamage = self::nonNegativeInteger($damage, 'incoming_damage');
        $passiveAppliedDamage = self::nonNegativeInteger($damage, 'applied_damage');
        self::nonNegativeInteger($damage, 'prevented_damage');

        $blocked = $attempted &&
            $this->random->integer(1, 100) <= $this->chancePercent;
        if (!$blocked) {
            return [
                'blocked' => false,
                'incoming_damage' => $incomingDamage,
                'prevented_damage' => $incomingDamage - $passiveAppliedDamage,
                'applied_damage' => $passiveAppliedDamage,
            ];
        }

        $appliedDamage = max(
            $incomingDamage > 0 ? 1 : 0,
            (int) round(
                $passiveAppliedDamage * (1 - ($this->reductionPercent / 100)),
            ),
        );

        return [
            'blocked' => true,
            'incoming_damage' => $incomingDamage,
            'prevented_damage' => $incomingDamage - $appliedDamage,
            'applied_damage' => $appliedDamage,
        ];
    }

    private static function percentage(array $values, string $key): int
    {
        $value = $values[$key] ?? null;
        if (!is_int($value) || $value <= 0 || $value > 100) {
            throw new InvalidArgumentException('Prototype Block percentage is invalid.');
        }

        return $value;
    }

    private static function nonNegativeInteger(array $values, string $key): int
    {
        $value = $values[$key] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Prototype Block damage result is invalid.');
        }

        return $value;
    }
}
