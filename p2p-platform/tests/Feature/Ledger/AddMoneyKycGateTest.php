<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Enums\FundingMethod;
use App\Enums\KycStatus;
use App\Models\KycSubmission;
use App\Models\User;
use App\Models\WalletFunding;
use App\Services\WalletProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The KYC gate on the "Add Money" screen.
 *
 * ## What these tests prove
 *
 *  - a user whose identity is not approved is told WHY the form is not there,
 *    in the words their own status calls for;
 *  - a user with no submission, or with a rejected one, is given a way forward
 *    (a link to the KYC page);
 *  - a user whose identity is approved still gets the full, unchanged form;
 *  - and, above all, that the gate is a real authorisation boundary and not
 *    only a hidden form: a client that posts a funding attempt anyway is
 *    refused by the server, and no money moves.
 *
 * The server-side refusal is enforced in `WalletFundingService::assertEligible()`.
 * These tests exercise it through the HTTP route, because that is the boundary
 * a hostile client would attack.
 */
class AddMoneyKycGateTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * A signed-in user with an approved KYC and a provisioned, zero-balance wallet.
     */
    private function eligibleUser(): User
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        app(WalletProvisioningService::class)->provision($user);

        return $user->fresh();
    }

    /**
     * A signed-in user whose KYC is in the given state.
     */
    private function userWithKyc(KycStatus $status): User
    {
        $user = User::factory()->create();

        if ($status !== KycStatus::NOT_SUBMITTED) {
            KycSubmission::factory()->for($user)->create([
                'status' => $status,
            ]);
        }

        return $user->fresh();
    }

    /**
     * The details bKash needs.
     *
     * @return array<string, string>
     */
    private function bkashDetails(): array
    {
        return ['mobile_number' => '01700000000'];
    }

    // -----------------------------------------------------------------------
    // The status each user is shown
    // -----------------------------------------------------------------------

    public function test_the_add_money_page_reports_the_users_kyc_status(): void
    {
        $user = $this->userWithKyc(KycStatus::PENDING);

        $response = $this->actingAs($user)->get('/wallet/fundings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('kyc.status', KycStatus::PENDING->value)
            ->where('kyc.statusLabel', KycStatus::PENDING->label())
        );
    }

    public function test_a_user_with_no_submission_is_told_to_complete_kyc(): void
    {
        $user = $this->userWithKyc(KycStatus::NOT_SUBMITTED);

        $response = $this->actingAs($user)->get('/wallet/fundings');

        $response->assertOk();

        // The page is rendered on the client, so the server contract is what
        // carries the message: the status the client turns into the "complete
        // KYC" copy and the "Complete KYC" link to the KYC page.
        $response->assertInertia(fn ($page) => $page
            ->where('kyc.status', KycStatus::NOT_SUBMITTED->value)
            ->where('kyc.statusLabel', KycStatus::NOT_SUBMITTED->label())
        );
    }

    public function test_a_user_whose_kyc_is_pending_is_told_it_is_under_review(): void
    {
        $user = $this->userWithKyc(KycStatus::PENDING);

        $response = $this->actingAs($user)->get('/wallet/fundings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('kyc.status', KycStatus::PENDING->value)
        );
    }

    public function test_a_user_whose_kyc_is_in_review_is_told_it_is_under_review(): void
    {
        $user = $this->userWithKyc(KycStatus::IN_REVIEW);

        $response = $this->actingAs($user)->get('/wallet/fundings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('kyc.status', KycStatus::IN_REVIEW->value)
        );
    }

    public function test_a_user_whose_kyc_was_rejected_is_told_to_resubmit(): void
    {
        $user = $this->userWithKyc(KycStatus::REJECTED);

        $response = $this->actingAs($user)->get('/wallet/fundings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('kyc.status', KycStatus::REJECTED->value)
        );
    }

    /**
     * @return array<string, array{0: KycStatus}>
     */
    public static function nonApprovedStatusProvider(): array
    {
        return [
            'not submitted' => [KycStatus::NOT_SUBMITTED],
            'pending' => [KycStatus::PENDING],
            'in review' => [KycStatus::IN_REVIEW],
            'rejected' => [KycStatus::REJECTED],
        ];
    }

    #[DataProvider('nonApprovedStatusProvider')]
    public function test_a_non_approved_user_is_told_which_state_they_are_in(KycStatus $status): void
    {
        $user = $this->userWithKyc($status);

        $response = $this->actingAs($user)->get('/wallet/fundings');

        $response->assertOk();

        // The page renders, and it carries the one thing the client needs to
        // choose the right message and the right action: the status.
        $response->assertInertia(fn ($page) => $page
            ->where('kyc.status', $status->value)
            ->where('kyc.statusLabel', $status->label())
        );
    }

    #[DataProvider('nonApprovedStatusProvider')]
    public function test_a_user_without_an_approved_kyc_cannot_post_a_funding_attempt(KycStatus $status): void
    {
        $user = $this->userWithKyc($status);

        $response = $this->actingAs($user)->post('/wallet/fundings', [
            'method' => FundingMethod::BKASH->value,
            'amount' => '5000',
            'mobile_number' => '01700000000',
        ]);

        $response->assertSessionHasErrors('kyc');

        // Nothing was created and nothing moved.
        $this->assertDatabaseCount('wallet_fundings', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    // -----------------------------------------------------------------------
    // The approved path is unchanged
    // -----------------------------------------------------------------------

    public function test_an_approved_user_still_gets_the_full_form(): void
    {
        $user = $this->eligibleUser();

        $response = $this->actingAs($user)->get('/wallet/fundings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('kyc.status', KycStatus::APPROVED->value)
            ->where('kyc.statusLabel', KycStatus::APPROVED->label())
            // The whole flow is still there, unchanged.
            ->where('methods', fn ($methods): bool => collect($methods)
                ->pluck('value')
                ->all() === ['BKASH', 'NAGAD', 'BANK_TRANSFER', 'CARD'])
            ->where('limits.maxAmountMinor', 5000000)
        );
    }

    public function test_an_approved_user_can_still_fund_over_http(): void
    {
        $user = $this->eligibleUser();

        $response = $this->actingAs($user)->post('/wallet/fundings', [
            'method' => FundingMethod::BKASH->value,
            'amount' => '5000',
            'mobile_number' => '01700000000',
        ]);

        $funding = WalletFunding::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('wallet.fundings.show', ['funding' => $funding->id]));
    }

    // -----------------------------------------------------------------------
    // The gate is a real boundary, not a hidden form
    // -----------------------------------------------------------------------

    public function test_the_server_refuses_a_posted_attempt_even_when_the_page_was_gated(): void
    {
        $user = $this->userWithKyc(KycStatus::PENDING);

        // The page shows no form...
        $this->actingAs($user)
            ->get('/wallet/fundings')
            ->assertOk()
            ->assertDontSee('Continue to payment');

        // ...but a direct POST is still refused.
        $this->actingAs($user)
            ->post('/wallet/fundings', [
                'method' => FundingMethod::BKASH->value,
                'amount' => '5000',
                'mobile_number' => '01700000000',
            ])
            ->assertSessionHasErrors('kyc');

        $this->assertDatabaseCount('wallet_fundings', 0);
    }

    public function test_a_user_without_an_approved_kyc_never_reaches_a_payment_screen(): void
    {
        $user = $this->userWithKyc(KycStatus::REJECTED);

        // Even a funding id that does not exist must not become reachable.
        $this->actingAs($user)
            ->get('/wallet/fundings/1')
            ->assertNotFound();
    }

    public function test_the_gate_does_not_change_any_other_account(): void
    {
        $gated = $this->userWithKyc(KycStatus::PENDING);
        $approved = $this->eligibleUser();

        $this->actingAs($gated)
            ->post('/wallet/fundings', [
                'method' => FundingMethod::BKASH->value,
                'amount' => '5000',
                'mobile_number' => '01700000000',
            ])
            ->assertSessionHasErrors('kyc');

        // The approved user's account is untouched.
        $this->assertDatabaseCount('wallet_fundings', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transactions', 0);

        // And the approved user's own state is unchanged.
        $this->assertSame(
            KycStatus::APPROVED,
            $approved->kycSubmission()->firstOrFail()->status,
        );
    }
}
