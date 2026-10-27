<?php

namespace Tests\Feature\P2P;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The profile update path.
 *
 * The profile form is the one write path a customer owns outright, so it is
 * checked for persistence rather than for authorisation.
 */
class ProfilePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_can_update_their_profile()
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'New Name',
            'email' => $user->email,
        ]);

        $response->assertRedirect();

        $user->refresh();

        $this->assertSame('New Name', $user->name);
    }

    public function test_a_customer_can_change_their_email()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'changed@example.test',
        ]);

        $this->assertSame('changed@example.test', $user->fresh()?->email);
    }

    public function test_the_email_must_be_unique()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $other->email,
        ])->assertSessionHasErrors('email');

        $this->assertNotSame($other->email, $user->fresh()?->email);
    }

    public function test_the_profile_update_is_validated()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => '',
            'email' => 'not-an-email',
        ])->assertSessionHasErrors(['name', 'email']);
    }

    public function test_a_guest_cannot_update_a_profile()
    {
        $this->patch(route('profile.update'), [
            'name' => 'Nobody',
            'email' => 'nobody@example.test',
        ])->assertRedirect(route('login'));
    }

    public function test_the_profile_page_renders()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    }
}
