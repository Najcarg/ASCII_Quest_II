<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$arguments = array_slice($argv, 1);
if (count($arguments) > 1 || ($arguments !== [] && $arguments !== ['--apply'])) {
    fwrite(STDERR, "Usage: php scripts/bootstrap_starter_equipment.php [--apply]\n");
    exit(2);
}
$apply = $arguments === ['--apply'];

require_once __DIR__ . '/../ascii-quest/db.php';
require_once __DIR__ . '/../ascii-quest/lib/EquipmentRepository.php';
require_once __DIR__ . '/../ascii-quest/lib/StarterEquipmentService.php';

$repository = new EquipmentRepository(getDb());
$service = new StarterEquipmentService($repository);
$counts = [
    'granted' => 0,
    'unchanged' => 0,
    'owned_weapon_available' => 0,
    'skipped_dead' => 0,
    'deferred_combat' => 0,
    'would_grant' => 0,
    'failed' => 0,
];

fwrite(STDOUT, $apply ? "Mode: APPLY\n" : "Mode: PREVIEW (no mutations)\n");
foreach ($repository->bootstrapCandidates() as $candidate) {
    $id = (int) $candidate['id'];
    try {
        $result = $service->bootstrapExisting((int) $candidate['user_id'], $id, $apply);
        $code = (string) $result['result'];
        $counts[$code] = ($counts[$code] ?? 0) + 1;
        $definition = isset($result['definition_key']) ? ' ' . $result['definition_key'] : '';
        fwrite(STDOUT, sprintf("Champion %d: %s%s\n", $id, $code, $definition));
    } catch (Throwable $exception) {
        $counts['failed']++;
        fwrite(STDERR, sprintf("Champion %d: failed (%s)\n", $id, $exception->getMessage()));
    }
}

fwrite(STDOUT, "Summary:\n");
foreach ($counts as $name => $count) {
    fwrite(STDOUT, sprintf("  %s: %d\n", $name, $count));
}
exit($counts['failed'] === 0 ? 0 : 1);
