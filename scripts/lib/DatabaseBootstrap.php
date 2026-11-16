<?php
declare(strict_types=1);

// This is not a browser maintenance endpoint, even when requested directly.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/** Loads the existing trusted getDb()->PDO implementation; never creates credentials. */
final class DatabaseBootstrap
{
    private static bool $attempted = false;
    private static ?PDO $connection = null;

    public static function connect(): PDO
    {
        return self::connectFromSources(
            __DIR__ . '/../../ascii-quest/db.php',
            '/var/www/html/ascii-quest/db.php'
        );
    }

    /** Private seam for credential-free subprocess fixtures, not a source override API. */
    private static function connectFromSources(string $local, string $approvedProduction): PDO
    {
        if (self::$connection !== null) { return self::$connection; }
        // A failed require can still define global functions. Never retry a poisoned process.
        if (self::$attempted) { throw new RuntimeException('Maintenance database unavailable.'); }
        self::$attempted = true;

        $level = ob_get_level();
        $display = ini_get('display_errors');
        $logging = ini_get('log_errors');
        // Suppress accidental inline text/echo/var_dump and PHP diagnostics while loading
        // AND calling getDb. This is a trusted-code loader, not a PHP sandbox.
        ob_start(static fn (string $output): string => '', 1);
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
        set_error_handler(static function (int $severity): never {
            throw new RuntimeException('Maintenance database unavailable.');
        });
        try {
            // Presence, not validity, decides precedence. Broken/dangling local sources
            // must not quietly connect to production instead.
            $parent = dirname($local);
            if (is_dir($parent) && (!is_readable($parent) || !is_executable($parent))) {
                throw new RuntimeException('Maintenance database unavailable.');
            }
            $source = file_exists($local) || is_link($local) ? $local : $approvedProduction;
            if (!is_file($source) || !is_readable($source) || function_exists('getDb')) {
                throw new RuntimeException('Maintenance database unavailable.');
            }
            // Isolate included variables from this method's control state. Functions in
            // the bootstrap remain global, matching the application's existing contract.
            (static function (string $bootstrap): void { require $bootstrap; })($source);
            if (!function_exists('getDb')) { throw new RuntimeException('Maintenance database unavailable.'); }
            $function = new ReflectionFunction('getDb');
            if ($function->getNumberOfRequiredParameters() !== 0) {
                throw new RuntimeException('Maintenance database unavailable.');
            }
            $pdo = getDb();
            if (!$pdo instanceof PDO) { throw new RuntimeException('Maintenance database unavailable.'); }
            self::$connection = $pdo;
            return $pdo;
        } catch (Throwable $e) {
            // No original message, previous exception, source path, SQL or PDO details.
            throw new RuntimeException('Maintenance database unavailable.');
        } finally {
            while (ob_get_level() > $level) { ob_end_clean(); }
            restore_error_handler();
            ini_set('display_errors', $display);
            ini_set('log_errors', $logging);
        }
    }
}
