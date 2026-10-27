<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Enums\KycStatus;
use App\Enums\LedgerAccountType;
use App\Models\KycSubmission;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Services\KycService;
use App\Services\WalletProvisioningService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wallet provisioning: who gets a wallet, what it contains, and what it never
 * contains.
 *
 * The specification makes verified KYC a hard precondition for any ledger
 * write path (SPEC §11.1) and requires it before a payer wallet may be used
 * (§7.1 step 2). A wallet is therefore created when the identity is approved,
 * and not before.
 *
 * These tests cover the whole surface the provisioning rule has to get right:
 * eligibility, the shape of what is created, idempotency, the database
 * constraints that back it, and the fact that it creates no money.
 */
class WalletProvisioningTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Eligibility
    // -----------------------------------------------------------------------

    public function test_a_user_without_an_approved_kyc_gets_no_wallet(): void
    {
        $user = User::factory()->create();

        $wallet = app(WalletProvisioningService::class)->provision($user);

        $this->assertNull($wallet);
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('ledger_accounts', 0);
    }

    public function test_a_user_with_a_pending_kyc_gets_no_wallet(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->create(['status' => KycStatus::PENDING]);

        $wallet = app(WalletProvisioningService::class)->provision($user);

        $this->assertNull($wallet);
        $this->assertDatabaseCount('wallets', 0);
    }

    public function test_a_user_with_a_rejected_kyc_gets_no_wallet(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->rejected()->create();

        $wallet = app(WalletProvisioningService::class)->provision($user);

        $this->assertNull($wallet);
        $this->assertDatabaseCount('wallets', 0);
    }

    public function test_a_user_with_an_approved_kyc_gets_a_wallet(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        $wallet = app(WalletProvisioningService::class)->provision($user);

        $this->assertNotNull($wallet);
        $this->assertDatabaseHas('wallets', [
            'id' => $wallet->id,
            'owner_user_id' => $user->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // What is created
    // -----------------------------------------------------------------------

    public function test_the_provisioned_wallet_starts_at_exactly_zero(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        $wallet = app(WalletProvisioningService::class)->provision($user);

        $this->assertNotNull($wallet);
        $this->assertSame(0, $wallet->balance_minor);
        $this->assertSame(0, $wallet->reserved_minor);
        $this->assertSame(0, $wallet->availableMinor());
        $this->assertSame('BDT', $wallet->currency);
    }

    public function test_provisioning_creates_a_user_type_ledger_account(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        $wallet = app(WalletProvisioningService::class)->provision($user);

        $this->assertNotNull($wallet);

        $account = LedgerAccount::query()->findOrFail($wallet->account_id);

        $this->assertSame($user->id, $account->owner_user_id);
        $this->assertSame(LedgerAccountType::USER, $account->account_type);
        $this->assertNull($account->system_role);
        $this->assertSame('BDT', $account->currency);
        $this->assertSame(1, $account->next_sequence_number);
    }

    public function test_provisioning_creates_no_system_account(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        app(WalletProvisioningService::class)->provision($user);

        // H-1 (SYSTEM accounts) is Proposed and not approved. Provisioning
        // must never create one.
        $this->assertDatabaseCount('ledger_accounts', 1);
        $this->assertSame(
            0,
            LedgerAccount::query()->where('account_type', LedgerAccountType::SYSTEM)->count(),
        );
    }

    public function test_provisioning_creates_no_ledger_entries_and_no_money(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        app(WalletProvisioningService::class)->provision($user);

        // A wallet is the CAPACITY to hold money, not money. No entry is
        // written, so the hash chain is untouched.
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(0, Wallet::query()->sum('balance_minor'));
    }

    public function test_the_wallet_is_bound_to_the_account_and_the_owner(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        $wallet = app(WalletProvisioningService::class)->provision($user);

        $this->assertNotNull($wallet);
        $this->assertSame($user->id, $wallet->owner->id);
        $this->assertSame($wallet->account_id, $wallet->account->id);
        $this->assertSame($user->id, $wallet->account->owner_user_id);
    }

    // -----------------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------------

    public function test_provisioning_twice_creates_only_one_wallet(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        $service = app(WalletProvisioningService::class);

        $first = $service->provision($user);
        $second = $service->provision($user);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('ledger_accounts', 1);
    }

    public function test_provisioning_many_times_over_creates_only_one_wallet(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        $service = app(WalletProvisioningService::class);

        for ($i = 0; $i < 10; $i++) {
            $service->provision($user);
        }

        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('ledger_accounts', 1);
    }

    public function test_provisioning_preserves_an_existing_wallet_and_its_balance(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        $service = app(WalletProvisioningService::class);

        $wallet = $service->provision($user);
        $this->assertNotNull($wallet);

        // Simulate a wallet that already holds a posted balance.
        Wallet::query()->whereKey($wallet->id)->update(['balance_minor' => 5000]);

        $again = $service->provision($user);

        $this->assertNotNull($again);
        $this->assertSame($wallet->id, $again->id);
        $this->assertSame(5000, $again->fresh()->balance_minor);
        $this->assertDatabaseCount('wallets', 1);
    }

    public function test_provisioning_preserves_an_existing_ledger_account(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        $account = LedgerAccount::factory()->forOwner($user)->create();

        $wallet = app(WalletProvisioningService::class)->provision($user);

        $this->assertNotNull($wallet);
        $this->assertSame($account->id, $wallet->account_id);
        $this->assertDatabaseCount('ledger_accounts', 1);
    }

    public function test_two_users_never_share_a_wallet_or_an_account(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        KycSubmission::factory()->for($first)->approved()->create();
        KycSubmission::factory()->for($second)->approved()->create();

        $service = app(WalletProvisioningService::class);

        $firstWallet = $service->provision($first);
        $secondWallet = $service->provision($second);

        $this->assertNotNull($firstWallet);
        $this->assertNotNull($secondWallet);
        $this->assertNotSame($firstWallet->id, $secondWallet->id);
        $this->assertNotSame($firstWallet->account_id, $secondWallet->account_id);
        $this->assertDatabaseCount('wallets', 2);
        $this->assertDatabaseCount('ledger_accounts', 2);
    }

    // -----------------------------------------------------------------------
    // Database integrity
    // -----------------------------------------------------------------------

    public function test_the_unique_constraint_on_the_owner_refuses_a_second_account(): void
    {
        $user = User::factory()->create();

        LedgerAccount::factory()->forOwner($user)->create();

        // `uq_ledger_accounts_owner` is UNIQUE on `owner_user_id`.
        $this->expectException(QueryException::class);

        LedgerAccount::factory()->forOwner($user)->create();
    }

    public function test_the_unique_constraint_on_the_account_refuses_a_second_wallet(): void
    {
        $user = User::factory()->create();
        $account = LedgerAccount::factory()->forOwner($user)->create();

        Wallet::factory()->forOwner($user)->create(['account_id' => $account->id]);

        // `uq_wallets_account` is UNIQUE on `account_id`.
        $this->expectException(QueryException::class);

        Wallet::factory()->create(['account_id' => $account->id]);
    }

    public function test_the_account_must_belong_to_the_wallet_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $account = LedgerAccount::factory()->forOwner($owner)->create();

        // A wallet whose owner differs from the account's owner breaks the
        // 1:1 mapping the reconciliation invariant depends on.
        $this->expectException(QueryException::class);

        Wallet::query()->create([
            'owner_user_id' => $other->id,
            'account_id' => $account->id,
            'currency' => 'BDT',
            'balance_minor' => 0,
            'reserved_minor' => 0,
        ]);
    }

    public function test_the_wallet_account_mapping_cannot_be_repointed(): void
    {
        $user = User::factory()->create();
        $account = LedgerAccount::factory()->forOwner($user)->create();
        $wallet = Wallet::factory()->forOwner($user)->create(['account_id' => $account->id]);

        $otherAccount = LedgerAccount::factory()->forOwner(User::factory()->create())->create();

        // `trg_wallets_account_id_immutable` refuses the re-point.
        $this->expectException(QueryException::class);

        Wallet::query()->whereKey($wallet->id)->update(['account_id' => $otherAccount->id]);
    }

    public function test_a_negative_balance_is_impossible(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        $wallet = app(WalletProvisioningService::class)->provision($user);
        $this->assertNotNull($wallet);

        // `balance_minor` is BIGINT UNSIGNED.
        $this->expectException(QueryException::class);

        Wallet::query()->whereKey($wallet->id)->update(['balance_minor' => -1]);
    }

    // -----------------------------------------------------------------------
    // The KYC approval path
    // -----------------------------------------------------------------------

    public function test_approving_a_kyc_provisions_the_wallet(): void
    {
        $user = User::factory()->create();
        $submission = KycSubmission::factory()->for($user)->inReview()->create();

        $admin = User::factory()->admin()->create();

        app(KycService::class)->decide($admin, $submission, KycStatus::APPROVED);

        $this->assertDatabaseHas('wallets', [
            'owner_user_id' => $user->id,
            'balance_minor' => 0,
            'reserved_minor' => 0,
        ]);
        $this->assertDatabaseHas('ledger_accounts', [
            'owner_user_id' => $user->id,
            'account_type' => LedgerAccountType::USER->value,
        ]);
    }

    public function test_approving_a_kyc_writes_no_ledger_entries(): void
    {
        $user = User::factory()->create();
        $submission = KycSubmission::factory()->for($user)->inReview()->create();

        $admin = User::factory()->admin()->create();

        app(KycService::class)->decide($admin, $submission, KycStatus::APPROVED);

        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_rejecting_a_kyc_provisions_no_wallet(): void
    {
        $user = User::factory()->create();
        $submission = KycSubmission::factory()->for($user)->inReview()->create();

        $admin = User::factory()->admin()->create();

        app(KycService::class)->decide($admin, $submission, KycStatus::REJECTED, 'Document unreadable.');

        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('ledger_accounts', 0);
    }

    public function test_approving_a_kyc_twice_never_creates_a_second_wallet(): void
    {
        $user = User::factory()->create();
        $submission = KycSubmission::factory()->for($user)->inReview()->create();

        $admin = User::factory()->admin()->create();
        $kyc = app(KycService::class);

        $kyc->decide($admin, $submission, KycStatus::APPROVED);

        // The state machine forbids a second APPROVED decision, but the
        // provisioning rule must be safe even if it were attempted.
        $service = app(WalletProvisioningService::class);
        $service->provisionForEligibleUser($user);
        $service->provisionForEligibleUser($user);

        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('ledger_accounts', 1);
    }
}
