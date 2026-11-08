<?php

namespace App\Console\Commands;

use App\Models\Activity;
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

    public function handle(): int
    {
        $now = now();
        $openedCount = 0;

        // Scheduled → Open
        Activity::where('status', 'Scheduled')
            ->get()
            ->each(function (Activity $activity) use ($now, &$openedCount) {
                $openAt = \Carbon\Carbon::parse($activity->open_date->format('Y-m-d') . ' ' . $activity->open_time);
                if ($openAt->lte($now)) {
                    $activity->update(['status' => 'Open']);
                    $openedCount++;
                    $this->info("Opened #{$activity->id}: \"{$activity->title}\"");
                }
            });

        if ($openedCount === 0) {
            $this->info('Nothing to update.');
        } else {
            $this->info("Opened {$openedCount}.");
        }

        return self::SUCCESS;
    }

}