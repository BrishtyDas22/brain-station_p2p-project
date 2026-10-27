<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The micro-lending tables: requests, investments, installments and withdrawals.
 *
 * ## Normative source
 *
 * `../PRD_SRS_Document.pdf` — Epic 3 (Loan Marketplace & Crowdfunded Escrow) and
 * Epic 4 (Repayment Engine & Default Management), plus the "Core Architectural
 * Mechanisms" section.
 *
 * ## The money model
 *
 * No table here holds a balance. Money lives in `wallets` (a projection) and is backed
 * by `ledger_entries` (the authority), exactly as everywhere else in this system.
 *
 * The flow the PRD describes, expressed in ledger terms:
 *
 *   1. A borrower lists a request. No money moves.
 *   2. A lender commits capital. The lender's wallet is DEBITED and the escrow account
 *      is CREDITED by the same amount — the capital is now `ESCROW_LOCKED`, in custody.
 *   3. At 100% funding the escrow account is DEBITED and the borrower's wallet is
 *      CREDITED by the same amount — the principal is disbursed.
 *   4. Each installment DEBITS the borrower's wallet and CREDITS each lender in
 *      proportion to their investment, split into principal and interest.
 *
 * Every one of those movements is a balanced pair of `ledger_entries` under one
 * `transactions` row, so `SUM(DEBIT) = SUM(CREDIT)` holds per transaction and book-wide.
 *
 * ## The migration is purely additive
 *
 * It creates four tables. It adds no trigger, drops nothing, and rewrites no existing
 * row. Every existing user, wallet, transaction and ledger entry is untouched.
 *
 * ## Money is integer Poisha
 *
 * 1 BDT = 100 Poisha. Every amount column is `BIGINT UNSIGNED`, so a negative amount is
 * physically unrepresentable, and a raw-DDL CHECK forbids zero. No float or decimal
 * column exists anywhere in this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        // -------------------------------------------------------------------
        // loan_requests — the marketplace listing.
        // -------------------------------------------------------------------
        Schema::create('loan_requests', function (Blueprint $table): void {
            $table->id();

            // Server-generated, unique, never accepted from a client.
            $table->string('reference', 32);

            // The borrower.
            $table->unsignedBigInteger('borrower_user_id');

            // The principal the borrower is asking for. Integer Poisha.
            $table->unsignedBigInteger('principal_minor');

            $table->char('currency', 3)->default('BDT');

            // The interest rate the borrower has agreed to, as a basis-point integer
            // (100 bps = 1.00%). Stored as an integer so no float ever touches a
            // financial rate. The PRD requires the rate to be *displayed* to lenders
            // but does not define how it is set, so the value is a per-request field
            // the borrower supplies and it is stored exactly as given.
            $table->unsignedInteger('interest_rate_bps');

            // The number of monthly installments. The PRD's repayment model is EMI
            // ("Repays scheduled monthly or weekly installments (Equated Monthly
            // Installments - EMI)"), and §4.1 of Epic 4 describes a daily batch on
            // due dates, so the unit is the MONTH.
            $table->unsignedSmallInteger('tenure_months');

            // Why the borrower needs the money. Free text, bounded length.
            $table->string('purpose', 255);

            // The lifecycle. `MARKETPLACE_LISTED` is the PRD's own name for a live
            // listing; `FUNDED` is the PRD's `DISBURSED` state (the PRD uses the two
            // words for the same moment — funds fully committed and released).
            $table->enum('status', [
                'MARKETPLACE_LISTED',
                'DISBURSED',
                'REPAYING',
                'REPAID',
                'DEFAULTED',
                'CANCELLED',
            ])->default('MARKETPLACE_LISTED');

            // How much has been committed so far, and how many lenders have committed.
            // These are PROJECTIONS of the `loan_investments` rows, maintained inside
            // the same transaction that writes the investment — the investment rows
            // remain the authority.
            $table->unsignedBigInteger('funded_minor')->default(0);
            $table->unsignedInteger('investor_count')->default(0);

            // When the listing was funded and disbursed.
            $table->dateTime('funded_at', 6)->nullable();

            // When the borrower finished repaying.
            $table->dateTime('repaid_at', 6)->nullable();

            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique('reference', 'uq_loan_requests_reference');

            // A borrower may have several listings, but the live-scan and the
            // "one active loan" rule both need a fast lookup by borrower.
            $table->index('borrower_user_id', 'ix_loan_requests_borrower');
            $table->index('status', 'ix_loan_requests_status');

            // No ON DELETE / ON UPDATE referential action — the house rule.
            $table->foreign('borrower_user_id')
                ->references('id')
                ->on('users');
        });

        // -------------------------------------------------------------------
        // loan_investments — one lender's commitment to one listing.
        // -------------------------------------------------------------------
        Schema::create('loan_investments', function (Blueprint $table): void {
            $table->id();

            $table->string('reference', 32);

            $table->unsignedBigInteger('loan_request_id');

            // The lender. This is the user whose wallet was debited.
            $table->unsignedBigInteger('lender_user_id');

            // How much this lender committed. Integer Poisha.
            $table->unsignedBigInteger('amount_minor');

            // The ledger transaction the escrow-locking entry pair belongs to.
            $table->unsignedBigInteger('transaction_id');

            // The idempotency key. UNIQUE, so a replayed funding request cannot create
            // a second investment for the same lender on the same loan.
            $table->string('idempotency_key', 64);

            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique('reference', 'uq_loan_investments_reference');
            $table->unique('idempotency_key', 'uq_loan_investments_idempotency_key');

            // The lender may not fund the same listing twice. This is the database's
            // half of the "duplicate funding" guard.
            $table->unique(
                ['loan_request_id', 'lender_user_id'],
                'uq_loan_investments_loan_lender'
            );

            $table->index('lender_user_id', 'ix_loan_investments_lender');
            $table->index('transaction_id', 'ix_loan_investments_transaction');

            $table->foreign('loan_request_id')
                ->references('id')
                ->on('loan_requests');

            $table->foreign('lender_user_id')
                ->references('id')
                ->on('users');

            $table->foreign('transaction_id')
                ->references('id')
                ->on('transactions');
        });

        // -------------------------------------------------------------------
        // loan_installments — the repayment schedule.
        // -------------------------------------------------------------------
        Schema::create('loan_installments', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('loan_request_id');

            // Which installment this is, counting from 1.
            $table->unsignedSmallInteger('sequence');

            // The due date.
            $table->dateTime('due_date', 6);

            // The total installment: principal share + interest share.
            $table->unsignedBigInteger('amount_minor');

            // The split. `principal_minor + interest_minor = amount_minor` is enforced
            // by a raw-DDL CHECK below.
            $table->unsignedBigInteger('principal_minor');
            $table->unsignedBigInteger('interest_minor');

            // PENDING until collected, then PAID. `GRACE_PERIOD` is the PRD's own
            // 3-day state after a failed collection.
            $table->enum('status', [
                'PENDING',
                'PAID',
                'GRACE_PERIOD',
                'DEFAULTED',
            ])->default('PENDING');

            // The ledger transaction the collection entry pair belongs to.
            $table->unsignedBigInteger('transaction_id')->nullable();

            // The penalty accrued on this installment so far (0.5% per day after the
            // PRD's 3-day grace period). Integer Poisha.
            $table->unsignedBigInteger('penalty_minor')->default(0);

            $table->dateTime('paid_at', 6)->nullable();
            $table->dateTime('grace_started_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            // One row per (loan, sequence). The schedule cannot be duplicated.
            $table->unique(
                ['loan_request_id', 'sequence'],
                'uq_loan_installments_loan_sequence'
            );

            $table->index('status', 'ix_loan_installments_status');
            $table->index('due_date', 'ix_loan_installments_due_date');

            $table->foreign('loan_request_id')
                ->references('id')
                ->on('loan_requests');

            $table->foreign('transaction_id')
                ->references('id')
                ->on('transactions');
        });

        // -------------------------------------------------------------------
        // wallet_withdrawals — the simulated payout of wallet funds.
        // -------------------------------------------------------------------
        Schema::create('wallet_withdrawals', function (Blueprint $table): void {
            $table->id();

            $table->string('reference', 32);

            $table->unsignedBigInteger('owner_user_id');

            // The simulated payout instrument. No card is accepted here: the PRD's
            // withdrawal destinations are "Bank / Mobile Financial Services".
            $table->enum('method', ['BKASH', 'NAGAD', 'BANK_TRANSFER']);

            $table->enum('status', ['PENDING', 'SUCCEEDED', 'FAILED', 'CANCELLED'])
                ->default('PENDING');

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('BDT');

            $table->string('failure_reason', 120)->nullable();

            // The minimal, storable form of the destination. Never a real credential:
            // only a masked account label and a bank name are kept.
            $table->json('details')->nullable();

            $table->unsignedBigInteger('transaction_id')->nullable();

            $table->string('idempotency_key', 64);

            $table->dateTime('settled_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique('reference', 'uq_wallet_withdrawals_reference');
            $table->unique('idempotency_key', 'uq_wallet_withdrawals_idempotency_key');

            $table->index('owner_user_id', 'ix_wallet_withdrawals_owner');
            $table->index('transaction_id', 'ix_wallet_withdrawals_transaction');

            $table->foreign('owner_user_id')
                ->references('id')
                ->on('users');

            $table->foreign('transaction_id')
                ->references('id')
                ->on('transactions');
        });

        // -------------------------------------------------------------------
        // CHECK constraints — raw DDL, because Laravel's builder cannot express them.
        // -------------------------------------------------------------------

        // A principal of zero is not a loan. The upper bound is the PRD's own range
        // (BDT 50,000) expressed in Poisha, so the rule lives in the data layer and
        // not only in the controller.
        DB::statement(
            'ALTER TABLE `loan_requests`
              ADD CONSTRAINT `ck_loan_requests_principal` CHECK (
                  `principal_minor` > 0 AND `principal_minor` <= 5000000
              )'
        );

        DB::statement(
            "ALTER TABLE `loan_requests`
              ADD CONSTRAINT `ck_loan_requests_currency` CHECK (`currency` = 'BDT')"
        );

        // The funding projection can never exceed the principal, and the committed
        // amount can never be negative.
        DB::statement(
            'ALTER TABLE `loan_requests`
              ADD CONSTRAINT `ck_loan_requests_funded` CHECK (
                  `funded_minor` <= `principal_minor`
              )'
        );

        DB::statement(
            'ALTER TABLE `loan_investments`
              ADD CONSTRAINT `ck_loan_investments_amount` CHECK (`amount_minor` > 0)'
        );

        // The split must add up. This is the invariant that makes an installment
        // internally consistent at the data layer.
        DB::statement(
            'ALTER TABLE `loan_installments`
              ADD CONSTRAINT `ck_loan_installments_split` CHECK (
                  `principal_minor` + `interest_minor` = `amount_minor`
              )'
        );

        DB::statement(
            'ALTER TABLE `loan_installments`
              ADD CONSTRAINT `ck_loan_installments_amount` CHECK (`amount_minor` > 0)'
        );

        DB::statement(
            "ALTER TABLE `wallet_withdrawals`
              ADD CONSTRAINT `ck_wallet_withdrawals_currency` CHECK (`currency` = 'BDT')"
        );

        DB::statement(
            'ALTER TABLE `wallet_withdrawals`
              ADD CONSTRAINT `ck_wallet_withdrawals_amount` CHECK (`amount_minor` > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_withdrawals');
        Schema::dropIfExists('loan_installments');
        Schema::dropIfExists('loan_investments');
        Schema::dropIfExists('loan_requests');
    }
};
