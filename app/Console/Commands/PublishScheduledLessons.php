<?php

namespace App\Console\Commands;

use App\Models\Lesson;
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

    public function handle(): int
    {
        $due = Lesson::dueForPublish()->get();

        if ($due->isEmpty()) {
            $this->info('No lessons due for publishing.');
            return self::SUCCESS;
        }

        foreach ($due as $lesson) {
            $lesson->update([
                'status'       => 'published',
                'published_at' => now(),
            ]);

            $this->info("Published lesson #{$lesson->id}: \"{$lesson->title}\"");
        }

        $this->info("Published {$due->count()} lesson(s).");

        return self::SUCCESS;
    }
}