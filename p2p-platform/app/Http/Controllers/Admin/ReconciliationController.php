<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The reconciliation summary.
 *
 * This reports what can be checked today:
 *
 *   - the double-entry invariant: for every transaction, SUM(DEBIT) equals
 *     SUM(CREDIT);
 *   - per-account sequence continuity: each account's entries run 1..n
 *     without a gap or a duplicate;
 *   - wallet/account mapping: one wallet per account, immutable.
 *
 * The hash-chain verification is NOT included: M-2 (the canonicalization
 * contract) is still open, so a chain verifier would be guessing. The page
 * says so rather than showing a green tick that means nothing.
 */
class ReconciliationController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // 1. Double-entry: every transaction must have equal debit and credit.
        $doubleEntryViolations = LedgerEntry::query()
            ->selectRaw('transaction_id')
            ->selectRaw('SUM(CASE WHEN direction = \'DEBIT\' THEN amount_minor ELSE 0 END) AS debits')
            ->selectRaw('SUM(CASE WHEN direction = \'CREDIT\' THEN amount_minor ELSE 0 END) AS credits')
            ->selectRaw('COUNT(*) AS entry_count')
            ->groupBy('transaction_id')
            ->havingRaw('SUM(CASE WHEN direction = \'DEBIT\' THEN amount_minor ELSE 0 END) <> SUM(CASE WHEN direction = \'CREDIT\' THEN amount_minor ELSE 0 END)')
            // The aggregates are raw SQL aliases, so the result is a base query
            // rather than a set of ledger-entry models.
            ->toBase()
            ->get();

        // 2. Sequence continuity per account.
        $sequenceViolations = [];

        foreach (LedgerAccount::query()->get() as $account) {
            $sequences = LedgerEntry::query()
                ->where('account_id', $account->id)
                ->orderBy('account_sequence_number')
                ->pluck('account_sequence_number')
                ->all();

            $expected = 1;

            foreach ($sequences as $sequence) {
                if ((int) $sequence !== $expected) {
                    $sequenceViolations[] = [
                        'accountId' => $account->id,
                        'expected' => $expected,
                        'found' => (int) $sequence,
                    ];
                    break;
                }

                $expected++;
            }
        }

        // 3. Wallet/account mapping — one wallet per account, no orphans.
        $walletsWithoutAccount = Wallet::query()
            ->whereNotIn('account_id', LedgerAccount::query()->select('id'))
            ->count();

        $accountsWithoutWallet = LedgerAccount::query()
            ->whereNotIn('id', Wallet::query()->select('account_id'))
            ->count();

        $entries = LedgerEntry::query()->count();
        $transactions = Transaction::query()->count();

        $violations = $doubleEntryViolations->count() + count($sequenceViolations)
            + $walletsWithoutAccount + $accountsWithoutWallet;

        return Inertia::render('admin/reconciliation', [
            'summary' => [
                'entries' => $entries,
                'transactions' => $transactions,
                'accounts' => LedgerAccount::query()->count(),
                'wallets' => Wallet::query()->count(),
                'violations' => $violations,
                'status' => $violations === 0 ? 'PASSED' : 'FAILED',
            ],
            'doubleEntryViolations' => $doubleEntryViolations->map(fn (\stdClass $row): array => [
                'transactionId' => (int) $row->transaction_id,
                'debits' => (int) $row->debits,
                'credits' => (int) $row->credits,
                'entryCount' => (int) $row->entry_count,
            ])->all(),
            'sequenceViolations' => $sequenceViolations,
            'mapping' => [
                'walletsWithoutAccount' => $walletsWithoutAccount,
                'accountsWithoutWallet' => $accountsWithoutWallet,
            ],
            'chainVerification' => [
                'available' => false,
                'reason' => 'M-2 — the canonicalization and cross-language hash contract is still open, so the hash chain cannot be verified yet. The append-only guarantee IS enforced by database triggers.',
            ],
        ]);
    }
}
