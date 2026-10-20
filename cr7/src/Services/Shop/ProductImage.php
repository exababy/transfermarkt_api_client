<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Core\App;

/**
 * Product photo uploads (admin): JPEG/PNG/WebP up to 2 MB, MIME type detected from the file content
 * (finfo + getimagesize, never the client-supplied type/name), stored under public/uploads/products/
 * with a random file name. Returns the public path '/uploads/products/<hex>.<ext>'.
 */
final class ProductImage
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const DIR = 'public/uploads/products';
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * Store an entry of $_FILES. $trusted = true skips is_uploaded_file() (tests / CLI imports only).
     *
     * @param array<string, mixed>|null $file
     * @return array{path: ?string, error: ?string} path null + error null => no file was sent
     */
    public static function store(?array $file, bool $trusted = false): array
    {
        if ($file === null || !isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['path' => null, 'error' => null];
        }
        $error = (int) $file['error'];
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return ['path' => null, 'error' => 'Görsel en fazla 2 MB olabilir.'];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['path' => null, 'error' => 'Görsel yüklenemedi (hata kodu ' . $error . ').'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || (!$trusted && !is_uploaded_file($tmp))) {
            return ['path' => null, 'error' => 'Görsel yüklenemedi.'];
        }
        $size = filesize($tmp);
        if ($size === false || $size <= 0 || $size > self::MAX_BYTES) {
            return ['path' => null, 'error' => 'Görsel en fazla 2 MB olabilir.'];
        }
        $mime = self::detectMime($tmp);
        if ($mime === null || !isset(self::TYPES[$mime])) {
            return ['path' => null, 'error' => 'Yalnızca JPG, PNG veya WebP görseller yüklenebilir.'];
        }
        $info = @getimagesize($tmp);
        if ($info === false || ($info['mime'] ?? '') !== $mime || $info[0] < 50 || $info[1] < 50 || $info[0] > 6000 || $info[1] > 6000) {
            return ['path' => null, 'error' => 'Görsel okunamadı veya boyutları uygun değil (50–6000 piksel).'];
        }
        $dir = App::basePath(self::DIR);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['path' => null, 'error' => 'Yükleme klasörü oluşturulamadı: ' . self::DIR];
        }
        $name = bin2hex(random_bytes(12)) . '.' . self::TYPES[$mime];
        $target = $dir . '/' . $name;
        $moved = $trusted ? @copy($tmp, $target) : @move_uploaded_file($tmp, $target);
        if (!$moved) {
            return ['path' => null, 'error' => 'Görsel kaydedilemedi; klasör izinlerini kontrol edin.'];
        }
        @chmod($target, 0644);
        return ['path' => '/uploads/products/' . $name, 'error' => null];
    }

    /** Delete a previously uploaded image (only files inside public/uploads/products/). */
    public static function delete(?string $path): void
    {
        if ($path === null || preg_match('#^/uploads/products/[a-f0-9]{24}\.(jpg|png|webp)$#', $path) !== 1) {
            return;
        }
        $file = App::basePath('public' . $path);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private static function detectMime(string $file): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $file);
                finfo_close($finfo);
                return is_string($mime) ? $mime : null;
            }
        }
        $info = @getimagesize($file);
        return $info !== false ? (string) $info['mime'] : null;
    }
}
