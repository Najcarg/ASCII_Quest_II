<?php
declare(strict_types=1);

require_once __DIR__ . '/../ascii-quest/lib/CharacterStats.php';

$allocatorPath = __DIR__ . '/../ascii-quest/lib/CharacterStatAllocator.php';
if (is_file($allocatorPath)) {
    require_once $allocatorPath;
}

function allocationCharacter(array $overrides = []): array
{
    return array_replace([
        'id' => 42,
        'user_id' => 7,
        'stat_points' => 3,
        'strength' => 10,
        'dexterity' => 5,
        'vitality' => 10,
        'energy' => 5,
        'fate' => 5,
        'current_hp' => 180,
        'current_mana' => 120,
    ], $overrides);
}

function assertAllocationRejected(
    string $expectedException,
    callable $operation,
    string $message,
): void {
    try {
        $operation();
    } catch (Throwable $e) {
        if ($e instanceof $expectedException) {
            return;
        }

        throw new RuntimeException(
            $message . ' Expected ' . $expectedException .
            ', got ' . $e::class . '.',
        );
    }

    throw new RuntimeException($message . ' Expected allocation to be rejected.');
}

return [
    'Strength allocation spends exactly one point' => function (): void {
        $before = allocationCharacter();

        $after = CharacterStatAllocator::allocate($before, 7, 'strength');

        assertSameValue(11, $after['strength'], 'Strength allocation.');
        assertSameValue(2, $after['stat_points'], 'Point spending.');
        assertSameValue(5, $after['dexterity'], 'Dexterity must not change.');
        assertSameValue(10, $before['strength'], 'Input row must not be mutated.');
    },

    'Vitality allocation raises Maximum Life without restoring HP' => function (): void {
        $before = allocationCharacter();
        $beforeStats = CharacterStats::calculate($before);

        $after = CharacterStatAllocator::allocate($before, 7, 'vitality');
        $afterStats = CharacterStats::calculate($after);

        assertSameValue(210, $afterStats['resources']['max_life'], 'Maximum Life.');
        assertSameValue(
            $beforeStats['resources']['max_life'] + 10,
            $afterStats['resources']['max_life'],
            'Vitality Life increase.',
        );
        assertSameValue(180, $after['current_hp'], 'Current HP must not change.');
    },

    'Energy allocation raises Maximum Mana without restoring Mana' => function (): void {
        $before = allocationCharacter();
        $beforeStats = CharacterStats::calculate($before);

        $after = CharacterStatAllocator::allocate($before, 7, 'energy');
        $afterStats = CharacterStats::calculate($after);

        assertSameValue(190, $afterStats['resources']['max_mana'], 'Maximum Mana.');
        assertSameValue(
            $beforeStats['resources']['max_mana'] + 15,
            $afterStats['resources']['max_mana'],
            'Energy Mana increase.',
        );
        assertSameValue(120, $after['current_mana'], 'Current Mana must not change.');
    },

    'Invalid stat name is rejected' => function (): void {
        assertAllocationRejected(
            InvalidArgumentException::class,
            fn (): array => CharacterStatAllocator::allocate(
                allocationCharacter(),
                7,
                'current_hp',
            ),
            'Arbitrary columns must be rejected.',
        );
    },

    'Allocation with zero points is rejected' => function (): void {
        assertAllocationRejected(
            DomainException::class,
            fn (): array => CharacterStatAllocator::allocate(
                allocationCharacter(['stat_points' => 0]),
                7,
                'strength',
            ),
            'Zero-point allocation.',
        );
    },

    'Another user Champion cannot be allocated' => function (): void {
        assertAllocationRejected(
            OutOfBoundsException::class,
            fn (): array => CharacterStatAllocator::allocate(
                allocationCharacter(['user_id' => 99]),
                7,
                'strength',
            ),
            'Ownership mismatch.',
        );
    },

    'Stat points cannot become negative' => function (): void {
        assertAllocationRejected(
            DomainException::class,
            fn (): array => CharacterStatAllocator::allocate(
                allocationCharacter(['stat_points' => -1]),
                7,
                'strength',
            ),
            'Negative point state.',
        );
    },
];
