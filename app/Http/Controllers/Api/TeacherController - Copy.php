<?php

namespace App\Http\Controllers\Api;

use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Teacher::with('subjects'); // ← eager load pivot subjects

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name',    'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%");
            });
        }

       $teachers = $query->orderBy('created_at', 'desc')
    ->paginate(10)
            ->through(fn ($t) => [
                'id'            => $t->id,
                'name'          => $t->name,
                'email'         => $t->email,
                'grade_level'   => $t->grade_level,
                'classes_count' => $t->classes_count,
                'status'        => $t->status,

                // ── Subject from pivot (subject_teacher) ──────────────────
                // If teacher has subjects assigned via SubjectPage, show those.
                // Fall back to the legacy `teachers.subject` text field if present.
                'subject' => $t->subjects->isNotEmpty()
                    ? $t->subjects->pluck('name')->implode(', ')
                    : ($t->subject ?: ''),

                // List of assigned subject objects for richer display
                'assigned_subjects' => $t->subjects->map(fn ($s) => [
                    'id'   => $s->id,
                    'code' => $s->code,
                    'name' => $s->name,
                    'grade_level' => $s->grade_level,
                ]),
            ]);

        return $this->success($teachers);
    }

    public function show(int $id): JsonResponse
    {
        return $this->success(Teacher::with('subjects')->findOrFail($id));
    }

public function store(Request $request): JsonResponse
{
    $data = $request->validate([
        'name'        => 'required|string|max:255',
        'email'       => 'required|email|unique:teachers,email|unique:users,email',
        'subject'     => 'required|string|max:100',
        'grade_level' => 'required|string|max:100',
        'status'      => 'required|in:Active,Inactive',
    ]);

    \DB::beginTransaction();
    try {
        $teacher = Teacher::create($data);

        // Auto-create matching user account so this teacher can log in
        // and appears in the Accounts tab
        $lastName = explode(' ', trim($data['name']));
        $username = strtolower(preg_replace('/[^a-zA-Z]/', '', end($lastName))) . '.SDA';
        $i = 1;
        while (\App\Models\User::where('username', $username)->exists()) {
            $username = strtolower(preg_replace('/[^a-zA-Z]/', '', end($lastName))) . '.SDA' . $i++;
        }

        \App\Models\User::create([
            'name'           => $data['name'],
            'email'          => $data['email'],
            'username'       => $username,
            'password'       => \Hash::make('password'),  // forced reset on first login
            'role'           => 'teacher',
            'is_first_login' => true,
        ]);

        \DB::commit();
        return $this->success($teacher, 'Teacher added successfully.', 201);

    } catch (\Throwable $e) {
        \DB::rollBack();
        return $this->error('Failed to add teacher: ' . $e->getMessage());
    }
}

    public function update(Request $request, int $id): JsonResponse
    {
        $teacher = Teacher::findOrFail($id);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'email'       => "sometimes|email|unique:teachers,email,{$id}",
            'subject'     => 'sometimes|string|max:100',
            'grade_level' => 'sometimes|string|max:100',
            'status'      => 'sometimes|in:Active,Inactive',
        ]);

        $teacher->update($data);
        return $this->success($teacher, 'Teacher updated successfully.');
    }

    public function destroy(int $id): JsonResponse
    {
        Teacher::findOrFail($id)->delete();
        return $this->success(null, 'Teacher removed successfully.');
    }
}