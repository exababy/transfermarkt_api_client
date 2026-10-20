<?php
/**
 * CR7.com.tr configuration template.
 *
 * Copy this file to config/config.php (gitignored) and fill in real values.
 * When config/config.php is missing the bootstrap falls back to this file, so
 * local development and tests work out of the box. Environment variables
 * CR7_DB_DRIVER, CR7_DB_PATH, CR7_DB_HOST, CR7_DB_PORT, CR7_DB_NAME, CR7_DB_USER,
 * CR7_DB_PASS, CR7_APP_URL and CR7_DEBUG override the matching keys.
 *
 * Many keys can also be overridden from the admin panel (stored in the
 * `settings` table); see App\Core\Settings.
 */

declare(strict_types=1);

return [
    'app' => [
        'name' => 'CR7.com.tr',
        'url' => 'https://cr7.com.tr',          // absolute base URL, no trailing slash
        'env' => 'production',
        'debug' => false,                        // never true in production
        'timezone' => 'Europe/Istanbul',         // display timezone (DB values are UTC)
        'secret' => 'CHANGE_ME',                 // long random string; also the /kurulum token
        'contact_email' => 'iletisim@cr7.com.tr',
        'trust_proxy' => false,                  // true behind Cloudflare/reverse proxy (CF-Connecting-IP, X-Forwarded-*)
        'session_idle_minutes' => 240,           // admin session idle timeout
    ],

    'db' => [
        'driver' => 'mysql',                     // mysql | sqlite
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'cr7',
        'username' => 'cr7',
        'password' => '',
        'charset' => 'utf8mb4',
        'sqlite_path' => __DIR__ . '/../storage/database.sqlite',
    ],

    'transfermarkt' => [
        'base_url' => 'https://tmapi-alpha.transfermarkt.technology/',
        'player_id' => 8198,
        'language' => 'tr-TR',
        'timeout' => 30,
    ],

    'news' => [
        'daily_limit' => 10,
        'min_interval_minutes' => 60,
        'publish_window' => [7, 23],             // Istanbul hours [from, to)
        'keywords' => ['ronaldo', 'cr7'],
        'max_age_hours' => 48,
        'similarity_threshold' => 0.55,
        'index_spun_posts' => true,
        'sources' => [],                         // news sources (defined by the news module)
    ],

    'spinner' => [
        'enabled' => true,
        'min_change_ratio' => 0.40,              // min share of word 3-shingles that must differ from every source
        'mix_sources' => true,
        'max_sources' => 3,
        'min_words' => 180,
    ],

    'shop' => [
        'enabled' => true,                       // shop pages visible and indexable
        'orders_enabled' => false,               // ordering closed by default ("Yakında satışta")
        'currency' => 'TRY',
        'whatsapp' => '',
        'notify_email' => '',
        'shipping_fee' => 0,
        // Order shown at checkout; a gateway appears only if enabled AND configured.
        'payment_methods' => ['paytr', 'iyzico', 'shopier', 'havale'],
    ],

    'payment' => [
        'paytr' => ['enabled' => false, 'merchant_id' => '', 'merchant_key' => '', 'merchant_salt' => '', 'test_mode' => true],
        'iyzico' => ['enabled' => false, 'api_key' => '', 'secret_key' => '', 'sandbox' => true],
        'shopier' => ['enabled' => false, 'api_key' => '', 'api_secret' => '', 'website_index' => 1],
        'havale' => ['enabled' => true, 'bank_name' => '', 'account_holder' => '', 'iban' => ''],
    ],

    'youtube' => [
        'api_key' => '',
        'channels' => [
            ['id' => 'UCtxD0x6AuNNqdXO9Wp5GHew', 'name' => 'UR · Cristiano', 'filter' => false],
        ],
        'search_query' => 'Cristiano Ronaldo',
    ],

    'indexnow' => ['key' => ''],

    'cron' => ['web_token' => ''],               // enables GET /cron/{token} when set (32+ random chars)

    'analytics' => ['ga4_id' => ''],             // e.g. G-XXXXXXXXXX; loaded only after cookie consent
];
