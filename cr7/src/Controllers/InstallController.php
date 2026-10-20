<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Install\Installer;
use App\Support\Log;

/**
 * Web installer: GET /kurulum?token=<app.secret> shows checks + admin form, POST /kurulum installs.
 * Only reachable while no admin user exists (404 afterwards) and only with the correct secret.
 */
final class InstallController
{
    public function index(Request $request): Response
    {
        if (Installer::isInstalled()) {
            return Response::notFound();
        }
        if (!$this->secretConfigured()) {
            return $this->page(['state' => 'no-secret'], 403);
        }
        $token = $request->query('token');
        if (!is_string($token) || !$this->tokenValid($token)) {
            return Response::notFound();
        }
        return $this->page(['state' => 'form', 'token' => $token, 'checks' => $this->checks(), 'username' => 'admin']);
    }

    public function install(Request $request): Response
    {
        if (Installer::isInstalled() || !$this->secretConfigured()) {
            return Response::notFound();
        }
        $token = $request->input('token');
        if (!is_string($token) || !$this->tokenValid($token)) {
            return Response::notFound();
        }
        Csrf::check($request);

        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        $errors = [];
        if (preg_match(Installer::USERNAME_PATTERN, $username) !== 1) {
            $errors['username'] = 'Kullanıcı adı 3-50 karakter olmalı (harf, rakam, _ . -).';
        }
        if (strlen($password) < Installer::MIN_PASSWORD_LENGTH) {
            $errors['password'] = 'Şifre en az ' . Installer::MIN_PASSWORD_LENGTH . ' karakter olmalı.';
        } elseif (!hash_equals($password, (string) $request->input('password_confirm', ''))) {
            $errors['password_confirm'] = 'Şifreler eşleşmiyor.';
        }
        if ($errors !== []) {
            return $this->page(['state' => 'form', 'token' => $token, 'checks' => $this->checks(), 'username' => $username, 'errors' => $errors], 422);
        }

        try {
            $installer = new Installer();
            $log = $installer->run($username, $password);
        } catch (\Throwable $e) {
            Log::error('install', 'Web install failed', ['exception' => $e]);
            return $this->page(['state' => 'form', 'token' => $token, 'checks' => $this->checks(), 'username' => $username,
                'errors' => ['general' => 'Kurulum başarısız: ' . $e->getMessage()]], 500);
        }
        return $this->page(['state' => 'done', 'log' => $log, 'failed' => $installer->failed()]);
    }

    private function secretConfigured(): bool
    {
        $secret = (string) Config::get('app.secret', '');
        return strlen($secret) >= 16 && $secret !== 'CHANGE_ME';
    }

    private function tokenValid(string $token): bool
    {
        return hash_equals((string) Config::get('app.secret', ''), $token);
    }

    /** @return list<array{label: string, ok: bool, detail: string}> environment checks */
    private function checks(): array
    {
        $checks = [
            ['label' => 'PHP 8.1+', 'ok' => PHP_VERSION_ID >= 80100, 'detail' => PHP_VERSION],
        ];
        foreach (['pdo', 'mbstring', 'dom', 'json', 'curl', 'gd'] as $ext) {
            $checks[] = ['label' => "{$ext} eklentisi", 'ok' => extension_loaded($ext), 'detail' => extension_loaded($ext) ? 'yüklü' : 'eksik'];
        }
        $driver = Database::driver();
        $checks[] = ['label' => "pdo_{$driver} eklentisi", 'ok' => extension_loaded('pdo_' . $driver), 'detail' => $driver];
        foreach (['storage', 'storage/cache', 'storage/logs', 'storage/locks', 'public/og'] as $dir) {
            $writable = is_dir(App::basePath($dir)) && is_writable(App::basePath($dir));
            $checks[] = ['label' => "{$dir}/ yazılabilir", 'ok' => $writable, 'detail' => $writable ? 'evet' : 'hayır (chmod 775)'];
        }
        try {
            Database::fetchValue('SELECT 1');
            $checks[] = ['label' => 'Veritabanı bağlantısı', 'ok' => true, 'detail' => 'başarılı'];
        } catch (\Throwable) {
            $checks[] = ['label' => 'Veritabanı bağlantısı', 'ok' => false, 'detail' => 'config/config.php içindeki db ayarlarını kontrol edin'];
        }
        return $checks;
    }

    private function page(array $data, int $status = 200): Response
    {
        return Response::html(View::render('install/index', $data + ['title' => 'Kurulum', 'bare' => true], 'layouts/admin'), $status)
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
