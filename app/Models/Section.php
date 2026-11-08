<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\FormatsTitleCaseForDisplay;

class Section extends Model
{
    use HasFactory, FormatsTitleCaseForDisplay;

    protected function titleCaseDisplayAttributes(): array
    {
        return ['name', 'grade_level'];
    }

    protected $fillable = ['name', 'grade_level', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function students()
    {
        return $this->hasMany(Student::class);
    }

    public static function leastPopulatedForGrade(string $gradeLevel): ?self
    {
        return static::query()
            ->where('grade_level', $gradeLevel)
            ->where('is_active', true)
            ->withCount(['students as active_students_count' => fn ($query) => $query->where('status', 'Active')])
            ->orderBy('active_students_count')
            ->orderBy('name')
            ->first();
    }
}