<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LedgerAccountType;
use App\Enums\UserRole;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * The local development seed.
 *
 * Creates one administrator and one customer, each with a ledger account and
 * a wallet, so the platform can be exercised end to end against a real
 * database.
 *
 * The starting balance is deliberately ZERO. There is no approved funding path
 * (H-1 is Proposed, not approved), so seeding a non-zero balance would be
 * inventing money. The wallet row exists with a zero balance, which is honest
 * and leaves room for the funding story to be decided later.
 *
 * These accounts are for LOCAL DEVELOPMENT ONLY. The credentials are read from
 * environment variables so nothing secret is committed.
 */
class DevSeeder extends Seeder
{
    public function run(): void
    {
        $admin = $this->upsertUser(
            name: (string) config('p2p-dev.admin.name'),
            email: (string) config('p2p-dev.admin.email'),
            password: (string) config('p2p-dev.admin.password'),
            role: UserRole::ADMIN,
        );

        $customer = $this->upsertUser(
            name: (string) config('p2p-dev.customer.name'),
            email: (string) config('p2p-dev.customer.email'),
            password: (string) config('p2p-dev.customer.password'),
            role: UserRole::USER,
        );

        $this->provisionLedgerAccount($admin);
        $this->provisionLedgerAccount($customer);

        $this->command?->info(sprintf(
            'Seeded %d development user(s) with ledger accounts and wallets (zero balance).',
            2,
        ));
    }

    /**
     * Create or update a user, without disturbing an existing row's identity.
     */
    private function upsertUser(
        string $name,
        string $email,
        string $password,
        UserRole $role,
    ): User {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = new User;
            $user->email = $email;
        }

        $user->name = $name;
        $user->password = Hash::make($password);
        $user->role = $role;
        $user->email_verified_at = Carbon::now();
        $user->save();

        return $user;
    }

    /**
     * Give a user their ledger account and wallet.
     *
     * The account type is USER, the currency is BDT, and the balance is zero.
     * No SYSTEM account is created — H-1 is still Proposed and not approved.
     */
    private function provisionLedgerAccount(User $user): void
    {
        $account = LedgerAccount::query()
            ->where('owner_user_id', $user->id)
            ->first();

        if ($account === null) {
            // `ledger_accounts` is fully guarded, so the attributes are set
            // explicitly rather than mass-assigned.
            $account = new LedgerAccount;
            $account->owner_user_id = $user->id;
            $account->account_type = LedgerAccountType::USER;
            $account->system_role = null;
            $account->currency = 'BDT';
            $account->next_sequence_number = 1;
            $account->created_at = Carbon::now();
            $account->save();
        }

        $wallet = Wallet::query()->where('account_id', $account->id)->first();

        if ($wallet === null) {
            // `wallets` is fully guarded for the same reason.
            $wallet = new Wallet;
            $wallet->owner_user_id = $user->id;
            $wallet->account_id = $account->id;
            $wallet->currency = 'BDT';
            $wallet->balance_minor = 0;
            $wallet->reserved_minor = 0;
            $wallet->save();
        }
    }
}
