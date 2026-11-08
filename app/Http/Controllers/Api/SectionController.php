<?php
namespace App\Http\Controllers\Api;

use App\Models\Section;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SectionController extends BaseApiController
{
    /** GET /api/admin/sections?grade=Grade+1 */
    public function index(Request $request): JsonResponse
    {
        $query = Section::query();

        if ($grade = $request->query('grade')) {
            $query->where('grade_level', $grade);
        }
        if ($request->boolean('active_only', false)) {
            $query->where('is_active', true);
        }

        return $this->success(
            $query->orderBy('grade_level')->orderBy('name')->get()
        );
    }

    /** POST /api/admin/sections */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'grade_level' => 'required|string|max:50',
            'is_active'   => 'boolean',
        ]);

        // Prevent duplicate section name within same grade
        $exists = Section::where('name', $data['name'])
                         ->where('grade_level', $data['grade_level'])
                         ->exists();
        if ($exists) {
            return $this->error("Section \"{$data['name']}\" already exists for {$data['grade_level']}.", 422);
        }

        $section = Section::create($data);
        return $this->success($section, 'Section created successfully.', 201);
    }

    /** PUT /api/admin/sections/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $section = Section::findOrFail($id);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'grade_level' => 'sometimes|string|max:50',
            'is_active'   => 'sometimes|boolean',
        ]);

        // Duplicate check (exclude self)
        if (isset($data['name']) || isset($data['grade_level'])) {
            $name  = $data['name']        ?? $section->name;
            $grade = $data['grade_level'] ?? $section->grade_level;

            $exists = Section::where('name', $name)
                             ->where('grade_level', $grade)
                             ->where('id', '!=', $id)
                             ->exists();
            if ($exists) {
                return $this->error("Section \"{$name}\" already exists for {$grade}.", 422);
            }
        }

        $section->update($data);
        return $this->success($section, 'Section updated.');
    }

    /** DELETE /api/admin/sections/{id} */
    public function destroy(int $id): JsonResponse
    {
        $section = Section::findOrFail($id);

        // Prevent deleting sections that still have students
        if ($section->students()->exists()) {
            return $this->error('Cannot delete: students are still assigned to this section.', 422);
        }

        $section->delete();
        return $this->success(null, 'Section deleted.');
    }
}