<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LoanStatus;
use App\Models\LoanInstallment;
use App\Models\LoanInvestment;
use App\Models\LoanRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LoanService;
use App\Services\RepaymentService;
use App\Services\WalletProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The loan marketplace and the borrower's / lender's loan screens.
 *
 * ## What this controller does
 *
 *  - shows the marketplace of open loan listings a lender may fund;
 *  - creates a borrower's loan request (no money moves);
 *  - shows one listing with its funding progress, its lenders and its schedule;
 *  - commits a lender's capital to a listing — from the lender's **real** wallet;
 *  - shows the borrower's own loans and the lender's investments;
 *  - triggers the collection of an installment from the borrower's wallet.
 *
 * ## What it does not do
 *
 *  - it never accepts a status, a balance, a funded amount or a reference from the
 *    client — only an amount, a tenure, a rate and a purpose;
 *  - it never writes `wallets.balance_minor` — the service posts a balanced pair of
 *    ledger entries and the projection follows them;
 *  - it never contacts a gateway, a bank or a card network. **Loan funding uses only
 *    the lender's existing wallet balance**; there is no external payment step.
 *
 * Every screen it renders is an ordinary Inertia page. There is no REST JSON API.
 */
class LoanController extends Controller
{
    public function __construct(
        private readonly LoanService $loans,
        private readonly RepaymentService $repayments,
        private readonly WalletProvisioningService $wallets,
    ) {}

    // -----------------------------------------------------------------------
    // The marketplace
    // -----------------------------------------------------------------------

    /**
     * The marketplace — every listing that is open for funding.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $listings = $this->loans->marketplaceFor($user);

        return Inertia::render('loans/marketplace', [
            'listings' => array_map(
                fn (LoanRequest $listing): array => $this->listingProps($listing),
                $listings,
            ),
            'wallet' => [
                'balanceMinor' => $this->balanceFor($user->id),
                'availableMinor' => $this->availableFor($user->id),
            ],
            'limits' => [
                'minPrincipalMinor' => (int) config('p2p-lending.min_principal_minor'),
                'maxPrincipalMinor' => (int) config('p2p-lending.max_principal_minor'),
            ],
            'canRequest' => $this->wallets->isEligible($user),
        ]);
    }

    /**
     * Create a loan request. No money moves — this only publishes a listing.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'principal' => ['required', 'string', 'max:20'],
            'tenure_months' => ['required', 'integer', 'min:1', 'max:60'],
            'interest_rate_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'purpose' => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'principal.required' => 'Enter the amount you want to borrow.',
            'tenure_months.min' => 'Choose a repayment tenure of at least one month.',
            'interest_rate_bps.max' => 'The interest rate cannot exceed 100%.',
            'purpose.min' => 'Describe the purpose of the loan in at least 10 characters.',
        ]);

        $loanRequest = $this->loans->createRequest(
            $user,
            (string) $validated['principal'],
            (int) $validated['tenure_months'],
            (int) $validated['interest_rate_bps'],
            (string) $validated['purpose'],
        );

        return redirect()
            ->route('loans.show', ['loan' => $loanRequest->id])
            ->with('success', 'Your loan request is live on the marketplace.');
    }

    /**
     * One listing, with its funding progress, its lenders and its schedule.
     */
    public function show(Request $request, int $loan): Response
    {
        $user = $request->user();

        $loanRequest = $this->loans->findRequest($loan);

        if ($loanRequest === null) {
            abort(404);
        }

        $investments = LoanInvestment::query()
            ->where('loan_request_id', $loanRequest->id)
            ->orderBy('id')
            ->get();

        $installments = LoanInstallment::query()
            ->where('loan_request_id', $loanRequest->id)
            ->orderBy('sequence')
            ->get();

        $isBorrower = $loanRequest->borrower_user_id === $user->id;

        $alreadyInvested = $investments
            ->contains(fn (LoanInvestment $investment): bool => $investment->lender_user_id === $user->id);

        return Inertia::render('loans/show', [
            'loan' => $this->listingProps($loanRequest),
            'borrower' => [
                'name' => $this->borrowerName($loanRequest->borrower_user_id),
            ],
            'investments' => array_map(
                fn (LoanInvestment $investment): array => $this->investmentProps($investment),
                $investments->all(),
            ),
            'installments' => array_map(
                fn (LoanInstallment $installment): array => $this->installmentProps($installment),
                $installments->all(),
            ),
            'viewer' => [
                'isBorrower' => $isBorrower,
                'alreadyInvested' => $alreadyInvested,
                'availableMinor' => $this->availableFor($user->id),
                'isApproved' => $this->wallets->isEligible($user),
            ],
        ]);
    }

