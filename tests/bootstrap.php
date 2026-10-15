<?php

declare(strict_types=1);

// Innerhalb einer Shopware-Installation liefert deren Autoloader die Klassen des Kerns.
$shopwareAutoloader = dirname(__DIR__, 4) . '/vendor/autoload.php';
if (file_exists($shopwareAutoloader)) {
    require_once $shopwareAutoloader;
}

// Die Shopware-Installation kennt den Namensraum der Tests nicht; dieser Autoloader deckt src/ und tests/ ab.
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
 * Der Kern wird nur gebootet, wenn das Plugin innerhalb einer Shopware-Installation getestet wird.
 *
 * `class_exists(TestBootstrapper::class)` taugt dafür nicht: `shopware/core` ist eine
 * `require`-Abhängigkeit, die Klasse gibt es also auch im eigenständigen Checkout (CI, frischer
 * `composer install`). Der Bootstrap versuchte dann einen Shop zu booten, den es dort nicht gibt,
 * und bräche mit „Could not find plugin: RcMinimalisticProductList" ab, noch bevor ein Unit-Test läuft.
 *
 * Gesucht wird zuerst im Aufrufverzeichnis, weil Integrationstests aus der Shopware-Wurzel starten
 * (`vendor/bin/phpunit -c custom/plugins/…`), danach vier Ebenen über `tests/`; das trifft
 * `custom/plugins/<Plugin>/tests/`, solange der Pfad kein Symlink ist.
 */
$shopwareRoot = null;
foreach ([getcwd(), \dirname(__DIR__, 4)] as $candidate) {
    // `getcwd()` kann false liefern, daher die Prüfung auf einen Text. Einen leeren Text liefert es nie.
    if (\is_string($candidate) && is_file($candidate . '/config/bundles.php')) {
        $shopwareRoot = $candidate;
        break;
    }
}

// IntegrationTestBehaviour erwartet, dass `KernelLifecycleManager::prepare($classLoader)` gelaufen ist,
// bevor der erste Test startet. Im eigenständigen Unit-Lauf entfällt das; dort genügt der Autoloader.
if ($shopwareRoot !== null && class_exists(\Shopware\Core\TestBootstrapper::class)) {
    // Die Entwicklungsumgebung trägt in `.env.test` `KERNEL_CLASS=App\Kernel` ein. Diese Klasse gibt es
    // in einer Installation, die über `KernelFactory::create()` startet, nicht; `Shopware\Core\Kernel`
    // ist die Vorgabe des Kerns. Der Wert steht früh, weil Dotenv ohne Überschreiben lädt.
    $currentKernelClass = getenv('KERNEL_CLASS') ?: ($_SERVER['KERNEL_CLASS'] ?? '');
    if ($currentKernelClass === '' || !class_exists($currentKernelClass)) {
        putenv('KERNEL_CLASS=Shopware\\Core\\Kernel');
        $_SERVER['KERNEL_CLASS'] = 'Shopware\\Core\\Kernel';
        $_ENV['KERNEL_CLASS'] = 'Shopware\\Core\\Kernel';
    }

    // `addCallingPlugin()` registriert das Plugin im Test-Kernel. `setForceInstallPlugins(true)` fehlt
    // mit Absicht: Es deinstallierte und installierte bei jedem Lauf neu. Aktiviert wird das Plugin in
    // der Testdatenbank vor dem Lauf.
    $bootstrapper = (new \Shopware\Core\TestBootstrapper())
        ->setPlatformEmbedded(false)
        ->addCallingPlugin();

    // Das Projektverzeichnis zeigt ausdrücklich auf die gefundene Shopware-Wurzel.
    // `KernelFactory::getProjectDir()` liest `$_SERVER['PROJECT_ROOT']` vor dem Rückfall über
    // Reflection; der nähme den Pfad der KernelFactory-Klasse und zeigte bei einem per Composer
    // installierten vendor auf das Plugin statt auf die Instanz.
    $bootstrapper->setProjectDir($shopwareRoot);
    $_SERVER['PROJECT_ROOT'] = $shopwareRoot;
    $_ENV['PROJECT_ROOT'] = $shopwareRoot;
    putenv('PROJECT_ROOT=' . $shopwareRoot);

    $bootstrapper->bootstrap();
}
