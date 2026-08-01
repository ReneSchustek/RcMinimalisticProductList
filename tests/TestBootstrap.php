<?php

declare(strict_types=1);

// Plugin-eigener Composer-Autoloader (CI + lokal via composer install)
$pluginAutoloader = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($pluginAutoloader)) {
    require_once $pluginAutoloader;
}

// Im Shopware-Kontext zusaetzlich den Shopware-Autoloader einhaengen
$shopwareAutoloader = dirname(__DIR__, 4) . '/vendor/autoload.php';
if (file_exists($shopwareAutoloader)) {
    require_once $shopwareAutoloader;
}

// Plugin-PSR-4-Pfade falls kein Composer-Autoloader greift
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Ruhrcoder\\RcMinimalisticProductList\\Tests\\' => __DIR__ . '/',
        'Ruhrcoder\\RcMinimalisticProductList\\' => dirname(__DIR__) . '/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $length = strlen($prefix);
        if (strncmp($class, $prefix, $length) !== 0) {
            continue;
        }

        $relative = substr($class, $length);
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (file_exists($file)) {
            require_once $file;

            return;
        }
    }
});
