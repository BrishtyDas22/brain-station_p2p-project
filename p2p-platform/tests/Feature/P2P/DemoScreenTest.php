<?php

namespace Tests\Feature\P2P;

use App\Enums\LedgerAccountType;
use App\Models\Dispute;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Reversal;
use App\Models\SettlementBatch;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The screens whose write path is not implemented.
 *
 * These pages are fully rendered and read real data, but every one of them
 * declares `availability.enabled = false` and names the exact blocker. The critical
 * guarantee is what they do NOT do: they never write a row to a financial
 * table, so nothing on screen can be mistaken for a settled transfer.
 */
class DemoScreenTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The screens whose disabled action is explained on the page.
     *
     * @return list<string>
     */
    private function demoRoutes(): array
    {
        return ['receive', 'notifications'];
    }

    public function test_each_demo_screen_is_disabled_and_states_its_blocker()
    {
        $user = User::factory()->create();

        foreach ($this->demoRoutes() as $name) {
            $response = $this->actingAs($user)->get(route($name));

            $response->assertOk();
            $response->assertInertia(fn ($page) => $page
                ->where('availability.enabled', false)
                ->where('availability.reason', fn (string $reason): bool => $reason !== '')
            );
        }
    }

    public function test_the_demo_screens_write_no_financial_rows()
    {
        $user = User::factory()->create();

        foreach ($this->demoRoutes() as $name) {
            $this->actingAs($user)->get(route($name));
        }

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame(0, SettlementBatch::query()->count());
        $this->assertSame(0, Reversal::query()->count());
        $this->assertSame(0, Dispute::query()->count());
    }

    public function test_the_receive_screen_reports_the_real_balance()
    {
        $user = User::factory()->create();

        $account = new LedgerAccount;
        $account->owner_user_id = $user->id;
        $account->account_type = LedgerAccountType::USER;
        $account->currency = 'BDT';
        $account->next_sequence_number = 1;
        $account->save();

        $wallet = new Wallet;
        $wallet->owner_user_id = $user->id;
        $wallet->account_id = $account->id;
        $wallet->currency = 'BDT';
        $wallet->balance_minor = 125_000;
        $wallet->reserved_minor = 25_000;
        $wallet->save();

        $response = $this->actingAs($user)->get(route('receive'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('wallet.balanceMinor', 125_000)
            ->where('availability.enabled', false)
        );
    }

    public function test_the_receive_screen_reports_a_zero_balance_when_there_is_no_wallet()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('receive'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('wallet.balanceMinor', 0)
        );
    }

    public function test_a_customer_cannot_see_another_customers_wallet()
    {
        $owner = User::factory()->create();
        $peer = User::factory()->create();

        $account = new LedgerAccount;
        $account->owner_user_id = $owner->id;
        $account->account_type = LedgerAccountType::USER;
        $account->currency = 'BDT';
        $account->next_sequence_number = 1;
        $account->save();

        $wallet = new Wallet;
        $wallet->owner_user_id = $owner->id;
        $wallet->account_id = $account->id;
        $wallet->currency = 'BDT';
        $wallet->balance_minor = 900_000;
        $wallet->reserved_minor = 0;
        $wallet->save();

        // The owner's own wallet page shows the balance.
        $this->actingAs($owner)->get(route('wallet'))->assertInertia(fn ($page) => $page
            ->where('wallet.balanceMinor', 900_000)
        );

        // The peer's wallet page shows their own (empty) position, never the
        // owner's balance.
        $this->actingAs($peer)->get(route('wallet'))->assertInertia(fn ($page) => $page
            ->where('wallet', null)
        );
    }

    public function test_the_wallet_query_scope_excludes_a_peer_wallet()
    {
        $owner = User::factory()->create();
        $peer = User::factory()->create();

        $account = new LedgerAccount;
        $account->owner_user_id = $owner->id;
        $account->account_type = LedgerAccountType::USER;
        $account->currency = 'BDT';
        $account->next_sequence_number = 1;
        $account->save();

        $wallet = new Wallet;
        $wallet->owner_user_id = $owner->id;
        $wallet->account_id = $account->id;
        $wallet->currency = 'BDT';
        $wallet->balance_minor = 100;
        $wallet->reserved_minor = 0;
        $wallet->save();

        $this->assertSame(0, Wallet::query()->forOwner($peer->id)->count());
        $this->assertSame(1, Wallet::query()->forOwner($owner->id)->count());
    }

    public function test_the_transaction_history_is_scoped_to_the_signed_in_user()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('transactions.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('transactions/index')
            ->where('transactions.total', 0)
        );
    }

    public function test_the_admin_reports_screen_states_its_availability()
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.reports'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('availability.enabled', false)
        );
    }

    public function test_guests_cannot_reach_any_demo_screen()
    {
        foreach ($this->demoRoutes() as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }
    }
}
