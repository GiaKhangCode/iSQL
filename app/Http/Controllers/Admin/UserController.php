<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function index(): JsonResponse
    {
        $users = $this->userService->getAllUsers();
        return response()->json($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->createUser($request->validated());

        return response()->json($user, 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->userService->getUser($id));
    }

    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        $user = $this->userService->updateUser($id, $request->validated());

        return response()->json($user);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $request->validate(['status' => 'required|in:Active,Inactive,Pending']);
        $user = $this->userService->updateStatus($id, $request->status);

        return response()->json($user);
    }

    public function approve(string $id): JsonResponse
    {
        $user = $this->userService->getUser($id);
        if (! in_array($user->role, User::INSTRUCTOR_ROLES, true)) {
            throw ValidationException::withMessages(['user_id' => ['Only instructor accounts can be approved.']]);
        }
        if ($user->status !== 'Pending') {
            throw ValidationException::withMessages(['status' => ['Only pending accounts can be approved.']]);
        }

        $user = $this->userService->updateStatus($id, 'Active');
        $lecturerGroup = \App\Models\RoleGroup::where('name', 'like', '%Giảng viên%')->first();
        if ($lecturerGroup) {
            $user->roleGroups()->syncWithoutDetaching([$lecturerGroup->id]);
        }

        return response()->json($user);
    }

    public function resetStudentPassword(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:8']]);

        $this->userService->resetStudentPassword($id, $data['password']);

        return response()->json(['message' => 'Student password reset successfully']);
    }

    public function importCsv(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']]);
        $uploadedFile = $request->file('file');
        if (strtolower($uploadedFile->getClientOriginalExtension()) !== 'csv') {
            throw ValidationException::withMessages(['file' => ['The uploaded file must be a CSV file.']]);
        }

        $handle = fopen($uploadedFile->getRealPath(), 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['The CSV file could not be read.']]);
        }

        try {
            $headers = fgetcsv($handle, 0, ',', '"', '\\');
            if (! is_array($headers)) {
                throw ValidationException::withMessages(['file' => ['The CSV file must include a header row.']]);
            }
            $headers = array_map(static fn ($header) => trim((string) $header), $headers);
            $headers[0] = preg_replace('/^\\xEF\\xBB\\xBF/', '', $headers[0] ?? '');
            $requiredHeaders = ['email', 'password', 'name', 'role'];
            if (array_diff($requiredHeaders, $headers) !== []) {
                throw ValidationException::withMessages([
                    'file' => ['CSV header must include email,password,name,role. Optional columns: initials,status,department.'],
                ]);
            }

            $rows = [];
            $errors = [];
            $seenEmails = [];
            $line = 1;
            while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $line++;
                if ($values === [null] || $values === []) {
                    continue;
                }
                if ($line > 1001) {
                    $errors["row_{$line}"] = ['CSV import is limited to 1000 data rows.'];
                    break;
                }

                $row = [];
                foreach ($headers as $index => $header) {
                    if ($header !== '') {
                        $row[$header] = isset($values[$index]) ? trim((string) $values[$index]) : null;
                        if ($row[$header] === '') {
                            $row[$header] = null;
                        }
                    }
                }

                $validator = Validator::make($row, [
                    'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                    'password' => ['required', 'string', 'min:8'],
                    'name' => ['required', 'string', 'max:255'],
                    'initials' => ['nullable', 'string', 'max:10'],
                    'role' => ['required', Rule::in(User::ROLES)],
                    'status' => ['nullable', Rule::in(['Active', 'Inactive', 'Pending'])],
                    'department' => ['nullable', 'string', 'max:255'],
                ]);

                $normalizedEmail = strtolower((string) ($row['email'] ?? ''));
                if ($normalizedEmail !== '' && isset($seenEmails[$normalizedEmail])) {
                    $errors["row_{$line}"] = ['The email address is duplicated in this CSV file.'];
                    continue;
                }
                if ($normalizedEmail !== '') {
                    $seenEmails[$normalizedEmail] = true;
                }

                if ($validator->fails()) {
                    $errors["row_{$line}"] = $validator->errors()->all();
                    continue;
                }
                $rows[] = $validator->validated();
            }
        } finally {
            fclose($handle);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => ['The CSV file contains no user rows.']]);
        }

        $users = $this->userService->importCsvRows($rows);

        return response()->json([
            'message' => 'Users imported successfully',
            'imported_count' => count($users),
            'users' => array_map(static fn (User $user) => [
                'id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
            ], $users),
        ], 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->userService->deleteUser($id);
        return response()->json(['message' => 'User deleted successfully']);
    }
}
