<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * The email-verification requirement is a switch, not a deletion.
 *
 * The local development / demo flow runs with the requirement OFF: a user
 * registers, logs in, and reaches the dashboard without any verification
 * detour. Production runs with it ON.
 *
 * These tests cover both positions of the switch, so the production
 * safeguard is proven to still exist rather than merely assumed.
 */
class EmailVerificationRequirementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::emailVerification());
    }

    /**
     * Turn the requirement on for the duration of a test.
     */
    private function requireEmailVerification(): void
    {
        config(['p2p-auth.require_email_verification' => true]);
    }

    /**
     * Turn the requirement off for the duration of a test.
     */
    private function skipEmailVerification(): void
    {
        config(['p2p-auth.require_email_verification' => false]);
    }

    public function test_the_requirement_defaults_to_off_for_the_local_demo_flow()
    {
        $this->assertFalse((bool) config('p2p-auth.require_email_verification'));
    }

    public function test_with_the_requirement_on_an_unverified_user_is_redirected_to_the_verification_notice()
    {
        $this->requireEmailVerification();

        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('verification.notice'));
    }

    public function test_with_the_requirement_on_an_unverified_user_cannot_reach_the_admin_area()
    {
        $this->requireEmailVerification();

        $admin = User::factory()->admin()->unverified()->create();

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertRedirect(route('verification.notice'));
    }

    public function test_with_the_requirement_on_a_verified_user_reaches_the_dashboard()
    {
        $this->requireEmailVerification();

        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_with_the_requirement_on_registration_sends_the_verification_email()
    {
        $this->requireEmailVerification();

        Notification::fake();

        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        Notification::assertSentTo(
            User::query()->where('email', 'test@example.com')->firstOrFail(),
            VerifyEmail::class,
        );
    }

    public function test_with_the_requirement_off_registration_sends_no_verification_email()
    {
        $this->skipEmailVerification();

        Notification::fake();

        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        Notification::assertNothingSent();
    }

    public function test_with_the_requirement_off_an_unverified_user_reaches_the_dashboard()
    {
        $this->skipEmailVerification();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_with_the_requirement_off_the_verification_routes_are_still_registered()
    {
        // The production path must still be reachable in the source, even
        // though the local flow does not use it.
        $this->assertTrue(
            $this->app['router']->has('verification.notice'),
            'the verification notice route is missing',
        );

        $this->assertTrue(
            $this->app['router']->has('verification.verify'),
            'the verification link route is missing',
        );

        $this->assertTrue(
            $this->app['router']->has('verification.send'),
            'the verification notification route is missing',
        );
    }

    public function test_the_verification_link_still_verifies_a_user_when_the_requirement_is_on()
    {
        $this->requireEmailVerification();

        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_the_registered_event_is_dispatched_in_both_positions_of_the_switch()
    {
        foreach ([true, false] as $required) {
            config(['p2p-auth.require_email_verification' => $required]);

            Event::fake();

            $this->post(route('register.store'), [
                'name' => 'Test User '.$required,
                'email' => 'test-'.($required ? 'on' : 'off').'@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            Event::assertDispatched(Registered::class);
        }
    }
}
