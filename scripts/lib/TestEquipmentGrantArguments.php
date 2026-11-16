<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

final class TestEquipmentGrantArguments
{
    public static function parse(array $arguments): array
    {
        $options = [];
        for ($i = 0; $i < count($arguments); $i++) {
            $parts = explode('=', $arguments[$i], 2);
            $name = $parts[0];
            if (!in_array($name, ['--user-id', '--character-id', '--apply', '--cleanup'], true)
                || array_key_exists($name, $options)) {
                throw new InvalidArgumentException('Unknown or duplicate argument.');
            }
            if (in_array($name, ['--apply', '--cleanup'], true)) {
                if (count($parts) !== 1) { throw new InvalidArgumentException('Flag takes no value.'); }
                $options[$name] = true;
                continue;
            }
            $value = $parts[1] ?? ($arguments[++$i] ?? '');
            if (!preg_match('/^[1-9][0-9]*$/D', $value)
                || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new InvalidArgumentException('Positive integer ID required.');
            }
            $options[$name] = (int) $value;
        }
        if (!isset($options['--user-id'], $options['--character-id'])) {
            throw new InvalidArgumentException('Both explicit IDs required.');
        }
        return ['user_id' => $options['--user-id'], 'character_id' => $options['--character-id'],
            'apply' => $options['--apply'] ?? false, 'cleanup' => $options['--cleanup'] ?? false];
    }
}
