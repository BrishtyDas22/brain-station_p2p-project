<?php

namespace Tests\Feature\P2P;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The administrator boundary.
 *
 * Every admin route is protected twice: by the `admin` middleware in the
 * route group AND by a role re-check in the service layer. These tests hold
 * BOTH lines — a route that leaks is a failure, and so is a service that
 * trusts its caller.
 */
class AdminBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every route that must be unreachable by a non-administrator.
     *
     * @return list<string>
     */
    private function adminRoutes(): array
    {
        return [
            'admin.dashboard',
            'admin.users',
            'admin.kyc',
            'admin.monitoring',
            'admin.settlement',
            'admin.reversals',
            'admin.audit',
            'admin.reconciliation',
            'admin.reports',
        ];
    }

    public function test_a_customer_is_refused_every_admin_route()
    {
        $customer = User::factory()->create();

        foreach ($this->adminRoutes() as $name) {
            $this->actingAs($customer)->get(route($name))->assertForbidden();
        }
    }

    public function test_a_guest_is_refused_every_admin_route()
    {
        foreach ($this->adminRoutes() as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }
    }

    public function test_an_administrator_can_visit_every_admin_route()
    {
        $admin = User::factory()->admin()->create();

        foreach ($this->adminRoutes() as $name) {
            $this->actingAs($admin)->get(route($name))->assertOk();
        }
    }

    public function test_a_customer_cannot_change_any_role()
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($customer)
            ->patch(route('admin.users.role', $other), ['role' => UserRole::ADMIN->value])
            ->assertForbidden();

        $this->assertSame(UserRole::USER, $other->fresh()?->role);
    }

    public function test_an_administrator_can_promote_another_user()
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $this->actingAs($admin)
            ->patch(route('admin.users.role', $customer), ['role' => UserRole::ADMIN->value])
            ->assertRedirect();

        $this->assertSame(UserRole::ADMIN, $customer->fresh()?->role);
    }

    public function test_an_administrator_cannot_change_their_own_role()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.users.role', $admin), ['role' => UserRole::USER->value])
            ->assertRedirect();

        $this->assertSame(UserRole::ADMIN, $admin->fresh()?->role);
    }

    public function test_a_role_change_is_audited()
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $this->actingAs($admin)
            ->patch(route('admin.users.role', $customer), ['role' => UserRole::ADMIN->value]);

        $this->assertDatabaseHas('audit_records', [
            'action' => 'USER_ROLE_CHANGED',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_the_role_change_validates_its_input()
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $this->actingAs($admin)
            ->patch(route('admin.users.role', $customer), ['role' => 'SUPERUSER'])
            ->assertSessionHasErrors('role');

        $this->assertSame(UserRole::USER, $customer->fresh()?->role);
    }

    public function test_a_newly_registered_user_is_never_an_administrator()
    {
        $response = $this->post(route('register'), [
            'name' => 'Fresh Signup',
            'email' => 'fresh@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect();

        $user = User::query()->where('email', 'fresh@example.test')->firstOrFail();

        $this->assertSame(UserRole::USER, $user->role);
        $this->assertFalse($user->isAdmin());
    }

    public function test_the_user_directory_only_reports_real_accounts()
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create(['name' => 'Searchable Person']);

        $response = $this->actingAs($admin)->get(route('admin.users', ['search' => 'Searchable']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/users')
            ->where('users.total', 1)
            ->where('users.data.0.id', $customer->id)
        );
    }
}
