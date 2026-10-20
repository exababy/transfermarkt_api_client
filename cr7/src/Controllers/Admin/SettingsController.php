<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\News\SourceRegistry;
use App\Support\Log;

/**
 * Site settings (every non-payment key of spec §4), stored with Settings::set().
 * Secrets (YouTube API key, web cron token) are never printed in full: an empty submit keeps
 * the stored value, a checkbox clears it. "Anahtar üret" buttons post a separate small form.
 */
final class SettingsController
{
    private const REVEAL_KEY = 'admin.cron_token_reveal';

    /**
     * Field definitions: setting key => [input name, type, options].
     * Types: int, float, bool, text, url, ga4, indexnow, secret.
     */
    public const FIELDS = [
        'news.daily_limit' => ['input' => 'news_daily_limit', 'type' => 'int', 'min' => 1, 'max' => 50, 'label' => 'Günlük yayın limiti'],
        'news.min_interval_minutes' => ['input' => 'news_min_interval_minutes', 'type' => 'int', 'min' => 0, 'max' => 1440, 'label' => 'İki yayın arası en az süre'],
        'news.index_spun_posts' => ['input' => 'news_index_spun_posts', 'type' => 'bool', 'label' => 'Otomatik haberler arama motorlarında indekslensin'],
        'spinner.min_change_ratio' => ['input' => 'spinner_min_change_ratio', 'type' => 'float', 'min' => 0.2, 'max' => 0.9, 'label' => 'Asgari özgünlük oranı'],
        'site.hero_title' => ['input' => 'site_hero_title', 'type' => 'text', 'max' => 120, 'label' => 'Ana sayfa başlığı'],
        'site.hero_subtitle' => ['input' => 'site_hero_subtitle', 'type' => 'text', 'max' => 300, 'label' => 'Ana sayfa alt başlığı'],
        'site.portrait_url' => ['input' => 'site_portrait_url', 'type' => 'url', 'max' => 500, 'label' => 'Portre görseli adresi'],
        'analytics.ga4_id' => ['input' => 'analytics_ga4_id', 'type' => 'ga4', 'label' => 'Google Analytics 4 ölçüm kimliği'],
        'indexnow.key' => ['input' => 'indexnow_key', 'type' => 'indexnow', 'label' => 'IndexNow anahtarı'],
        'youtube.api_key' => ['input' => 'youtube_api_key', 'type' => 'secret', 'pattern' => '/^[A-Za-z0-9_-]{20,100}$/', 'label' => 'YouTube Data API anahtarı'],
        'cron.web_token' => ['input' => 'cron_web_token', 'type' => 'secret', 'pattern' => '/^[A-Za-z0-9_-]{32,128}$/', 'label' => 'Web cron anahtarı'],
    ];

    public function index(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return $this->page([], []);
    }

    public function update(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        Csrf::check($request);

        $action = AdminPage::input($request, '_action');
        if ($action === 'generate_indexnow') {
            Settings::set('indexnow.key', bin2hex(random_bytes(16)));
            Log::info('admin', 'IndexNow key generated', ['user' => Auth::user()['id'] ?? null]);
            Session::flash('success', 'Yeni IndexNow anahtarı oluşturuldu. Anahtar dosyası: ' . url('/indexnow-key.txt'));
            return Response::redirect(url('/admin/ayarlar#arama-motorlari'));
        }
        if ($action === 'generate_cron') {
            $token = bin2hex(random_bytes(20));
            Settings::set('cron.web_token', $token);
            Session::set(self::REVEAL_KEY, $token);
            Log::info('admin', 'web cron token generated', ['user' => Auth::user()['id'] ?? null]);
            Session::flash('success', 'Yeni web cron anahtarı oluşturuldu. Adresi şimdi kopyalayın; güvenlik nedeniyle daha sonra tamamı gösterilmez.');
            return Response::redirect(url('/admin/ayarlar#cron'));
        }

        [$values, $errors] = self::validate($request);
        if ($errors !== []) {
            return $this->page($errors, $request->all(), 422);
        }
        $changed = self::save($values);
        Log::info('admin', 'settings saved', ['keys' => $changed, 'user' => Auth::user()['id'] ?? null]);
        Session::flash('success', $changed === [] ? 'Değişiklik yapılmadı.' : 'Ayarlar kaydedildi (' . count($changed) . ' değişiklik).');
        return Response::redirect(url('/admin/ayarlar'));
    }

