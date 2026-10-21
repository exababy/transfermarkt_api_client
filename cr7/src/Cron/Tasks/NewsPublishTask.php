<?php

declare(strict_types=1);

namespace App\Cron\Tasks;

use App\Cron\Task;
use App\Services\News\NewsPublisher;

/**
 * Publishes at most one spun news post per run; the publisher itself enforces the daily limit
 * (news.daily_limit, Istanbul day), the minimum interval and the publish window.
 */
final class NewsPublishTask implements Task
{
    public function name(): string
    {
        return 'news-publish';
    }

    public function intervalMinutes(): int
    {
        return 10;
    }

    public function run(): string
    {
        $publisher = new NewsPublisher();
        $postId = $publisher->publishNext();
        return ($postId !== null ? "#{$postId} " : '') . $publisher->lastMessage();
    }
}
