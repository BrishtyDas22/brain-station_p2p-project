<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FundingIntent;
use App\Enums\FundingMethod;
use App\Enums\LedgerDirection;
use App\Models\KycSubmission;
use App\Models\LedgerAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use App\Services\WalletFundingService;
use App\Services\WalletProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Runs the exact "Add Money" scenario against the configured database and prints the
 * resulting accounting.
 *
 * This is a **verification harness**, not an application feature: it exists so the
 * funding path can be exercised end-to-end and the resulting ledger postings read back
 * from the database, on the development instance, without a browser.
 *
 * It creates its own throwaway user, so it never touches an existing account's balance.
 */
class VerifySandboxFundingScenario extends Command
{
    protected $signature = 'p2p:verify-sandbox-funding';

    protected $description = 'Exercise the Add Money flow and print the resulting ledger accounting.';

    public function handle(): int
    {
        $this->line('Database: '.config('database.default').' → '.config('database.connections.'.config('database.default').'.database'));

        // A throwaway user. The scenario is proven on its own account so no existing
        // balance is disturbed.
        $user = User::factory()->create(['email' => 'verify-'.uniqid().'@example.test']);
        KycSubmission::factory()->for($user)->approved()->create();
        app(WalletProvisioningService::class)->provision($user);

        $wallet = Wallet::query()->where('owner_user_id', $user->id)->firstOrFail();

        $this->line('');
        $this->line('STEP 0 — initial main wallet balance');
        $this->line(sprintf('  wallet.balance_minor = %d', $wallet->balance_minor));
        $this->assertSame('0', (string) $wallet->balance_minor, 'The wallet must start at zero.');

        // -------------------------------------------------------------------
        // ৳5,000 through bKash
        // -------------------------------------------------------------------
        $service = app(WalletFundingService::class);

        $bkash = $service->start($user, FundingMethod::BKASH, '5000', ['mobile_number' => '01700000000']);
        $bkash = $service->settle($user, $bkash, FundingIntent::CONFIRM);

        $wallet->refresh();
        $this->line('');
        $this->line('STEP 1 — Add ৳5,000 through bKash');
        $this->line(sprintf('  funding.reference      = %s', $bkash->reference));
        $this->line(sprintf('  funding.status         = %s', $bkash->status->value));
        $this->line(sprintf('  wallet.balance_minor   = %d', $wallet->balance_minor));
        $this->assertSame('500000', (string) $wallet->balance_minor, 'The wallet must hold ৳5,000.');

        $this->reportPosting($bkash, $user);

        // -------------------------------------------------------------------
        // ৳2,000 through Nagad
        // -------------------------------------------------------------------
        $nagad = $service->start($user, FundingMethod::NAGAD, '2000', ['mobile_number' => '01800000000']);
        $nagad = $service->settle($user, $nagad, FundingIntent::CONFIRM);

        $wallet->refresh();
        $this->line('');
        $this->line('STEP 2 — Add ৳2,000 through Nagad');
        $this->line(sprintf('  funding.reference      = %s', $nagad->reference));
        $this->line(sprintf('  funding.status         = %s', $nagad->status->value));
        $this->line(sprintf('  wallet.balance_minor   = %d', $wallet->balance_minor));
        $this->assertSame('700000', (string) $wallet->balance_minor, 'The wallet must hold ৳7,000.');

        $this->reportPosting($nagad, $user);

        // -------------------------------------------------------------------
        // A failed payment credits nothing
        // -------------------------------------------------------------------
        $failed = $service->start($user, FundingMethod::BANK_TRANSFER, '3000', [
            'bank' => 'Bank Asia',
            'account_number' => '0001234567',
        ]);
        $failed = $service->settle($user, $failed, FundingIntent::FAIL);

        $wallet->refresh();
        $this->line('');
        $this->line('STEP 3 — a failed ৳3,000 bank transfer');
        $this->line(sprintf('  funding.status         = %s', $failed->status->value));
        $this->line(sprintf('  wallet.balance_minor   = %d', $wallet->balance_minor));
        $this->assertSame('700000', (string) $wallet->balance_minor, 'A failed payment must not move the balance.');
        $this->assertSame('0', (string) DB::table('ledger_entries')->where('transaction_id', $failed->transaction_id ?? 0)->count(), 'A failed payment must post no entries.');

        // -------------------------------------------------------------------
        // The whole-book balance
        // -------------------------------------------------------------------
        $this->line('');
        $this->line('THE WHOLE BOOK');
        $this->assertLedgerBalanced();

        $debits = (int) DB::table('ledger_entries')->where('direction', 'DEBIT')->sum('amount_minor');
        $credits = (int) DB::table('ledger_entries')->where('direction', 'CREDIT')->sum('amount_minor');
        $this->line(sprintf('  SUM(DEBIT)  = %d', $debits));
        $this->line(sprintf('  SUM(CREDIT) = %d', $credits));
        $this->assertSame((string) $debits, (string) $credits, 'The whole book must balance.');

        // The transaction history.
        $this->line('');
        $this->line('TRANSACTION HISTORY (as the user sees it)');
        $history = Transaction::query()
            ->where('sender_account_id', $wallet->account_id)
            ->orWhere('recipient_account_id', $wallet->account_id)
            ->latest()
            ->get();

        foreach ($history as $transaction) {
            $this->line(sprintf(
                '  %-24s %-14s %-10s %12d  %s',
                $transaction->reference,
                $transaction->method,
                $transaction->direction->value,
                $transaction->amount_minor,
                $transaction->note,
            ));
        }

        $this->line('');
        $this->line('The sandbox funding account:');
        $fundingAccount = LedgerAccount::query()
            ->where('account_type', 'SYSTEM')
            ->where('system_role', 'FUNDING')
            ->first();

        if ($fundingAccount !== null) {
            $this->line(sprintf('  id=%d owner_user_id=%s next_sequence_number=%d', $fundingAccount->id, var_export($fundingAccount->owner_user_id, true), $fundingAccount->next_sequence_number));
        }

        $this->line('');
        $this->line('The funding attempts:');
        foreach (WalletFunding::query()->where('owner_user_id', $user->id)->get() as $funding) {
            $this->line(sprintf(
                '  %-24s %-14s %-10s %12d',
                $funding->reference,
                $funding->method->value,
                $funding->status->value,
                $funding->amount_minor,
            ));
        }

        $this->line('');
        $this->info('VERIFIED: the main, ledger-backed wallet was credited with balanced double-entry postings.');
        $this->line(sprintf('The verification user is %s (id %d).', $user->email, $user->id));
        $this->line('Its ledger entries are APPEND-ONLY and cannot be deleted — the user may be removed,');
        $this->line('but its funding postings stay in the ledger as a permanent record. That is the');
        $this->line('append-only guarantee working, not a cleanup failure.');

        return self::SUCCESS;
    }