    /**
     * Validate the posted form.
     *
     * @return array{0: array<string, array{action: string, value?: mixed}>, 1: array<string, string>}
     *         [setting key => ['action' => 'set'|'forget'|'keep', 'value' => …], input name => error]
     */
    public static function validate(Request $request): array
    {
        $values = [];
        $errors = [];
        foreach (self::FIELDS as $key => $field) {
            $name = $field['input'];
            $raw = AdminPage::input($request, $name);
            switch ($field['type']) {
                case 'int':
                    if (preg_match('/^\d{1,6}$/', $raw) !== 1 || (int) $raw < $field['min'] || (int) $raw > $field['max']) {
                        $errors[$name] = sprintf('%s %d ile %d arasında bir tam sayı olmalıdır.', $field['label'], $field['min'], $field['max']);
                        break;
                    }
                    $values[$key] = ['action' => 'set', 'value' => (int) $raw];
                    break;
                case 'float':
                    $number = str_replace(',', '.', $raw);
                    if (!is_numeric($number) || (float) $number < $field['min'] || (float) $number > $field['max']) {
                        $errors[$name] = sprintf('%s %s ile %s arasında olmalıdır (ör. 0,40).', $field['label'], str_replace('.', ',', (string) $field['min']), str_replace('.', ',', (string) $field['max']));
                        break;
                    }
                    $values[$key] = ['action' => 'set', 'value' => round((float) $number, 2)];
                    break;
                case 'bool':
                    $values[$key] = ['action' => 'set', 'value' => AdminPage::checked($request, $name)];
                    break;
                case 'text':
                    $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($raw)));
                    if (mb_strlen($text) > $field['max']) {
                        $errors[$name] = sprintf('%s en fazla %d karakter olabilir.', $field['label'], $field['max']);
                        break;
                    }
                    $values[$key] = $text === '' ? ['action' => 'forget'] : ['action' => 'set', 'value' => $text];
                    break;
                case 'url':
                    if ($raw !== '' && (strlen($raw) > $field['max'] || preg_match('#^(https?://[^\s/$.?\#][^\s]*|/[^\s/][^\s]*)$#i', $raw) !== 1)) {
                        $errors[$name] = $field['label'] . ' https:// ile başlayan bir adres ya da /assets/… gibi site içi bir yol olmalıdır.';
                        break;
                    }
                    $values[$key] = $raw === '' ? ['action' => 'forget'] : ['action' => 'set', 'value' => $raw];
                    break;
                case 'ga4':
                    $id = strtoupper(str_replace(' ', '', $raw));
                    if ($id !== '' && preg_match('/^G-[A-Z0-9]{4,20}$/', $id) !== 1) {
                        $errors[$name] = 'Ölçüm kimliği “G-” ile başlamalı ve yalnızca harf/rakam içermelidir (ör. G-AB12CD34EF).';
                        break;
                    }
                    $values[$key] = ['action' => 'set', 'value' => $id];
                    break;
                case 'indexnow':
                    if ($raw !== '' && preg_match('/^[A-Za-z0-9-]{8,128}$/', $raw) !== 1) {
                        $errors[$name] = 'IndexNow anahtarı 8–128 karakter olmalı ve yalnızca harf, rakam ve tire içermelidir.';
                        break;
                    }
                    $values[$key] = ['action' => 'set', 'value' => $raw];
                    break;
                case 'secret':
                    if (AdminPage::checked($request, $name . '_clear')) {
                        $values[$key] = ['action' => 'set', 'value' => ''];
                    } elseif ($raw === '') {
                        $values[$key] = ['action' => 'keep'];
                    } elseif (preg_match($field['pattern'], $raw) !== 1) {
                        $errors[$name] = $key === 'cron.web_token'
                            ? 'Web cron anahtarı en az 32 karakter olmalı ve yalnızca harf, rakam, “-” ve “_” içermelidir. “Anahtar üret” düğmesini kullanabilirsiniz.'
                            : 'API anahtarı geçersiz görünüyor (yalnızca harf, rakam, “-” ve “_”; 20–100 karakter).';
                    } else {
                        $values[$key] = ['action' => 'set', 'value' => $raw];
                    }
                    break;
            }
        }

        // News sources: the form lists every defined source; unchecked ones are disabled.
        if (AdminPage::checked($request, 'sources_present')) {
            $defined = array_keys(self::sources());
            $enabled = $request->input('sources_enabled', []);
            $enabled = is_array($enabled) ? array_values(array_filter($enabled, 'is_string')) : [];
            $disabled = array_values(array_diff($defined, $enabled));
            if ($defined !== [] && $disabled === $defined) {
                $errors['sources_enabled'] = 'En az bir haber kaynağı etkin olmalıdır.';
            } else {
                // Keep disabled keys of sources that are no longer defined (they may come back).
                $previous = self::disabledSources();
                $orphans = array_values(array_diff($previous, $defined));
                $values['news.sources_disabled'] = ['action' => 'set', 'value' => array_values(array_unique([...$disabled, ...$orphans]))];
            }
        }
        return [$values, $errors];
    }

    /**
     * Persist validated values; returns the changed keys (secrets are never logged with values).
     *
     * @param array<string, array{action: string, value?: mixed}> $values
     * @return list<string>
     */
    public static function save(array $values): array
    {
        $stored = Settings::all();
        $changed = [];
        foreach ($values as $key => $entry) {
            if ($entry['action'] === 'keep') {
                continue;
            }
            if ($entry['action'] === 'forget') {
                if (array_key_exists($key, $stored)) {
                    Settings::forget($key);
                    $changed[] = $key;
                }
                continue;
            }
            $value = $entry['value'];
            if (!array_key_exists($key, $stored) && $value === Config::get($key)) {
                continue; // same as the configuration default: no override needed
            }
            if (array_key_exists($key, $stored) && $stored[$key] === $value) {
                continue;
            }
            Settings::set($key, $value);
            $changed[] = $key;
        }
        return $changed;
    }

    /** @return array<string, array{name: string, type: string, url: string}> every defined news source */
    public static function sources(): array
    {
        if (!class_exists(SourceRegistry::class)) {
            return [];
        }
        try {
            if (method_exists(SourceRegistry::class, 'defined')) {
                return SourceRegistry::defined();
            }
            // Contract-only fallback: enabled sources + the disabled keys we know about.
            $sources = SourceRegistry::all();
            foreach (self::disabledSources() as $key) {
                $sources[$key] ??= ['name' => $key, 'type' => '', 'url' => ''];
            }
            return $sources;
        } catch (\Throwable $e) {
            Log::error('admin', 'source registry failed: ' . $e->getMessage());
            return [];
        }
    }

    /** @return list<string> */
    public static function disabledSources(): array
    {
        $value = Settings::get('news.sources_disabled', []);
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }

    /**
     * @param array<string, string> $errors input name => message
     * @param array<string, mixed> $old posted values (re-rendered after validation errors)
     */
    private function page(array $errors, array $old, int $status = 200): Response
    {
        $current = [];
        foreach (self::FIELDS as $key => $field) {
            $current[$field['input']] = Settings::get($key, Config::get($key));
        }
        $token = (string) ($current['cron_web_token'] ?? '');
        $reveal = Session::pull(self::REVEAL_KEY);
        $revealed = is_string($reveal) && $reveal !== '' && hash_equals($token, $reveal);
        $window = Settings::get('news.publish_window', Config::get('news.publish_window', [7, 23]));

        return AdminPage::render('settings', 'Ayarlar', [
            'fields' => self::FIELDS,
            'current' => $current,
            'old' => $old,
            'errors' => $errors,
            'sources' => self::sources(),
            'disabledSources' => self::disabledSources(),
            'registryAvailable' => class_exists(SourceRegistry::class),
            'defaults' => [
                'news_daily_limit' => Config::get('news.daily_limit', 10),
                'news_min_interval_minutes' => Config::get('news.min_interval_minutes', 60),
                'spinner_min_change_ratio' => Config::get('spinner.min_change_ratio', 0.4),
            ],
            'publishWindow' => is_array($window) && count($window) === 2 ? sprintf('%02d:00–%02d:00', (int) $window[0], (int) $window[1]) : '—',
            'maxAgeHours' => (int) Settings::get('news.max_age_hours', Config::get('news.max_age_hours', 48)),
            'youtubeMasked' => AdminPage::mask((string) ($current['youtube_api_key'] ?? '')),
            'cronToken' => $token,
            'cronRevealed' => $revealed,
            'cronUrl' => $token === '' ? '' : url('/cron/') . ($revealed ? $token : AdminPage::mask($token)),
            'cronConfigToken' => (string) Config::get('cron.web_token', ''),
            'crontabLine' => '*/10 * * * * /usr/bin/php ' . App::basePath('bin/cron.php') . ' >/dev/null 2>&1',
            'indexNowUrl' => url('/indexnow-key.txt'),
        ], $status, [['name' => 'Ayarlar']]);
    }
}
