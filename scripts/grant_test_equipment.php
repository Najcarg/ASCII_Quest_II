<?php
declare(strict_types=1);

// Must precede even argument loading and, critically, database bootstrap.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/lib/TestEquipmentGrantArguments.php';
try {
    $options = TestEquipmentGrantArguments::parse(array_slice($argv, 1));
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, "Usage: php scripts/grant_test_equipment.php --user-id N --character-id N [--cleanup] [--apply]\n");
    exit(2);
}

try {
    require_once __DIR__ . '/lib/DatabaseBootstrap.php';
    $pdo = DatabaseBootstrap::connect();
    require_once __DIR__ . '/lib/TestEquipmentGrantRepository.php';
    require_once __DIR__ . '/lib/TestEquipmentGrantService.php';
    $service = new TestEquipmentGrantService(new TestEquipmentGrantRepository($pdo));
    $result = $service->execute($options['user_id'], $options['character_id'], $options['apply'], $options['cleanup']);
    fwrite(STDOUT, "TEST equipment maintenance (inventory only)\n" . json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    exit($result['result'] === 'failed' ? 1 : 0);
} catch (Throwable $e) {
    // Never expose SQL, connection details, paths or credentials. Safe domain codes only.
    $code = in_array($e->getMessage(), ['test_item_equipped', 'test_item_referenced', 'Task32 provenance identity mismatch.', 'Champion unavailable.'], true)
        ? $e->getMessage() : 'maintenance_failed';
    fwrite(STDERR, "Champion {$options['character_id']}: failed ($code)\n");
    exit(1);
}
