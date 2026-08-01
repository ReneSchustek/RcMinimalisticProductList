<?php

declare(strict_types=1);

// Shopware-Autoloader laden (stellt Framework-Klassen bereit)
$shopwareAutoloader = dirname(__DIR__, 4) . '/vendor/autoload.php';
if (file_exists($shopwareAutoloader)) {
    require_once $shopwareAutoloader;
}

// Plugin-eigenen Autoloader registrieren (src/ und tests/)
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Ruhrcoder\\RcMinimalisticProductList\\Tests\\' => __DIR__ . '/',
        'Ruhrcoder\\RcMinimalisticProductList\\' => dirname(__DIR__) . '/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            continue;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

/*
 * Shopware-Root suchen. Der Kernel-Bootstrap darf NUR laufen, wenn das Plugin tatsächlich
 * innerhalb einer Shopware-Installation getestet wird.
 *
 * Nicht auf `class_exists(TestBootstrapper::class)` prüfen: `shopware/core` ist eine
 * `require`-Abhängigkeit, die Klasse existiert also auch im Standalone-Checkout (CI, frischer
 * `composer install`). Der Bootstrap versuchte dann einen Shop zu booten, den es dort nicht gibt,
 * und stürbe mit „Could not find plugin: RcMinimalisticProductList" — noch bevor ein Unit-Test läuft.
 *
 * Kandidaten in dieser Reihenfolge:
 *   1. Aufruf-Verzeichnis — Konvention: Integration-Tests werden aus dem Shopware-Root gestartet
 *      (`vendor/bin/phpunit -c custom/plugins/…`).
 *   2. Vier Ebenen über `tests/` — greift bei `custom/plugins/<Plugin>/tests/`, solange der Pfad
 *      kein Symlink ist.
 */
$shopwareRoot = null;
foreach ([getcwd(), \dirname(__DIR__, 4)] as $candidate) {
    if (\is_string($candidate) && $candidate !== '' && is_file($candidate . '/config/bundles.php')) {
        $shopwareRoot = $candidate;
        break;
    }
}

// Kernel-Lifecycle vorbereiten. IntegrationTestBehaviour erwartet, dass
// `KernelLifecycleManager::prepare($classLoader)` gelaufen ist, bevor der erste Test startet.
// Im Standalone-Unit-Lauf bleibt das ein No-op — die Unit-Tests brauchen nur den Autoloader.
if ($shopwareRoot !== null && class_exists(\Shopware\Core\TestBootstrapper::class)) {
    // KERNEL_CLASS-Pin: Das DDEV-Shopware-Setup hat in `.env.test` `KERNEL_CLASS=App\Kernel`
    // stehen — diese Klasse existiert in der Setup-Variante nicht (Production nutzt
    // `KernelFactory::create()` ohne App-Kernel). `Shopware\Core\Kernel` ist die konkrete
    // Default-Klasse. Früh setzen, damit Dotenv (override=false) sie nicht überschreibt.
    $currentKernelClass = getenv('KERNEL_CLASS') ?: ($_SERVER['KERNEL_CLASS'] ?? '');
    if ($currentKernelClass === '' || !class_exists($currentKernelClass)) {
        putenv('KERNEL_CLASS=Shopware\\Core\\Kernel');
        $_SERVER['KERNEL_CLASS'] = 'Shopware\\Core\\Kernel';
        $_ENV['KERNEL_CLASS'] = 'Shopware\\Core\\Kernel';
    }

    // `addCallingPlugin()` registriert RcMinimalisticProductList im Test-Kernel. Kein
    // `setForceInstallPlugins(true)` — das löste bei jedem Lauf einen uninstall->install-Zyklus
    // aus, der nicht idempotent ist. Die Aktivierung in der Test-Datenbank übernimmt das Gate.
    $bootstrapper = (new \Shopware\Core\TestBootstrapper())
        ->setPlatformEmbedded(false)
        ->addCallingPlugin();

    // ProjectDir explizit auf das gefundene Shopware-Root setzen. Wichtig:
    // `KernelFactory::getProjectDir()` liest `$_SERVER['PROJECT_ROOT']` *vor* dem
    // Reflection-Fallback — der nähme sonst den Pfad der KernelFactory-Klasse selbst und zeigte
    // bei composer-installiertem vendor auf das Plugin statt auf die Instanz.
    $bootstrapper->setProjectDir($shopwareRoot);
    $_SERVER['PROJECT_ROOT'] = $shopwareRoot;
    $_ENV['PROJECT_ROOT'] = $shopwareRoot;
    putenv('PROJECT_ROOT=' . $shopwareRoot);

    $bootstrapper->bootstrap();
}
