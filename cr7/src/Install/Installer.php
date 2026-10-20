<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\App;
use App\Core\Database;
use App\Support\Date;
use App\Support\Log;
use App\Support\Str;

/**
 * Idempotent installer (CLI: bin/install.php, web: /kurulum).
 * Creates storage dirs, runs the schema for the active driver, creates the admin user,
 * runs every seeder in src/Install/Seeders/ and generates missing brand images.
 */
final class Installer
{
    public const USERNAME_PATTERN = '/^[A-Za-z0-9_.-]{3,50}$/';
    public const MIN_PASSWORD_LENGTH = 8;

    /** @var list<string> */
    private array $log = [];
    private bool $failed = false;

    /**
     * @param string|null $password used when the admin user is created (random if null)
     * @param bool $resetPassword also set $password on an existing admin user
     * @return list<string> log lines (Turkish, shown to the operator)
     */
    public function run(string $username = 'admin', ?string $password = null, bool $resetPassword = false): array
    {
        $this->ensureDirectories();
        $this->migrate();
        $this->ensureAdmin($username, $password, $resetPassword);
        $this->runSeeders();
        $this->ensureAssets();
        return $this->log;
    }

    /** True if a seeder or asset step reported an error during run(). */
    public function failed(): bool
    {
        return $this->failed;
    }

    /** At least one admin user exists. */
    public static function isInstalled(): bool
    {
        try {
            return (int) Database::fetchValue('SELECT COUNT(*) FROM users') > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> SQL statements of database/schema.{driver}.sql */
    public static function schemaStatements(?string $driver = null): array
    {
        $driver ??= Database::driver();
        $file = App::basePath('database/schema.' . ($driver === 'sqlite' ? 'sqlite' : 'mysql') . '.sql');
        $sql = (string) file_get_contents($file);
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        return array_values(array_filter(array_map('trim', explode(';', $sql)), static fn (string $s): bool => $s !== ''));
    }

    /** Create all tables/indexes (safe to repeat). */
    public function migrate(): void
    {
        $statements = self::schemaStatements();
        $pdo = Database::pdo();
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        $tables = count(array_filter($statements, static fn (string $s): bool => stripos($s, 'CREATE TABLE') === 0));
        $this->log[] = sprintf('Veritabanı şeması hazır (%s, %d tablo).', Database::driver(), $tables);
    }

    private function ensureDirectories(): void
    {
        foreach (['storage', 'storage/cache', 'storage/logs', 'storage/locks', 'public/og'] as $dir) {
            $path = App::basePath($dir);
            if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
                $this->log[] = "UYARI: {$dir}/ klasörü oluşturulamadı.";
                continue;
            }
            if (!is_writable($path)) {
                $this->log[] = "UYARI: {$dir}/ klasörü yazılabilir değil (chmod 775).";
            }
        }
        $this->log[] = 'Depolama klasörleri kontrol edildi.';
    }

    private function ensureAdmin(string $username, ?string $password, bool $resetPassword): void
    {
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            throw new \InvalidArgumentException('Kullanıcı adı 3-50 karakter olmalı (harf, rakam, _ . -).');
        }
        if ($password !== null && strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException('Şifre en az ' . self::MIN_PASSWORD_LENGTH . ' karakter olmalı.');
        }
        $existing = Database::fetchOne('SELECT id FROM users WHERE username = ?', [$username]);
        if ($existing !== null) {
            if ($resetPassword && $password !== null) {
                Database::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [(int) $existing['id']]);
                $this->log[] = "Yönetici '{$username}' zaten mevcut; şifresi güncellendi.";
            } else {
                $this->log[] = "Yönetici '{$username}' zaten mevcut (şifre değiştirilmedi).";
            }
            return;
        }
        $generated = $password === null;
        $password ??= Str::random(16);
        Database::insert('users', [
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => Date::now(),
        ]);
        $this->log[] = "Yönetici '{$username}' oluşturuldu."
            . ($generated ? " Oluşturulan şifre: {$password} (giriş yaptıktan sonra değiştirin)." : '');
    }

    private function runSeeders(): void
    {
        $files = glob(App::basePath('src/Install/Seeders/*.php')) ?: [];
        sort($files);
        foreach ($files as $file) {
            $class = 'App\\Install\\Seeders\\' . basename($file, '.php');
            if (!class_exists($class) || !is_subclass_of($class, Seeder::class)) {
                continue;
            }
            $name = basename($file, '.php');
            try {
                $message = (new $class())->run();
                $this->log[] = "{$name}: {$message}";
            } catch (\Throwable $e) {
                $this->failed = true;
                $this->log[] = "HATA {$name}: " . $e->getMessage();
                Log::error('install', "Seeder {$name} failed", ['exception' => $e]);
            }
        }
        if ($files === []) {
            $this->log[] = 'Çalıştırılacak seeder bulunamadı.';
        }
    }

    private function ensureAssets(): void
    {
        try {
            $created = (new AssetGenerator(App::basePath('public'), App::basePath('public/assets/fonts/DejaVuSans-Bold.ttf')))->generateMissing();
            $this->log[] = $created === [] ? 'Görseller mevcut (og-default, ikonlar).' : 'Oluşturulan görseller: ' . implode(', ', $created) . '.';
        } catch (\Throwable $e) {
            $this->failed = true;
            $this->log[] = 'UYARI: Görseller oluşturulamadı: ' . $e->getMessage();
        }
    }
}
