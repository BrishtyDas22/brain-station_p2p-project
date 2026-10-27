<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerDirection;
use App\Enums\LedgerSystemRole;
use App\Models\LedgerAccount;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Posts balanced double-entry postings to the append-only ledger.
 *
 * ## The invariant this service exists to uphold
 *
 * Every posting writes **exactly two** `ledger_entries` rows of equal magnitude and opposite
 * direction, belonging to one `transactions` row:
 *
 *     SUM(DEBIT) = SUM(CREDIT) for every transaction_id
 *
 * `ledger_entries` is append-only — the database refuses `UPDATE` and `DELETE` with
 * SQLSTATE 45000 — so the only way to move money is to append a balanced pair. Nothing in
 * this service writes `wallets.balance_minor` directly as a way of *creating* money: the
 * wallet projection is advanced to the new balance **after** the entries exist, and only by
 * the amount the entries say.
 *
 * ## The wallet projection
 *
 * `wallets.balance_minor` is a rebuildable projection of the ledger entries. It is updated
 * here so that a read of the wallet is cheap, but the entries remain the authority. The
 * update is a `balance_minor = balance_minor + <delta>` under a row lock, never an
 * assignment of an arbitrary number.
 *
 * ## The hash chain
 *
 * Each entry carries `prev_hash` and `entry_hash`. `entry_hash` is a SHA-256 over a
 * canonical, pipe-delimited body that includes the previous entry's hash, so the entries
 * form a tamper-evident chain per account.
 *
 * **M-2 (canonicalization) is ⚪ BLOCKED in the specification.** This service therefore
 * implements a **local, documented canonical form** — a fixed field order, a pipe separator,
 * and no ambiguity — and does NOT claim to implement the specification's canonicalization
 * rule, which has not been decided. The chain is internally consistent and tamper-evident
 * against casual edits; it is not yet certified against the specification's M-2 rule. That
 * gap is recorded in the OpenSpec change and in the epic memlog.
 */
final class LedgerPostingService
{
    /**
     * The genesis hash used as `prev_hash` for the very first entry of an account.
     *
     * **M-3 (the genesis literal) is ⚪ Open in the specification** — the exact genesis value
     * has not been decided. This is a local, documented constant for the sandbox chain. It is
     * not a specification-approved genesis value and is recorded as such.
     */
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Post a balanced pair of ledger entries and advance the affected wallet projection.
     *
     * The whole thing runs in ONE transaction, with the account rows locked `FOR UPDATE`, so
     * two concurrent postings to the same account serialise and the per-account sequence
     * numbers stay contiguous.
     *
     * @param  int  $amountMinor  The amount, in integer Poisha. Must be positive.
     * @param  int  $sourceAccountId  The account the money comes FROM. Debited.
     * @param  int  $destinationAccountId  The account the money goes TO. Credited.
     * @param  int  $transactionId  The transaction the entries belong to.
     * @param  int|null  $destinationWalletId  The wallet whose projection advances, when the
     *                                         destination is a user wallet.
     * @param  int|null  $sourceWalletId  The wallet whose projection reduces, when the source
     *                                    is a user wallet.
     */
    public function postPair(
        int $amountMinor,
        int $sourceAccountId,
        int $destinationAccountId,
        int $transactionId,
        ?int $destinationWalletId = null,
        ?int $sourceWalletId = null,
    ): void {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('A ledger posting amount must be greater than zero.');
        }

        if ($sourceAccountId === $destinationAccountId) {
            throw new \InvalidArgumentException('A ledger posting needs two distinct accounts.');
        }

