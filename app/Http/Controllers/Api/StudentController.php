<?php

namespace App\Http\Controllers\Api;

use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Student::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name',  'like', "%{$search}%")
                  ->orWhere('grade', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $students = $query->orderBy('name')->paginate(10);

        return $this->success($students);
    }

    public function show(int $id): JsonResponse
    {
        $student = Student::findOrFail($id);
        return $this->success($student);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|unique:students,email',
            'grade'   => 'required|string|max:50',
            'section' => 'required|string|max:100',
            'gpa'     => 'required|numeric|min:0|max:100',
            'status'  => 'required|in:Active,Inactive',
        ]);

        $student = Student::create($data);

        return $this->success($student, 'Student added successfully.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $student = Student::findOrFail($id);

        $data = $request->validate([
            'name'    => 'sometimes|string|max:255',
            'email'   => "sometimes|email|unique:students,email,{$id}",
            'grade'   => 'sometimes|string|max:50',
            'section' => 'sometimes|string|max:100',
            'gpa'     => 'sometimes|numeric|min:0|max:100',
            'status'  => 'sometimes|in:Active,Inactive',
        ]);

        $student->update($data);

        return $this->success($student, 'Student updated successfully.');
    }

    public function destroy(int $id): JsonResponse
    {
        $student = Student::findOrFail($id);
        $student->delete();

        return $this->success(null, 'Student removed successfully.');
    }
}