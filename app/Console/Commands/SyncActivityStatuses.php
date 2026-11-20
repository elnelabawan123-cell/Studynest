<?php

namespace App\Console\Commands;

use App\Services\ScheduledContentSyncService;
use Illuminate\Console\Command;

/**
 * Persists scheduled-to-open transitions. Due dates do not close activities:
 * they only mark submissions late when submitted after the deadline.
 *
 *   Scheduled ──(open_date/open_time arrives)──▶ Open
 *
 * Draft is never touched. Closing and reopening are teacher actions.
 */
class SyncActivityStatuses extends Command
{
    protected $signature = 'activities:sync-status';

    protected $description = 'Auto-open scheduled activities; teacher controls closing';

    public function handle(ScheduledContentSyncService $scheduledSync): int
    {
        $opened = $scheduledSync->openDueActivities();

        if ($opened === []) {
            $this->info('Nothing to update.');
        } else {
            foreach ($opened as $activity) {
                $this->info("Opened #{$activity->id}: \"{$activity->title}\"");
            }
            $count = count($opened);
            $this->info("Opened {$count}.");
        }

        return self::SUCCESS;
    }
}