        // A SYSTEM account may only be credited when it is the ESCROW account or the
        // PAYOUT account, and only when the other side of the pair is a real user wallet.
        //
        // The distinction matters:
        //
        //  - The sandbox `FUNDING` account is an EXTERNAL source. Crediting it would
        //    create money with no external inflow behind it — the one thing a balanced
        //    ledger forbids. So it is refused.
        //  - The `ESCROW` account holds real user capital in custody. A lender's wallet
        //    is debited and the escrow account credited by the same amount in the SAME
        //    balanced pair, so the credit is backed by a real user debit. That is
        //    custody, not creation.
        //  - The `PAYOUT` account is an EXTERNAL sink. Money leaving the platform is
        //    credited to it, and the credit is backed by the user's wallet being debited
        //    in the SAME balanced pair. That is an outflow, not a creation.
        //
        // Anything else is refused.
        if ($destinationWalletId === null) {
            $destinationRole = $this->systemRoleOf($destinationAccountId);

            if (! in_array($destinationRole, [LedgerSystemRole::ESCROW, LedgerSystemRole::PAYOUT], true)) {
                throw new \InvalidArgumentException(
                    'A system account cannot be credited by a ledger posting.'
                );
            }

            if ($sourceWalletId === null) {
                throw new \InvalidArgumentException(
                    'A system account may only be credited from a user wallet.'
                );
            }
        }

        DB::transaction(function () use (
            $amountMinor,
            $sourceAccountId,
            $destinationAccountId,
            $transactionId,
            $destinationWalletId,
            $sourceWalletId,
        ): void {
            // Lock both accounts, in a stable id order, so two postings that touch the
            // same pair of accounts can never deadlock against each other.
            $accountIds = [$sourceAccountId, $destinationAccountId];
            sort($accountIds);

            $accounts = LedgerAccount::query()
                ->whereIn('id', $accountIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($accounts->count() !== 2) {
                throw new \RuntimeException('A ledger posting needs two existing ledger accounts.');
            }

            /** @var LedgerAccount $source */
            $source = $accounts[$sourceAccountId];
            /** @var LedgerAccount $destination */
            $destination = $accounts[$destinationAccountId];

            $sourceBalance = $this->balanceOf($sourceWalletId, $source);
            $destinationBalance = $this->balanceOf($destinationWalletId, $destination);

            $destinationAfter = $destinationBalance + $amountMinor;

            if ($destinationAfter < 0) {
                throw new \RuntimeException('A ledger posting cannot take an account below zero.');
            }

            // A user wallet may never go below zero.
            //
            // A wallet-less SYSTEM account is one of three different things, and the
            // difference is what `balance_after` records:
            //
            //  - The sandbox `FUNDING` account is an EXTERNAL source. It holds no user
            //    money, so there is no balance to exhaust; what it records is the
            //    cumulative amount it has sourced, which only GROWS. `balance_after` is
            //    unsigned and constrained to be non-negative, so a "running balance" that
            //    went negative could not be written anyway; the sourced total is the
            //    honest value.
            //  - The `ESCROW` account holds real capital in custody. It DOES have a
            //    running balance — the capital currently held — and it may go down when
            //    that capital is disbursed or returned. It must never go below zero: an
            //    escrow that released more than it held would be inventing money.
            //  - The `PAYOUT` account is an EXTERNAL sink. Like `FUNDING`, it holds no
            //    user money; it records the cumulative amount that has been paid OUT,
            //    which only GROWS.
            $sourceAfter = match (true) {
                $sourceWalletId !== null => $sourceBalance - $amountMinor,
                $source->system_role === LedgerSystemRole::ESCROW => $sourceBalance - $amountMinor,
                default => $sourceBalance + $amountMinor,
            };

            if ($sourceAfter < 0) {
                throw new \RuntimeException('A ledger posting cannot take an account below zero.');
            }

            $this->appendEntry(
                $source,
                LedgerDirection::DEBIT,
                $amountMinor,
                $transactionId,
                $sourceAfter,
            );

            $this->appendEntry(
                $destination,
                LedgerDirection::CREDIT,
                $amountMinor,
                $transactionId,
                $destinationAfter,
            );

            // The projection is advanced only after both entries exist, and only by the
            // exact amount the entries carry.
            if ($sourceWalletId !== null) {
                $this->adjustWallet($sourceWalletId, -$amountMinor);
            }

            if ($destinationWalletId !== null) {
                $this->adjustWallet($destinationWalletId, $amountMinor);
            }
        }, 3);
    }

