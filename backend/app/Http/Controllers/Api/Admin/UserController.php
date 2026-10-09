<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    /**
     * List users with optional role filtering and name/email/phone search.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::with('role');

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->input('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', $search)
                  ->orWhere('email', 'ilike', $search)
                  ->orWhere('phone', 'ilike', $search);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
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
    public function update(UpdateUserRequest $request, User $user, AuditLogger $auditLogger): JsonResponse
    {
        $validated = $request->validated();
        $oldRoleId = $user->role_id;
        $oldStatus = $user->status ?? 'active';

        if (isset($validated['status']) && $validated['status'] === 'suspended' && $oldStatus !== 'suspended') {
            if ($request->user()->id === $user->id) {
                return response()->json([
                    'message' => 'You cannot suspend your own administrative account.',
                ], 422);
            }

            if ($user->hasRole('admin')) {
                $activeAdminCount = User::whereHas('role', function ($q) {
                    $q->where('name', 'admin');
                })->where('status', 'active')->where('id', '!=', $user->id)->count();

                if ($activeAdminCount === 0) {
                    return response()->json([
                        'message' => 'Cannot suspend the last active administrator.',
                    ], 422);
                }
            }

            $user->tokens()->delete();
        }

        $user->update($validated);

        $user->load([
            'role.permissions',
            'merchant',
            'organizer',
        ]);

        $auditLogger->log(
            actor: $request->user(),
            action: 'user.update',
            entityType: 'user',
            entityId: $user->id,
            metadata: [
                'updated_fields' => array_keys($validated),
                'old_role_id' => $oldRoleId,
                'new_role_id' => $user->role_id,
                'old_status' => $oldStatus,
                'new_status' => $user->status,
            ],
            before: ['status' => $oldStatus, 'role_id' => $oldRoleId],
            after: ['status' => $user->status, 'role_id' => $user->role_id]
        );

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Suspend user account.
     */
    public function suspend(Request $request, User $user, AuditLogger $auditLogger): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'You cannot suspend your own administrative account.',
            ], 422);
        }

        if ($user->hasRole('admin')) {
            $activeAdminCount = User::whereHas('role', function ($q) {
                $q->where('name', 'admin');
            })->where('status', 'active')->where('id', '!=', $user->id)->count();

            if ($activeAdminCount === 0) {
                return response()->json([
                    'message' => 'Cannot suspend the last active administrator.',
                ], 422);
            }
        }

        $oldStatus = $user->status ?? 'active';

        if ($oldStatus === 'suspended') {
            return response()->json([
                'message' => 'User account is already suspended.',
                'data' => new UserResource($user->load(['role.permissions', 'merchant', 'organizer'])),
            ]);
        }

        $user->update(['status' => 'suspended']);

        // Revoke all existing Sanctum personal access tokens immediately
        $user->tokens()->delete();

        $auditLogger->log(
            actor: $request->user(),
            action: 'user.suspend',
            entityType: 'user',
            entityId: $user->id,
            metadata: [
                'old_status' => $oldStatus,
                'new_status' => 'suspended',
                'reason' => $request->input('reason', 'Administrative suspension'),
            ],
            before: ['status' => $oldStatus],
            after: ['status' => 'suspended']
        );

        $user->load([
            'role.permissions',
            'merchant',
            'organizer',
        ]);

        return response()->json([
            'message' => 'User account suspended successfully.',
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Reactivate suspended user account.
     */
    public function reactivate(Request $request, User $user, AuditLogger $auditLogger): JsonResponse
    {
        $oldStatus = $user->status ?? 'active';

        if ($oldStatus === 'active') {
            return response()->json([
                'message' => 'User account is already active.',
                'data' => new UserResource($user->load(['role.permissions', 'merchant', 'organizer'])),
            ]);
        }

        $user->update(['status' => 'active']);

        $auditLogger->log(
            actor: $request->user(),
            action: 'user.reactivate',
            entityType: 'user',
            entityId: $user->id,
            metadata: [
                'old_status' => $oldStatus,
                'new_status' => 'active',
                'reason' => $request->input('reason', 'Administrative reactivation'),
            ],
            before: ['status' => $oldStatus],
            after: ['status' => 'active']
        );

        $user->load([
            'role.permissions',
            'merchant',
            'organizer',
        ]);

        return response()->json([
            'message' => 'User account reactivated successfully.',
            'data' => new UserResource($user),
        ]);
    }
}
