<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The administrator's user directory.
 *
 * Read-only over the customer list plus one role toggle. The role toggle is
 * deliberately narrow: an administrator may promote or demote another user,
 * but may not act on their own account, so a live administrator can never
 * lock the platform out of its own admin area.
 */
class UserManagementController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): Response
    {
        $query = User::query()->with('kycSubmission');

        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        $role = (string) $request->query('role', '');

        if ($role !== '' && $role !== 'ALL') {
            $query->where('role', $role);
        }

        $users = $query->latest()->paginate(15)->withQueryString();

        return Inertia::render('admin/users', [
            'users' => $users->through(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role->value,
                'roleLabel' => $u->role->label(),
                'kycStatus' => $u->kycSubmission?->status->value ?? 'NOT_SUBMITTED',
                'kycLabel' => $u->kycSubmission?->status->label() ?? 'Not submitted',
                'emailVerified' => $u->email_verified_at !== null,
                'createdAt' => $u->created_at?->format('d M Y, H:i') ?? '',
            ]),
            'filters' => [
                'search' => $search,
                'role' => $role === '' ? 'ALL' : $role,
            ],
            'roles' => UserRole::values(),
            'totals' => [
                'users' => User::query()->count(),
                'admins' => User::query()->where('role', UserRole::ADMIN->value)->count(),
            ],
        ]);
    }

    /**
     * Change a user's role.
     */
    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'role' => ['required', Rule::in(UserRole::values())],
        ], [
            'role.in' => 'The selected role is not valid.',
        ]);

        $newRole = UserRole::from($validated['role']);

        if ($user->id === $actor->id) {
            return back()->with('error', 'You cannot change your own role.');
        }

        $previous = $user->role->value;

        $user->role = $newRole;
        $user->save();

        $this->audit->record(
            action: 'USER_ROLE_CHANGED',
            actor: $actor,
            targetType: 'user',
            targetId: (string) $user->id,
            context: ['from' => $previous, 'to' => $newRole->value],
        );

        return back()->with('success', sprintf('%s is now a %s.', $user->name, $newRole->label()));
    }
}
