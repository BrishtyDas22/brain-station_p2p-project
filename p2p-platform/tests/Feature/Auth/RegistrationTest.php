<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * The local development / demo registration flow.
 *
 * The flow under test is:
 *
 *   register -> the user is saved in MySQL -> the browser is sent to the
 *   login page with a success message -> the user logs in -> the dashboard.
 *
 * Registration never leaves an authenticated session behind, and the
 * administrator role is never granted by a registration.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_a_new_user_is_saved_in_the_database()
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::query()->where('email', 'test@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('Test User', $user->name);
    }

    public function test_the_password_is_hashed_and_never_stored_in_plain_text()
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::query()->where('email', 'test@example.com')->firstOrFail();

        $this->assertNotSame('password', $user->password);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_registration_redirects_to_the_login_page()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_registration_does_not_sign_the_new_user_in()
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_the_registration_session_is_invalidated()
    {
        // The session id before the registration must not survive it.
        $this->get(route('register'));

        $before = session()->getId();

        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_the_success_message_is_flashed_for_the_login_page()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHas('status', 'Account created successfully. Please log in.');
    }

    public function test_the_success_message_is_shown_on_the_login_page()
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('status', 'Account created successfully. Please log in.')
        );
    }

    public function test_a_newly_registered_user_can_log_in_and_reach_the_dashboard()
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        // The user is on the login page at this point.
        $this->assertGuest();

        $login = $this->post(route('login.store'), [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $login->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $dashboard = $this->get(route('dashboard'));

        $dashboard->assertOk();
        $dashboard->assertInertia(fn ($page) => $page
            ->where('auth.user.email', 'test@example.com')
        );
    }

    public function test_a_newly_registered_user_is_never_an_administrator()
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::query()->where('email', 'test@example.com')->firstOrFail();

        $this->assertSame('USER', $user->role->value);
        $this->assertFalse($user->isAdmin());
    }

    public function test_a_newly_registered_user_cannot_reach_the_admin_area()
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->post(route('login.store'), [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_registration_validation_rejects_a_duplicate_email()
    {
        User::factory()->create(['email' => 'test@example.com']);

        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
        $this->assertSame(1, User::query()->where('email', 'test@example.com')->count());
    }

    public function test_registration_validation_rejects_a_mismatched_password_confirmation()
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'different-password',
        ]);

        $this->assertGuest();
        $this->assertNull(User::query()->where('email', 'test@example.com')->first());
    }

    public function test_registration_does_not_send_a_verification_email_in_the_local_demo_flow()
    {
        Notification::fake();

        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        Notification::assertNothingSent();
    }

    public function test_the_registered_event_is_still_dispatched()
    {
        Event::fake();

        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        // The event is dispatched so any future welcome-email or audit
        // listener still fires. Only the verification notification itself is
        // suppressed.
        Event::assertDispatched(Registered::class);
    }

    public function test_an_unverified_user_can_reach_the_dashboard_in_the_local_demo_flow()
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_an_unverified_user_is_not_redirected_to_the_verification_notice()
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $this->assertNotSame(route('verification.notice'), $response->headers->get('Location'));
    }
}
