<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $request->validate(['status' => 'required|in:Active,Inactive,Pending']);
        $user = $this->userService->updateStatus($id, $request->status);

        return response()->json($user);
    }

    public function updateRole(Request $request, string $id): JsonResponse
    {
        $request->validate(['role' => 'required|in:student,instructor,admin']);
        $user = $this->userService->updateRole($id, $request->role);

        return response()->json($user);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->userService->deleteUser($id);
        return response()->json(['message' => 'User deleted successfully']);
    }
}