    /**
     * Print the two ledger entries behind a funding and prove they balance.
     */
    private function reportPosting(WalletFunding $funding, User $user): void
    {
        if ($funding->transaction_id === null) {
            return;
        }

        $entries = DB::table('ledger_entries')
            ->where('transaction_id', $funding->transaction_id)
            ->orderBy('direction')
            ->get();

        $this->line('  ledger entries:');

        foreach ($entries as $entry) {
            $this->line(sprintf(
                '    %-6s account=%-3d amount=%12d seq=%-3d balance_after=%-12d %s',
                $entry->direction,
                $entry->account_id,
                $entry->amount_minor,
                $entry->account_sequence_number,
                $entry->balance_after,
                substr((string) $entry->entry_hash, 0, 16),
            ));
        }

        $debit = (int) $entries->where('direction', LedgerDirection::DEBIT->value)->sum('amount_minor');
        $credit = (int) $entries->where('direction', LedgerDirection::CREDIT->value)->sum('amount_minor');

        $this->line(sprintf('    SUM(DEBIT)=%d  SUM(CREDIT)=%d  balanced=%s', $debit, $credit, $debit === $credit ? 'YES' : 'NO'));

        $this->assertSame((string) $debit, (string) $credit, 'The posting must be balanced.');
        $this->assertSame('2', (string) $entries->count(), 'A posting must be exactly two entries.');
    }

    /**
     * Prove no transaction in the book is unbalanced.
     */
    private function assertLedgerBalanced(): void
    {
        $unbalanced = DB::table('ledger_entries')
            ->select('transaction_id')
            ->groupBy('transaction_id')
            ->selectRaw('transaction_id, SUM(CASE WHEN direction = \'DEBIT\' THEN amount_minor ELSE 0 END) AS d, SUM(CASE WHEN direction = \'CREDIT\' THEN amount_minor ELSE 0 END) AS c')
            ->havingRaw('d <> c')
            ->get();

        $this->assertSame('0', (string) $unbalanced->count(), 'No transaction may be unbalanced.');
    }

    /**
     * Fail the command when an assertion does not hold.
     */
    private function assertSame(string $expected, string $actual, string $message): void
    {
        if ($expected !== $actual) {
            $this->error(sprintf('%s (expected %s, got %s)', $message, $expected, $actual));

            throw new \RuntimeException($message);
        }
    }
}
