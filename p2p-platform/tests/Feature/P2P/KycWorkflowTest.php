<?php

namespace Tests\Feature\P2P;

use App\Enums\KycStatus;
use App\Models\AuditRecord;
use App\Models\KycSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The KYC workflow: a customer submits, an administrator decides.
 *
 * The two halves are deliberately separate because they have different
 * authorisation: submission is a customer action, a decision is an
 * administrator action that is enforced in the service as well as in the
 * route middleware.
 */
class KycWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A complete, valid submission payload.
     *
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Rakib Hasan',
            'document_type' => 'National ID (NID)',
            'document_number' => '1990123456789',
            'date_of_birth' => '1990-12-03',
            'address_line' => 'House 12, Road 5, Dhanmondi',
            'city' => 'Dhaka',
            'country' => 'BD',
            'mobile_number' => '+8801700000000',
        ], $overrides);
    }

    public function test_guests_cannot_open_the_kyc_page()
    {
        $this->get(route('kyc.index'))->assertRedirect(route('login'));
    }

    public function test_a_customer_can_submit_identity_details()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('kyc.store'), $this->payload());

        $response->assertRedirect();

        $this->assertDatabaseHas('kyc_submissions', [
            'user_id' => $user->id,
            'status' => KycStatus::PENDING->value,
            'full_name' => 'Rakib Hasan',
            'document_type' => 'National ID (NID)',
            'city' => 'Dhaka',
        ]);
    }

    public function test_the_document_number_is_never_exposed_in_plain_text()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('kyc.store'), $this->payload());

        $submission = KycSubmission::query()->where('user_id', $user->id)->firstOrFail();

        $masked = $submission->maskedDocumentNumber();

        $this->assertNotSame('1990123456789', $masked);
        $this->assertStringNotContainsString('1990123456789', $masked);
    }

    public function test_submission_is_validated()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('kyc.store'), $this->payload([
                'full_name' => '',
                'document_type' => 'Not A Document',
                'document_number' => 'x',
                'date_of_birth' => '2999-01-01',
            ]))
            ->assertSessionHasErrors(['full_name', 'document_type', 'document_number', 'date_of_birth']);

        $this->assertDatabaseCount('kyc_submissions', 0);
    }

    public function test_a_submission_is_audited()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('kyc.store'), $this->payload());

        $this->assertDatabaseHas('audit_records', [
            'action' => 'KYC_SUBMITTED',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_an_approved_user_cannot_submit_again()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('kyc.store'), $this->payload());

        $submission = KycSubmission::query()->where('user_id', $user->id)->firstOrFail();
        $submission->status = KycStatus::APPROVED;
        $submission->save();

        $this->actingAs($user)
            ->post(route('kyc.store'), $this->payload())
            ->assertSessionHasErrors('kyc');

        $this->assertSame(1, KycSubmission::query()->count());
    }

    public function test_a_customer_cannot_reach_the_admin_review_queue()
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->get(route('admin.kyc'))->assertForbidden();
    }

    public function test_a_customer_cannot_decide_their_own_application()
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->post(route('kyc.store'), $this->payload());

        $submission = KycSubmission::query()->where('user_id', $customer->id)->firstOrFail();

        $this->actingAs($customer)
            ->post(route('admin.kyc.approve', $submission))
            ->assertForbidden();

        $this->assertSame(KycStatus::PENDING, $submission->fresh()?->status);
    }

    public function test_an_administrator_can_approve_an_application()
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $this->actingAs($customer)->post(route('kyc.store'), $this->payload());

        $submission = KycSubmission::query()->where('user_id', $customer->id)->firstOrFail();

        // PENDING → IN_REVIEW → APPROVED: the state machine does not allow a
        // direct jump from PENDING to APPROVED.
        $this->actingAs($admin)->post(route('admin.kyc.review', $submission))->assertRedirect();

        $this->assertSame(KycStatus::IN_REVIEW, $submission->fresh()?->status);

        $this->actingAs($admin)->post(route('admin.kyc.approve', $submission))->assertRedirect();

        $submission->refresh();

        $this->assertSame(KycStatus::APPROVED, $submission->status);
        $this->assertSame($admin->id, $submission->reviewed_by);
        $this->assertNotNull($submission->reviewed_at);
    }

    public function test_an_application_cannot_skip_the_review_state()
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $this->actingAs($customer)->post(route('kyc.store'), $this->payload());

        $submission = KycSubmission::query()->where('user_id', $customer->id)->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.kyc.approve', $submission))
            ->assertSessionHasErrors('status');

        $this->assertSame(KycStatus::PENDING, $submission->fresh()?->status);
    }

    public function test_an_administrator_can_reject_an_application_with_a_reason()
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $this->actingAs($customer)->post(route('kyc.store'), $this->payload());

        $submission = KycSubmission::query()->where('user_id', $customer->id)->firstOrFail();

        $this->actingAs($admin)->post(route('admin.kyc.reject', $submission), [
            'rejection_reason' => 'The document image was unreadable.',
        ])->assertRedirect();

        $submission->refresh();

        $this->assertSame(KycStatus::REJECTED, $submission->status);
        $this->assertSame('The document image was unreadable.', $submission->rejection_reason);
    }

    public function test_a_rejection_requires_a_reason()
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $this->actingAs($customer)->post(route('kyc.store'), $this->payload());

        $submission = KycSubmission::query()->where('user_id', $customer->id)->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.kyc.reject', $submission), ['rejection_reason' => ''])
            ->assertSessionHasErrors('rejection_reason');

        $this->assertSame(KycStatus::PENDING, $submission->fresh()?->status);
    }

    public function test_a_decision_is_audited()
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $this->actingAs($customer)->post(route('kyc.store'), $this->payload());

        $submission = KycSubmission::query()->where('user_id', $customer->id)->firstOrFail();

        $this->actingAs($admin)->post(route('admin.kyc.review', $submission));
        $this->actingAs($admin)->post(route('admin.kyc.approve', $submission));

        $this->assertDatabaseHas('audit_records', [
            'action' => 'KYC_APPROVED',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_the_audit_sequence_is_gapless_and_unique()
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $this->actingAs($customer)->post(route('kyc.store'), $this->payload());

        $submission = KycSubmission::query()->where('user_id', $customer->id)->firstOrFail();

        $this->actingAs($admin)->post(route('admin.kyc.review', $submission));
        $this->actingAs($admin)->post(route('admin.kyc.approve', $submission));

        // The counter row is bookkeeping and is not part of the chain.
        $sequences = AuditRecord::query()
            ->where('action', '!=', 'SEQUENCE_ALLOCATED')
            ->orderBy('sequence')
            ->pluck('sequence')
            ->all();

        $this->assertNotEmpty($sequences);
        $this->assertSame($sequences, array_values(array_unique($sequences)));

        // Gapless: every number from 1 to the maximum is present.
        $this->assertSame(range(1, max($sequences)), $sequences);
    }

    public function test_the_review_queue_shows_the_pending_application()
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $this->actingAs($customer)->post(route('kyc.store'), $this->payload());

        $response = $this->actingAs($admin)->get(route('admin.kyc'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/kyc-review')
            ->where('submissions.data.0.applicantEmail', $customer->email)
            ->where('submissions.data.0.status', KycStatus::PENDING->value)
        );
    }

    public function test_the_customer_sees_their_own_status_after_submitting()
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->post(route('kyc.store'), $this->payload());

        $response = $this->actingAs($customer)->get(route('kyc.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('kyc/index')
            ->where('status', KycStatus::PENDING->value)
            ->where('submission.fullName', 'Rakib Hasan')
        );
    }
}
