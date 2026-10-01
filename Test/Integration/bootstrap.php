<?php
/**
 * Boots the host Magento so the engine runs against its real database.
 *
 * The Magento root is taken from MAGENTO_ROOT or found by walking up from this file (the module is
 * expected to live under app/code or vendor of a Magento install).
 */
declare(strict_types=1);

use EcomEU\DbTool\Test\Integration\Magento;
use Magento\Framework\App\Bootstrap;

$root = getenv('MAGENTO_ROOT') ?: null;
if ($root === null) {
    $dir = __DIR__;
    while ($dir !== dirname($dir)) {
        if (is_file($dir . '/app/bootstrap.php')) {
            $root = $dir;
            break;
        }
        $dir = dirname($dir);
    }
}
if ($root === null) {
    throw new RuntimeException('EcomEU_DbTool tests: Magento root not found; set MAGENTO_ROOT.');
}

require $root . '/app/bootstrap.php';
Magento::init(Bootstrap::create($root, $_SERVER)->getObjectManager());
