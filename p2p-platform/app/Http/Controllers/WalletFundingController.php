<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FundingIntent;
use App\Enums\FundingMethod;
use App\Enums\KycStatus;
use App\Http\Requests\WalletFundingStoreRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use App\Services\WalletFundingService;
use App\Support\FundingDetails;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Add Money" flow.
 *
 * ## What this controller does
 *
 *  - shows the Add Money screen with the four methods and the current wallet balance;
 *  - starts a funding attempt from a validated amount and a chosen method;
 *  - shows the payment screen for the selected method;
 *  - settles a funding attempt according to an **intent** the user expressed;
 *  - shows the receipt and the funding history.
 *
 * ## What it does not do
 *
 *  - it never accepts a status, a balance, a credited amount or a reference from the
 *    client — the `intent` field is the only thing the settle route takes;
 *  - it never writes `wallets.balance_minor` itself — the service posts a balanced pair of
 *    ledger entries and the projection follows them;
 *  - it never contacts a gateway, a bank or a card network.
 *
 * Every screen it renders is an ordinary Inertia page. There is no REST JSON API.
 */
class WalletFundingController extends Controller
{
    public function __construct(private readonly WalletFundingService $funding) {}

    /**
     * The "Add Money" screen — amount, method, and the current wallet balance.
     *
     * The user's KYC status travels with the page so the client can explain,
     * in the user's own terms, why the form is not there yet. The status is a
     * *presentation* concern only: the authorisation boundary lives in
     * {@see WalletFundingService::assertEligible()}, which the `store` route
     * goes through. A client that renders the form anyway is still refused.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $balanceMinor = $this->balanceFor($user->id);

        $kycStatus = $this->kycStatusFor($user);

        return Inertia::render('wallet/add-money', [
            'methods' => $this->methods(),
            'balance' => [
                'balanceMinor' => $balanceMinor,
                'currency' => (string) config('p2p-funding.currency', 'BDT'),
            ],
            'limits' => [
                'maxAmountMinor' => (int) config('p2p-funding.max_amount_minor'),
                'dailyMaxAmountMinor' => (int) config('p2p-funding.daily_max_amount_minor'),
            ],
            'banks' => FundingDetails::banks(),
            'testCard' => [
                'number' => (string) config('p2p-funding.test_card.number'),
                'expiry' => (string) config('p2p-funding.test_card.expiry'),
                'cvv' => (string) config('p2p-funding.test_card.cvv'),
                'holder' => (string) config('p2p-funding.test_card.holder'),
            ],
            'environmentLabel' => (string) config('p2p-funding.environment_label'),
            'kyc' => [
                'status' => $kycStatus->value,
                'statusLabel' => $kycStatus->label(),
            ],
        ]);
    }

    /**
     * The user's current KYC status.
     *
     * A user has at most one submission: `kyc_submissions.user_id` is UNIQUE,
     * and a re-submission replaces the rejected row rather than adding a second
     * one. A user who has never submitted has no row at all, which is
     * `NOT_SUBMITTED`.
     */
    private function kycStatusFor(User $user): KycStatus
    {
        $submission = $user->kycSubmission()->first();

        return $submission === null ? KycStatus::NOT_SUBMITTED : $submission->status;
    }

    /**
     * Create a funding attempt and move to its payment screen.
     *
     * The amount arrives as a Taka string and is converted to integer Poisha by the
     * service. The method's inputs are validated and reduced to their minimal storable
     * form.
     */
    public function store(WalletFundingStoreRequest $request): RedirectResponse
    {
        $user = $request->user();

        $method = FundingMethod::from((string) $request->validated('method'));

        $funding = $this->funding->start(
            $user,
            $method,
            (string) $request->validated('amount'),
            $request->fundingPayload(),
            $request->idempotencyKey(),
        );

        return redirect()->route('wallet.fundings.show', ['funding' => $funding->id]);
    }

