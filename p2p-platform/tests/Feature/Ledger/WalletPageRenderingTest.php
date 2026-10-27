<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Models\KycSubmission;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The wallet page renders the REAL database-backed wallet.
 *
 * These tests are about what the browser is told. A page that returns HTTP 200
 * while showing "No wallet provisioned yet" to a user who has a wallet is
 * broken, so the props are asserted rather than the status code alone.
 */
class WalletPageRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_away_from_the_wallet_page(): void
    {
        $this->get(route('wallet'))->assertRedirect(route('login'));
    }

    public function test_the_wallet_page_renders_the_real_wallet_of_an_approved_user(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        app(WalletProvisioningService::class)->provision($user);

        $wallet = Wallet::query()->where('owner_user_id', $user->id)->firstOrFail();
        $account = LedgerAccount::query()->where('owner_user_id', $user->id)->firstOrFail();

        $response = $this->actingAs($user)->get(route('wallet'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('wallet')
            ->where('hasWallet', true)
            ->where('kycApproved', true)
            ->where('wallet.id', $wallet->id)
            ->where('wallet.currency', 'BDT')
            ->where('wallet.balanceMinor', 0)
            ->where('wallet.reservedMinor', 0)
            ->where('wallet.availableMinor', 0)
            ->where('wallet.accountId', $account->id)
            ->where('account.id', $account->id)
            ->where('account.nextSequenceNumber', 1)
        );
    }

    public function test_the_wallet_page_shows_a_zero_balance_in_poisha_not_a_formatted_string(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        app(WalletProvisioningService::class)->provision($user);

        $response = $this->actingAs($user)->get(route('wallet'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('wallet.balanceMinor', 0)
            ->where('wallet.availableMinor', 0)
        );
    }

    public function test_the_wallet_page_shows_no_wallet_for_a_user_without_an_approved_kyc(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('wallet'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('wallet')
            ->where('hasWallet', false)
            ->where('kycApproved', false)
            ->where('wallet', null)
            ->where('account', null)
        );

        $this->assertDatabaseCount('wallets', 0);
    }

    public function test_opening_the_wallet_page_provisions_a_missing_wallet_for_an_approved_user(): void
    {
        // The self-healing path: a user approved before the provisioning rule
        // existed, whose wallet was never created.
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        $this->assertDatabaseCount('wallets', 0);

        $response = $this->actingAs($user)->get(route('wallet'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('hasWallet', true)
            ->where('wallet.balanceMinor', 0)
        );

        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('ledger_accounts', 1);
    }

    public function test_the_wallet_page_is_stable_across_repeated_visits(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        $first = $this->actingAs($user)->get(route('wallet'));
        $second = $this->actingAs($user)->get(route('wallet'));
        $third = $this->actingAs($user)->get(route('wallet'));

        $first->assertInertia(fn (Assert $page) => $page->where('wallet.id', fn ($id) => is_int($id)));
        $second->assertInertia(fn (Assert $page) => $page->where('wallet.id', fn ($id) => is_int($id)));
        $third->assertInertia(fn (Assert $page) => $page->where('wallet.id', fn ($id) => is_int($id)));

        // Refreshing must never create a second wallet.
        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('ledger_accounts', 1);
    }

    public function test_the_wallet_page_shows_the_same_wallet_after_signing_in_again(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        app(WalletProvisioningService::class)->provision($user);

        $walletId = Wallet::query()->where('owner_user_id', $user->id)->value('id');

        // A fresh session, as if the user had signed out and back in.
        $this->post('/logout');

        $response = $this->actingAs($user)->get(route('wallet'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('hasWallet', true)
            ->where('wallet.id', $walletId)
            ->where('wallet.balanceMinor', 0)
        );

        $this->assertDatabaseCount('wallets', 1);
    }

    public function test_a_user_never_sees_another_users_wallet(): void
    {
        $owner = User::factory()->create();
        KycSubmission::factory()->for($owner)->approved()->create();
        app(WalletProvisioningService::class)->provision($owner);

        $other = User::factory()->create();

        $response = $this->actingAs($other)->get(route('wallet'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('hasWallet', false)
            ->where('wallet', null)
        );
    }

    public function test_the_wallet_page_never_claims_a_balance_it_did_not_read(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        $wallet = app(WalletProvisioningService::class)->provision($user);
        $this->assertNotNull($wallet);

        // Give the wallet a real, posted balance and reserved amount. The
        // page must report exactly these numbers, and available must be
        // balance minus reserved.
        Wallet::query()->whereKey($wallet->id)->update([
            'balance_minor' => 125000,
            'reserved_minor' => 2500,
        ]);

        $response = $this->actingAs($user)->get(route('wallet'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('wallet.balanceMinor', 125000)
            ->where('wallet.reservedMinor', 2500)
            ->where('wallet.availableMinor', 122500)
        );
    }
}
