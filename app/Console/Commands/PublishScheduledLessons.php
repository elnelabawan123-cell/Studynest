<?php

namespace App\Console\Commands;

use App\Services\ScheduledContentSyncService;
use Illuminate\Console\Command;

/**
 * Flips any "scheduled" lesson to "published" once its scheduled_at
 * date/time has arrived. Runs on a schedule (see routes/console.php)
 * so publishing needs zero teacher interaction.
 */
class PublishScheduledLessons extends Command
{
    protected $signature = 'lessons:publish-scheduled';

    protected $description = 'Publish scheduled lessons whose publish date/time has arrived';

    public function handle(ScheduledContentSyncService $scheduledSync): int
    {
        $published = $scheduledSync->publishDueLessons();

        if ($published === []) {
            $this->info('No lessons due for publishing.');
            return self::SUCCESS;
        }

        foreach ($published as $lesson) {
            $this->info("Published lesson #{$lesson->id}: \"{$lesson->title}\"");
        }

        $count = count($published);
        $this->info("Published {$count} lesson(s).");

        return self::SUCCESS;
    }
}