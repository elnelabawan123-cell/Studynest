<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolYear extends Model
{
    protected $fillable = [
        'label', 'start_date', 'end_date',
        'is_active', 'promoted', 'promoted_at',
    ];

    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'is_active'   => 'boolean',
        'promoted'    => 'boolean',
        'promoted_at' => 'datetime',
    ];

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function promotionHistories(): HasMany
    {
        return $this->hasMany(StudentPromotionHistory::class);
    }

    /** Returns the current active school year. */
    public static function active(): ?self
    {
        return static::where('is_active', true)->latest()->first();
    }

    /** True if today has reached or passed the end date. */
    public function isEndDateReached(): bool
    {
        return now()->startOfDay()->gte($this->end_date->startOfDay());
    }

    /**
     * Returns the label for the next school year.
     * e.g. current SY ends in 2027 → "SY. 2027-2028"
     */
    public function nextYearLabel(): string
    {
        $startYear = $this->end_date->year;
        return 'SY. ' . $startYear . '-' . ($startYear + 1);
    }

    /**
     * Builds the attribute array for the next school year.
     * start_date = June 1 of the year the current SY ends.
     * end_date   = March 31 the following year (admin-editable default).
     * Does NOT persist — caller is responsible for SchoolYear::create().
     */
    public function buildNextSchoolYear(): array
    {
        $nextStartYear = $this->end_date->year;

        return [
            'label'       => $this->nextYearLabel(),
            'start_date'  => \Illuminate\Support\Carbon::create($nextStartYear, 6, 1)->toDateString(),
            'end_date'    => \Illuminate\Support\Carbon::create($nextStartYear + 1, 3, 31)->toDateString(),
            'is_active'   => true,
            'promoted'    => false,
            'promoted_at' => null,
        ];
    }
}