    /**
     * Commit the lender's capital to a listing.
     *
     * The capital comes from the lender's **real** wallet balance. There is no external
     * gateway in this path — no money leaves the platform; it moves from one internal
     * wallet into escrow custody.
     */
    public function fund(Request $request, int $loan): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'amount' => ['required', 'string', 'max:20'],
        ], [
            'amount.required' => 'Enter how much you want to commit.',
        ]);

        $loanRequest = $this->loans->findRequest($loan);

        if ($loanRequest === null) {
            abort(404);
        }

        $investment = $this->loans->fund(
            $user,
            $loanRequest,
            (string) $validated['amount'],
            $this->idempotencyKey($request),
        );

        $loanRequest->refresh();

        $message = $loanRequest->status === LoanStatus::DISBURSED
            ? 'The loan is fully funded. The principal has been disbursed to the borrower.'
            : 'Your capital is locked in escrow until this loan is fully funded.';

        return redirect()
            ->route('loans.show', ['loan' => $loanRequest->id])
            ->with('success', $message)
            ->with('investmentId', $investment->id);
    }

    // -----------------------------------------------------------------------
    // The borrower's loans and the lender's investments
    // -----------------------------------------------------------------------

    /**
     * The borrower's own loan requests, with their funding progress.
     */
    public function myLoans(Request $request): Response
    {
        $user = $request->user();

        $requests = $this->loans->requestsFor($user);

        return Inertia::render('loans/my-loans', [
            'loans' => array_map(
                fn (LoanRequest $loan): array => $this->listingProps($loan),
                $requests,
            ),
            'canRequest' => $this->wallets->isEligible($user),
            'limits' => [
                'minPrincipalMinor' => (int) config('p2p-lending.min_principal_minor'),
                'maxPrincipalMinor' => (int) config('p2p-lending.max_principal_minor'),
                'minTenureMonths' => (int) config('p2p-lending.min_tenure_months'),
                'maxTenureMonths' => (int) config('p2p-lending.max_tenure_months'),
                'maxInterestRateBps' => (int) config('p2p-lending.max_interest_rate_bps'),
                'gracePeriodDays' => (int) config('p2p-lending.grace_period_days'),
                'latePenaltyBps' => (int) config('p2p-lending.late_penalty_bps'),
            ],
        ]);
    }

    /**
     * The lender's own commitments, with the loans they sit on.
     */
    public function myInvestments(Request $request): Response
    {
        $user = $request->user();

        $investments = $this->loans->investmentsFor($user);

        return Inertia::render('loans/my-investments', [
            'investments' => array_map(
                fn (LoanInvestment $investment): array => $this->investmentProps($investment),
                $investments,
            ),
            'totals' => [
                'committedMinor' => (int) array_sum(array_map(
                    static fn (LoanInvestment $investment): int => $investment->amount_minor,
                    $investments,
                )),
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // Repayment
    // -----------------------------------------------------------------------

    /**
     * Collect one installment from the borrower's wallet and pay the lenders.
     *
     * The borrower may trigger their own installment. The collection is idempotent:
     * collecting an installment twice moves no extra money.
     */
    public function collect(Request $request, int $installment): RedirectResponse
    {
        $user = $request->user();

        $record = LoanInstallment::query()->whereKey($installment)->first();

        if ($record === null) {
            abort(404);
        }

        $loanRequest = LoanRequest::query()->whereKey($record->loan_request_id)->first();

        if ($loanRequest === null) {
            abort(404);
        }

        if ($loanRequest->borrower_user_id !== $user->id) {
            throw ValidationException::withMessages([
                'installment' => 'Only the borrower can make a repayment.',
            ]);
        }

        $collected = $this->repayments->collect($record, $user);

        return redirect()
            ->route('loans.show', ['loan' => $loanRequest->id])
            ->with('success', $this->collectionMessage($collected));
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The props describing a loan listing.
     *
     * @return array<string, mixed>
     */
    private function listingProps(LoanRequest $loan): array
    {
        return [
            'id' => $loan->id,
            'reference' => $loan->reference,
            'principalMinor' => $loan->principal_minor,
            'fundedMinor' => $loan->funded_minor,
            'remainingMinor' => $loan->remainingMinor(),
            'fundingPercent' => $loan->fundingPercent(),
            'currency' => $loan->currency,
            'interestRateBps' => $loan->interest_rate_bps,
            'interestRateLabel' => $this->rateLabel($loan->interest_rate_bps),
            'tenureMonths' => $loan->tenure_months,
            'purpose' => $loan->purpose,
            'status' => $loan->status->value,
            'statusLabel' => $loan->status->label(),
            'investorCount' => $loan->investor_count,
            'createdAt' => $loan->created_at->format('d M Y'),
            'fundedAt' => $loan->funded_at?->format('d M Y') ?? '',
            'repaidAt' => $loan->repaid_at?->format('d M Y') ?? '',
        ];
    }

    /**
     * The props describing one lender commitment.
     *
     * @return array<string, mixed>
     */
    private function investmentProps(LoanInvestment $investment): array
    {
        $loan = LoanRequest::query()->whereKey($investment->loan_request_id)->first();

        return [
            'id' => $investment->id,
            'reference' => $investment->reference,
            'loanId' => $investment->loan_request_id,
            'loanReference' => $loan->reference ?? '',
            'loanStatus' => $loan->status->value ?? '',
            'loanStatusLabel' => $loan->status->label(),
            'loanPrincipalMinor' => $loan->principal_minor ?? 0,
            'lenderName' => $this->lenderName($investment->lender_user_id),
            'amountMinor' => $investment->amount_minor,
            'committedAt' => $investment->created_at->format('d M Y'),
        ];
    }

    /**
     * The props describing one installment.
     *
     * @return array<string, mixed>
     */
    private function installmentProps(LoanInstallment $installment): array
    {
        return [
            'id' => $installment->id,
            'sequence' => $installment->sequence,
            'dueDate' => $installment->due_date->format('d M Y'),
            'amountMinor' => $installment->amount_minor,
            'principalMinor' => $installment->principal_minor,
            'interestMinor' => $installment->interest_minor,
            'penaltyMinor' => $installment->penalty_minor,
            'status' => $installment->status->value,
            'statusLabel' => $installment->status->label(),
            'paidAt' => $installment->paid_at?->format('d M Y') ?? '',
        ];
    }

    /**
     * The borrower's display name.
     */
    private function borrowerName(int $borrowerUserId): string
    {
        $borrower = User::query()->whereKey($borrowerUserId)->first();

        return $borrower->name ?? 'Borrower';
    }

    /**
     * The lender's display name.
     */
    private function lenderName(int $lenderUserId): string
    {
        $lender = User::query()->whereKey($lenderUserId)->first();

        return $lender->name ?? 'Lender';
    }

    /**
     * The user's wallet balance.
     */
    private function balanceFor(int $userId): int
    {
        $wallet = Wallet::query()->where('owner_user_id', $userId)->first();

        return $wallet === null ? 0 : $wallet->balance_minor;
    }

    /**
     * The amount the user may actually commit — the balance minus what is reserved.
     */
    private function availableFor(int $userId): int
    {
        $wallet = Wallet::query()->where('owner_user_id', $userId)->first();

        return $wallet === null ? 0 : $wallet->availableMinor();
    }

    /**
     * The client's idempotency key, if it sent one.
     */
    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        return is_string($key) && trim($key) !== '' ? $key : null;
    }

    /**
     * A basis-point rate, written the way a person would say it.
     */
    private function rateLabel(int $bps): string
    {
        $percent = $bps / 100;

        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'%';
    }

    /**
     * The message shown after an installment is collected.
     */
    private function collectionMessage(LoanInstallment $installment): string
    {
        return match ($installment->status->value) {
            'PAID' => sprintf('Installment %d has been paid and your lenders have been credited.', $installment->sequence),
            'GRACE_PERIOD' => 'Your balance could not cover this installment. It is now in its 3-day grace period.',
            default => 'This installment could not be collected.',
        };
    }
}
