<?php
/**
 * CLI installer (idempotent).
 *
 *   php bin/install.php [--admin=admin] [--password=...] [--reset-password]
 *
 * Creates tables for the configured driver, the admin user (random password if
 * --password is omitted), runs seeders and generates missing brand images.
 * --reset-password sets --password on an already existing admin user.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Database;
use App\Install\Installer;

$options = getopt('h', ['admin:', 'password:', 'reset-password', 'help']);
if (isset($options['h']) || isset($options['help'])) {
    echo "Kullanım: php bin/install.php [--admin=admin] [--password=SIFRE] [--reset-password]\n";
    exit(0);
}

$admin = is_string($options['admin'] ?? null) ? $options['admin'] : 'admin';
$password = is_string($options['password'] ?? null) ? $options['password'] : null;
$reset = isset($options['reset-password']);

echo "CR7.com.tr kurulum (sürücü: " . Database::driver() . ")\n";
try {
    $installer = new Installer();
    foreach ($installer->run($admin, $password, $reset) as $line) {
        echo " - {$line}\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Kurulum başarısız: " . $e->getMessage() . "\n");
    exit(1);
}

if ($installer->failed()) {
    echo "Kurulum uyarılarla tamamlandı.\n";
    exit(1);
}
echo "Kurulum tamamlandı.\n";
