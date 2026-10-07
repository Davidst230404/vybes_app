<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    /**
     * List users with optional role filtering and name/email search.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::with('role');

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->input('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', $search)
                  ->orWhere('email', 'ilike', $search);
            });
        }

        if ($request->filled('role_id')) {
            $query->where('role_id', $request->input('role_id'));
        } elseif ($request->filled('role')) {
            $roleName = $request->input('role');
            $query->whereHas('role', function ($q) use ($roleName) {
                $q->where('name', $roleName);
            });
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $users = $query->latest('id')->paginate($perPage);

        return UserResource::collection($users);
    }

    /**
     * Show single user detail.
     */
    public function show(User $user): JsonResponse
    {
        $user->load([
            'role.permissions',
            'merchant',
            'organizer',
        ]);

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Update user details and role.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();
        $user->update($validated);

        $user->load([
            'role.permissions',
            'merchant',
            'organizer',
        ]);

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => new UserResource($user),
        ]);
    }
}