    /**
     * The SYSTEM funding account — the sandbox counterparty for an "Add Money" posting.
     *
     * It is a `SYSTEM` account with the `FUNDING` role and no owning user, which is exactly
     * the shape `ck_ledger_accounts_type` demands. It is created lazily and exactly once.
     *
     * **H-1 (SYSTEM accounts) is 🟠 Proposed and not approved.** This method creates a SYSTEM
     * account for a **local sandbox funding policy only**. It is not the production funding
     * account, it is not reachable from a production funding route, and no production
     * money-movement code path may use it. See the OpenSpec change
     * `add-sandbox-wallet-funding` for the full decision record.
     */
    public function sandboxFundingAccount(): LedgerAccount
    {
        $existing = LedgerAccount::query()
            ->where('account_type', LedgerAccountType::SYSTEM->value)
            ->where('system_role', LedgerSystemRole::FUNDING->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $account = new LedgerAccount;
        $account->owner_user_id = null;
        $account->account_type = LedgerAccountType::SYSTEM;
        $account->system_role = LedgerSystemRole::FUNDING;
        $account->currency = 'BDT';
        $account->next_sequence_number = 1;
        $account->save();

        return $account;
    }

    /**
     * The system role of an account, or NULL when it is not a SYSTEM account.
     */
    private function systemRoleOf(int $accountId): ?LedgerSystemRole
    {
        $account = LedgerAccount::query()
            ->whereKey($accountId)
            ->where('account_type', LedgerAccountType::SYSTEM->value)
            ->first();

        if ($account === null) {
            return null;
        }

        return $account->system_role;
    }

    /**
     * The escrow account — the custody account the PRD's escrow mechanism requires.
     *
     * It is a `SYSTEM` account with the `ESCROW` role and no owning user, which is
     * exactly the shape `ck_ledger_accounts_type` demands. It is created lazily and
     * exactly once.
     *
     * **This is not the sandbox funding account.** The funding account is an external
     * source of money; the escrow account holds capital that already exists — it came
     * out of a lender's real wallet, in a balanced pair, and it stays there until the
     * loan is disbursed or the commitment is returned. No money ever appears in the
     * escrow account without a matching debit from a real user wallet.
     */
    public function escrowAccount(): LedgerAccount
    {
        $existing = LedgerAccount::query()
            ->where('account_type', LedgerAccountType::SYSTEM->value)
            ->where('system_role', LedgerSystemRole::ESCROW->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $account = new LedgerAccount;
        $account->owner_user_id = null;
        $account->account_type = LedgerAccountType::SYSTEM;
        $account->system_role = LedgerSystemRole::ESCROW;
        $account->currency = 'BDT';
        $account->next_sequence_number = 1;
        $account->save();

        return $account;
    }

    /**
     * The sandbox payout account — the sink for money that leaves the platform.
     *
     * It is the exact mirror of the sandbox `FUNDING` account: `FUNDING` records money
     * that came IN, `PAYOUT` records money that went OUT. A "Withdraw Money" payout
     * debits the user's wallet and credits this account, so the pair stays balanced and
     * the total the platform has paid out is recorded rather than lost.
     *
     * **No real money leaves the platform.** The destination is simulated exactly like
     * the "Add Money" gateway: the client sends an intent, the server decides the
     * outcome, and only a successful settlement posts a balanced pair. No provider API
     * is contacted and no credential is collected.
     */
    public function sandboxPayoutAccount(): LedgerAccount
    {
        $existing = LedgerAccount::query()
            ->where('account_type', LedgerAccountType::SYSTEM->value)
            ->where('system_role', LedgerSystemRole::PAYOUT->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $account = new LedgerAccount;
        $account->owner_user_id = null;
        $account->account_type = LedgerAccountType::SYSTEM;
        $account->system_role = LedgerSystemRole::PAYOUT;
        $account->currency = 'BDT';
        $account->next_sequence_number = 1;
        $account->save();

        return $account;
    }

    /**
     * Append one ledger entry and advance the account's sequence allocator.
     *
     * The account row is already locked by the caller, so the sequence allocation here is
     * safe: two concurrent postings to the same account cannot claim the same sequence.
     */
    private function appendEntry(
        LedgerAccount $account,
        LedgerDirection $direction,
        int $amountMinor,
        int $transactionId,
        int $balanceAfter,
    ): void {
        $sequence = (int) $account->next_sequence_number;

        $previous = DB::table('ledger_entries')
            ->where('account_id', $account->id)
            ->orderByDesc('account_sequence_number')
            ->first();

        $prevHash = $previous === null
            ? self::GENESIS_HASH
            : (string) $previous->entry_hash;

        $entryId = (string) Str::ulid();

        $entryHash = $this->hash([
            $entryId,
            (string) $transactionId,
            (string) $account->id,
            $direction->value,
            (string) $amountMinor,
            $account->currency,
            (string) $sequence,
            (string) $balanceAfter,
            $prevHash,
        ]);

        DB::table('ledger_entries')->insert([
            'entry_id' => $entryId,
            'transaction_id' => $transactionId,
            'account_id' => $account->id,
            'direction' => $direction->value,
            'amount_minor' => $amountMinor,
            'currency' => $account->currency,
            'account_sequence_number' => $sequence,
            'balance_after' => $balanceAfter,
            'prev_hash' => $prevHash,
            'entry_hash' => $entryHash,
            'created_at' => Carbon::now(),
        ]);

        // The allocator advances so the next entry for this account gets the next number.
        $account->next_sequence_number = $sequence + 1;
        $account->save();
    }

    /**
     * Advance a wallet's projection by a signed delta.
     *
     * The row is locked, so a concurrent posting to the same wallet serialises here. The
     * balance moves by the delta and by nothing else — it is never assigned a number that
     * did not come from a ledger posting.
     */
    private function adjustWallet(int $walletId, int $deltaMinor): void
    {
        $wallet = Wallet::query()->whereKey($walletId)->lockForUpdate()->first();

        if ($wallet === null) {
            throw new \RuntimeException('The wallet for this posting does not exist.');
        }

        $next = $wallet->balance_minor + $deltaMinor;

        if ($next < 0) {
            throw new \RuntimeException('A wallet balance cannot go below zero.');
        }

        $wallet->balance_minor = $next;
        $wallet->save();
    }

    /**
     * The current balance of the account a posting touches.
     *
     * When the account HAS a wallet, the balance is the wallet's projection — the cheap
     * number the interface reads.
     *
     * When the account has NO wallet, there are three cases, and they differ:
     *
     *  - The sandbox `FUNDING` account is an external SOURCE. Its "balance" is the
     *    cumulative amount it has sourced — the sum of the amounts it has been debited
     *    for. It holds no user money, so there is no balance to exhaust; the total is
     *    what the ledger records about it, and it is what `balance_after` carries.
     *  - The `ESCROW` account holds real capital in custody. Its balance is a genuine
     *    running balance: credits in (capital committed) minus debits out (capital
     *    disbursed or returned). That is the capital currently held, and it is what
     *    `balance_after` carries.
     *  - The `PAYOUT` account is an external SINK. Its "balance" is the cumulative
     *    amount it has paid out — the sum of the amounts it has been credited for. Like
     *    `FUNDING`, it holds no user money; the total is what the ledger records.
     */
    private function balanceOf(?int $walletId, LedgerAccount $account): int
    {
        if ($walletId !== null) {
            $wallet = Wallet::query()->whereKey($walletId)->first();

            return $wallet === null ? 0 : (int) $wallet->balance_minor;
        }

        $credited = (int) DB::table('ledger_entries')
            ->where('account_id', $account->id)
            ->where('direction', LedgerDirection::CREDIT->value)
            ->sum('amount_minor');

        $debited = (int) DB::table('ledger_entries')
            ->where('account_id', $account->id)
            ->where('direction', LedgerDirection::DEBIT->value)
            ->sum('amount_minor');

        if ($account->system_role === LedgerSystemRole::ESCROW) {
            // Capital currently held in custody.
            return $credited - $debited;
        }

        if ($account->system_role === LedgerSystemRole::PAYOUT) {
            // An external sink: the cumulative amount it has paid out.
            return $credited;
        }

        // An external source: the cumulative amount it has supplied.
        return $debited;
    }

    /**
     * The SHA-256 of a canonical, pipe-delimited body.
     *
     * **This is a local canonical form, not the specification's M-2 rule** (which is ⚪
     * BLOCKED). The field order is fixed and the separator cannot appear in any field, so
     * the body is unambiguous.
     *
     * @param  list<string>  $fields
     */
    private function hash(array $fields): string
    {
        return hash('sha256', implode('|', $fields));
    }
}