    /**
     * The payment screen for a pending attempt, and the receipt for a settled one.
     */
    public function show(Request $request, int $funding): Response
    {
        $user = $request->user();

        $record = $this->funding->findFor($user, $funding);

        if ($record === null) {
            abort(404);
        }

        return Inertia::render('wallet/funding', [
            'funding' => $this->fundingProps($record),
            'method' => [
                'value' => $record->method->value,
                'label' => $record->method->label(),
                'description' => $record->method->description(),
                'inputKind' => $record->method->inputKind(),
            ],
            'details' => $record->details ?? [],
            'banks' => FundingDetails::banks(),
            'testCard' => [
                'number' => (string) config('p2p-funding.test_card.number'),
                'expiry' => (string) config('p2p-funding.test_card.expiry'),
                'cvv' => (string) config('p2p-funding.test_card.cvv'),
                'holder' => (string) config('p2p-funding.test_card.holder'),
            ],
            'environmentLabel' => (string) config('p2p-funding.environment_label'),
        ]);
    }

    /**
     * Settle a funding attempt according to the user's intent.
     *
     * The intent is a request; the server decides the outcome. The `intent` field is the
     * only thing this route accepts — no status, no amount, no balance.
     */
    public function settle(Request $request, int $funding): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'intent' => ['required', 'string', Rule::in(FundingIntent::values())],
        ], [
            'intent.in' => 'Choose confirm, fail or cancel.',
        ]);

        $record = $this->funding->findFor($user, $funding);

        if ($record === null) {
            abort(404);
        }

        $settled = $this->funding->settle(
            $user,
            $record,
            FundingIntent::from((string) $validated['intent']),
        );

        return redirect()
            ->route('wallet.fundings.show', ['funding' => $settled->id])
            ->with('success', $this->outcomeMessage($settled));
    }

    /**
     * The funding history — the receipt list for this user's funding attempts.
     */
    public function history(Request $request): Response
    {
        $user = $request->user();

        $fundings = $this->funding->historyFor($user);

        return Inertia::render('wallet/funding-history', [
            'balance' => [
                'balanceMinor' => $this->balanceFor($user->id),
                'currency' => (string) config('p2p-funding.currency', 'BDT'),
            ],
            'fundings' => array_map(
                fn (WalletFunding $funding): array => $this->fundingProps($funding),
                $fundings,
            ),
            'environmentLabel' => (string) config('p2p-funding.environment_label'),
        ]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The four methods, with the labels and hints their screens need.
     *
     * @return list<array<string, string>>
     */
    private function methods(): array
    {
        return array_map(static fn (FundingMethod $method): array => [
            'value' => $method->value,
            'label' => $method->label(),
            'description' => $method->description(),
            'inputKind' => $method->inputKind(),
        ], FundingMethod::cases());
    }

    /**
     * The props describing a funding attempt.
     *
     * @return array<string, mixed>
     */
    private function fundingProps(WalletFunding $funding): array
    {
        return [
            'id' => $funding->id,
            'reference' => $funding->reference,
            'method' => $funding->method->value,
            'methodLabel' => $funding->method->label(),
            'status' => $funding->status->value,
            'statusLabel' => $funding->status->label(),
            'amountMinor' => $funding->amount_minor,
            'currency' => $funding->currency,
            'failureReason' => $funding->failure_reason,
            'settledAt' => $funding->settled_at?->format('d M Y, H:i') ?? '',
            'createdAt' => $funding->created_at->format('d M Y, H:i'),
        ];
    }

    /**
     * The user's current main-wallet balance.
     */
    private function balanceFor(int $userId): int
    {
        $wallet = Wallet::query()
            ->where('owner_user_id', $userId)
            ->first();

        return $wallet === null ? 0 : $wallet->balance_minor;
    }

    /**
     * The message shown after a funding attempt settles.
     */
    private function outcomeMessage(WalletFunding $funding): string
    {
        return match ($funding->status->value) {
            'SUCCEEDED' => 'Payment Successful. The amount has been added to your wallet.',
            'FAILED' => 'Payment Failed. Your wallet balance was not changed.',
            'CANCELLED' => 'Payment Cancelled. Your wallet balance was not changed.',
            default => 'The payment is still awaiting confirmation.',
        };
    }
}
