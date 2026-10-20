<?php

declare(strict_types=1);

namespace App\Cron\Tasks;

use App\Cron\Task;
use App\Services\News\NewsCollector;

/**
 * Fetches Cristiano Ronaldo news candidates from every enabled source (Bing/Google News search,
 * Turkish sports RSS/Atom feeds), filters, de-duplicates and clusters them into `news_items`.
 */
final class NewsFetchTask implements Task
{
    public function name(): string
    {
        return 'news-fetch';
    }

    public function intervalMinutes(): int
    {
        return 30;
    }

    public function run(): string
    {
        $stats = (new NewsCollector())->run();
        $totals = ['fetched' => 0, 'relevant' => 0, 'new' => 0, 'clustered' => 0];
        $errors = [];
        $parts = [];
        foreach ($stats as $key => $s) {
            foreach ($totals as $k => $v) {
                $totals[$k] = $v + (int) $s[$k];
            }
            if ($s['error'] !== null) {
                $errors[] = $key . ' (' . $s['error'] . ')';
            }
            $parts[] = sprintf('%s %d/%d/%d', $key, $s['fetched'], $s['relevant'], $s['new']);
        }
        if ($stats !== [] && count($errors) === count($stats)) {
            throw new \RuntimeException('Hiçbir haber kaynağına ulaşılamadı: ' . implode(', ', $errors));
        }
        return sprintf(
            '%d kaynak: %d öğe, %d ilgili, %d yeni (%d kümelendi)%s. Kaynak başına öğe/ilgili/yeni: %s',
            count($stats),
            $totals['fetched'],
            $totals['relevant'],
            $totals['new'],
            $totals['clustered'],
            $errors !== [] ? '; hata: ' . implode(', ', $errors) : '',
            implode(', ', $parts)
        );
    }
}
