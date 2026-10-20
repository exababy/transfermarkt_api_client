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
 * Keys marked [panel] can also be changed in the admin panel (stored in the
 * `settings` table, which overrides this file); see App\Core\Settings.
 */

declare(strict_types=1);

return [
    'app' => [
        'name' => 'CR7.com.tr',
        'url' => 'https://cr7.com.tr',          // absolute base URL, no trailing slash (sub-folder installs: https://site.com/cr7)
        'env' => 'production',
        'debug' => false,                        // never true in production (true turns every PHP warning into an error page)
        'timezone' => 'Europe/Istanbul',         // display timezone (DB values are UTC)
        'secret' => 'CHANGE_ME',                 // long random string; also the /kurulum?token=… installer token
        'contact_email' => 'iletisim@cr7.com.tr',
        'trust_proxy' => false,                  // true behind Cloudflare/reverse proxy (CF-Connecting-IP, X-Forwarded-*)
        // Proxy headers are used only when REMOTE_ADDR is one of these IPs/CIDRs. [] = Cloudflare's ranges.
        // Own reverse proxy (e.g. nginx on the same server): ['127.0.0.1', '::1'] (+ Cloudflare ranges if both).
        'trusted_proxies' => [],
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
        'player_id' => 8198,                     // Cristiano Ronaldo
        'language' => 'tr-TR',
        'timeout' => 30,                         // seconds per API request
        'retries' => 1,                          // extra attempts on network errors / 5xx
    ],

    'news' => [
        'daily_limit' => 10,                     // [panel] max automatic posts per Istanbul day
        'min_interval_minutes' => 60,            // [panel] minimum gap between two automatic posts
        'publish_window' => [7, 23],             // [panel] Istanbul hours [from, to); equal values = all day
        'keywords' => ['ronaldo', 'cr7'],        // relevance filter for general sports feeds
        'max_age_hours' => 48,                   // [panel] older source items are neither collected nor published
        'similarity_threshold' => 0.55,          // title similarity that puts two items into the same story cluster
        'index_spun_posts' => true,              // [panel] false => automatic posts are noindex and stay out of the sitemaps
        'sources' => [],                         // extra/overriding sources (same shape as config/news-sources.php)
        'sources_disabled' => [],                // [panel] source keys that are not fetched (e.g. ['google'])
    ],

    'spinner' => [
        'enabled' => true,                       // false => no automatic publishing (admin "Şimdi yayınla" still works)
        'min_change_ratio' => 0.40,              // [panel] min share of word 3-shingles that must differ from every source
        'mix_sources' => true,                   // mix sentences from several sources of the same story
        'max_sources' => 3,
        'min_words' => 180,                      // shorter results are not published (note 'too-short')
        'max_shared_ngrams' => 0.10,             // scraped-content gate: share of word 6-grams shared with a source …
        'max_verbatim_words' => 12,              // … and longest verbatim run (quotes excluded); above => published noindex
    ],

    'shop' => [
        'enabled' => true,                       // [panel] shop pages visible and indexable
        'orders_enabled' => false,               // [panel] ordering closed by default ("Yakında satışta")
        'currency' => 'TRY',                     // informational: all gateways charge in Turkish lira
        'whatsapp' => '',                        // [panel] e.g. 905xxxxxxxxx ("Haber ver" / support links)
        'notify_email' => '',                    // [panel] new order / payment notifications (PHP mail())
        'shipping_fee' => 0,                     // [panel] flat shipping fee per order in TL (0 = free)
        // Order shown at checkout; a gateway appears only if enabled AND configured.
        'payment_methods' => ['paytr', 'iyzico', 'shopier', 'havale'],
        // [panel] Seller identity printed on the legal pages (Mesafeli Satış Sözleşmesi, Ön Bilgilendirme, İade).
        'company_name' => '',
        'company_address' => '',
        'company_phone' => '',
        'company_email' => '',                   // empty => app.contact_email
        'company_tax_office' => '',
        'company_tax_number' => '',
        'company_mersis' => '',
    ],

    // [panel] Admin › Ödeme Ayarları. Callback URLs to register in the provider panels:
    //   PayTR   Bildirim URL:  https://cr7.com.tr/odeme/paytr/bildirim
    //   iyzico  (sent automatically with each payment): https://cr7.com.tr/odeme/iyzico/donus
    //   Shopier Geri dönüş URL: https://cr7.com.tr/odeme/shopier/donus
    'payment' => [
        // test_mode: only while integrating (test cards); test notifications are not booked as paid in live mode.
        'paytr' => ['enabled' => false, 'merchant_id' => '', 'merchant_key' => '', 'merchant_salt' => '', 'test_mode' => false],
        'iyzico' => ['enabled' => false, 'api_key' => '', 'secret_key' => '', 'sandbox' => true],
        'shopier' => ['enabled' => false, 'api_key' => '', 'api_secret' => '', 'website_index' => 1],
        'havale' => ['enabled' => true, 'bank_name' => '', 'account_holder' => '', 'iban' => ''],
    ],

    'youtube' => [
        'api_key' => '',                         // [panel] optional YouTube Data API v3 key (durations, views, search)
        // Channels scanned by videos-sync (RSS, no API key needed). These are also the built-in
        // defaults; entries here override a default with the same id. 'filter' => true keeps only
        // videos that mention Ronaldo. Disable a default with ['id' => 'UC…', 'enabled' => false].
        'channels' => [
            ['id' => 'UCtxD0x6AuNNqdXO9Wp5GHew', 'name' => 'UR · Cristiano', 'filter' => false],
            ['id' => 'UCHEQtltsiDd3p8ga-5nC-ow', 'name' => 'Al Nassr FC', 'filter' => true],
            ['id' => 'UCsIoK3XP-cVcpoCC_SdjZdg', 'name' => 'Federação Portuguesa de Futebol', 'filter' => true],
            ['id' => 'UCWV3obpZVGgJ3j9FVhEjF2Q', 'name' => 'Real Madrid', 'filter' => true],
            ['id' => 'UC6yW44UGJJBvYTlfC7CRg2Q', 'name' => 'Manchester United', 'filter' => true],
            ['id' => 'UCLzKhsxrExAC6yAdtZ-BOWw', 'name' => 'Juventus', 'filter' => true],
            ['id' => 'UCnJj6L93JX3Jrhzv81ayywA', 'name' => 'Sporting CP', 'filter' => true],
            ['id' => 'UCyGa1YEx9ST66rYrJTGIKOw', 'name' => 'UEFA', 'filter' => true],
            ['id' => 'UCpcTrCXblq78GZrTUTLWeBw', 'name' => 'FIFA', 'filter' => true],
        ],
        'search_query' => 'Cristiano Ronaldo',   // used only with an API key
    ],

    'site' => [
        'hero_title' => '',                      // [panel] home page heading ('' = default)
        'hero_subtitle' => '',                   // [panel]
        'portrait_url' => '',                    // [panel] own portrait image (https://… or /assets/…); '' = Transfermarkt portrait
    ],

    'seo' => [                                   // [panel] webmaster "HTML tag" verification codes (home page meta tags)
        'google_verification' => '',             // <meta name="google-site-verification">
        'bing_verification' => '',               // <meta name="msvalidate.01">
        'yandex_verification' => '',             // <meta name="yandex-verification">
    ],

    'sitemap' => ['cache_ttl' => 3600],          // seconds; the index and Google News sitemap use at most 900; 0 = no cache

    'indexnow' => ['key' => ''],                 // [panel] 8–128 chars; served at /indexnow-key.txt

    'cron' => ['web_token' => ''],               // [panel] enables GET /cron/{token} when set (32+ random chars)

    'analytics' => ['ga4_id' => ''],             // [panel] e.g. G-XXXXXXXXXX; loaded only after cookie consent
];
