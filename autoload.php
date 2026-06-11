<?php
/**
 * Self-contained autoloading. Prefers Composer's autoloader when present, else
 * registers a minimal PSR-4 loader for the Daktela\ namespace -> src/. This lets
 * the module install and the CLI run without `composer install` on the server
 * (CLAUDE.md self-contained constraint).
 */

declare(strict_types=1);

$vendor = __DIR__ . '/vendor/autoload.php';
if (is_file($vendor)) {
    require_once $vendor;
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Daktela\\';
    $len = strlen($prefix);
    if (strncmp($class, $prefix, $len) !== 0) {
        return;
    }
    $relative = substr($class, $len);
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
