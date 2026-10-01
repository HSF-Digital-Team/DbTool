<?php
/**
 * Unit test bootstrap: plain composer autoloading, no Magento boot.
 *
 * Works both for the standalone module repo (vendor/ at the module root, as in CI) and for a
 * module installed inside a Magento project (the first vendor/autoload.php found walking up).
 */
declare(strict_types=1);

$dir = __DIR__;
$autoload = null;
while ($dir !== dirname($dir)) {
    if (is_file($dir . '/vendor/autoload.php')) {
        $autoload = $dir . '/vendor/autoload.php';
        break;
    }
    $dir = dirname($dir);
}
if ($autoload === null) {
    throw new RuntimeException('EcomEU_DbTool unit tests: vendor/autoload.php not found; run composer install.');
}
require $autoload;

// The module's own namespace, when it is the composer root package (CI) and not installed as a
// dependency; harmless otherwise.
spl_autoload_register(static function (string $class): void {
    $prefix = 'EcomEU\\DbTool\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__, 2) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

// Magento generates TableDefinitionFactory at runtime; outside Magento a minimal stand-in does.
if (!class_exists(\EcomEU\DbTool\Model\Config\TableDefinitionFactory::class)) {
    require __DIR__ . '/Stub/TableDefinitionFactory.php';
}
