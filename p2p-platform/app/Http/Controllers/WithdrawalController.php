<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\WithdrawalIntent;
use App\Enums\WithdrawalMethod;
use App\Models\Wallet;
use App\Models\WalletWithdrawal;
use App\Services\WalletProvisioningService;
use App\Services\WithdrawalService;
use App\Support\WithdrawalDetails;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Withdraw Money" flow.
 *
 * ## What this controller does
 *
 *  - shows the Withdraw Money screen with the three methods and the current balance;
 *  - starts a withdrawal attempt from a validated amount and a chosen method;
 *  - shows the payout screen for the selected method;
 *  - settles a withdrawal according to an **intent** the user expressed;
 *  - shows the receipt list.
 *
 * ## What it does not do
 *
 *  - it never accepts a status, a balance, a debited amount or a reference from the
 *    client — the `intent` field is the only thing the settle route takes;
 *  - it never writes `wallets.balance_minor` itself — the service posts a balanced pair
 *    of ledger entries and the projection follows them;
 *  - it never contacts bKash, Nagad or a bank. **No real gateway API is called and no
 *    credential is ever collected.** The payout is simulated exactly like "Add Money".
 *
 * Every screen it renders is an ordinary Inertia page. There is no REST JSON API.
 */
class WithdrawalController extends Controller
{
    public function __construct(
        private readonly WithdrawalService $withdrawals,
        private readonly WalletProvisioningService $wallets,
    ) {}

    /**
     * The "Withdraw Money" screen — amount, method, and the current wallet balance.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('wallet/withdraw', [
            'methods' => array_map(static fn ($method): array => [
                'value' => $method->value,
                'label' => $method->label(),
                'description' => $method->description(),
                'inputKind' => $method->inputKind(),
            ], WithdrawalMethod::cases()),
            'balance' => [
                'balanceMinor' => $this->balanceFor($user->id),
                'availableMinor' => $this->availableFor($user->id),
                'currency' => 'BDT',
            ],
            'limits' => [
                'maxAmountMinor' => (int) config('p2p-lending.max_withdrawal_minor'),
            ],
            'banks' => WithdrawalDetails::banks(),
            'kycApproved' => $this->wallets->isEligible($user),
            'environmentLabel' => (string) config('p2p-lending.environment_label'),
        ]);
    }

    /**
     * Create a withdrawal attempt and move to its payout screen.
     *
     * The amount arrives as a Taka string and is converted to integer Poisha by the
     * service. The destination is reduced to its minimal storable form — a masked label,
     * never a credential.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::in(WithdrawalMethod::values())],
            'amount' => ['required', 'string', 'max:20'],
            'mobile_number' => ['nullable', 'string', 'max:32'],
            'bank' => ['nullable', 'string', 'max:64'],
            'account_number' => ['nullable', 'string', 'max:64'],
            'account_holder' => ['nullable', 'string', 'max:128'],
        ], [
            'method.in' => 'Choose bKash, Nagad or a bank transfer.',
            'amount.required' => 'Enter the amount you want to withdraw.',
        ]);

        $method = WithdrawalMethod::from((string) $validated['method']);

        $withdrawal = $this->withdrawals->start(
            $user,
            $method,
            (string) $validated['amount'],
            $this->detailsFrom($validated),
            $this->idempotencyKey($request),
        );

        return redirect()->route('wallet.withdrawals.show', ['withdrawal' => $withdrawal->id]);
    }

    /**
     * The payout screen for a pending attempt, and the receipt for a settled one.
     */
    public function show(Request $request, int $withdrawal): Response
    {
        $user = $request->user();

        $record = $this->withdrawals->findFor($user, $withdrawal);

        if ($record === null) {
            abort(404);
        }

        return Inertia::render('wallet/withdrawal', [
            'withdrawal' => $this->withdrawalProps($record),
            'method' => [
                'value' => $record->method->value,
                'label' => $record->method->label(),
                'description' => $record->method->description(),
                'inputKind' => $record->method->inputKind(),
            ],
            'details' => $record->details ?? [],
            'environmentLabel' => (string) config('p2p-lending.environment_label'),
        ]);
    }

    /**
     * Settle a withdrawal according to the user's intent.
     *
     * The intent is a request; the server decides the outcome. The `intent` field is the
     * only thing this route accepts — no status, no amount, no balance.
     */
    public function settle(Request $request, int $withdrawal): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'intent' => ['required', 'string', Rule::in(WithdrawalIntent::values())],
        ], [
            'intent.in' => 'Choose confirm, fail or cancel.',
        ]);

        $record = $this->withdrawals->findFor($user, $withdrawal);

        if ($record === null) {
            abort(404);
        }

        $settled = $this->withdrawals->settle(
            $user,
            $record,
            WithdrawalIntent::from((string) $validated['intent']),
        );

        return redirect()
            ->route('wallet.withdrawals.show', ['withdrawal' => $settled->id])
            ->with('success', $this->outcomeMessage($settled));
    }

    /**
     * The receipt list — this user's withdrawal attempts.
     */
    public function history(Request $request): Response
    {
        $user = $request->user();

        $withdrawals = $this->withdrawals->historyFor($user);

        return Inertia::render('wallet/withdrawal-history', [
            'balance' => [
                'balanceMinor' => $this->balanceFor($user->id),
                'currency' => 'BDT',
            ],
            'withdrawals' => array_map(
                fn (WalletWithdrawal $withdrawal): array => $this->withdrawalProps($withdrawal),
                $withdrawals,
            ),
            'environmentLabel' => (string) config('p2p-lending.environment_label'),
        ]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The destination inputs, in the shape the service reduces.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function detailsFrom(array $validated): array
    {
        return array_filter([
            'mobile_number' => $validated['mobile_number'] ?? null,
            'bank' => $validated['bank'] ?? null,
            'account_number' => $validated['account_number'] ?? null,
            'account_holder' => $validated['account_holder'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * The props describing a withdrawal attempt.
     *
     * @return array<string, mixed>
     */
    private function withdrawalProps(WalletWithdrawal $withdrawal): array
    {
        return [
            'id' => $withdrawal->id,
            'reference' => $withdrawal->reference,
            'method' => $withdrawal->method->value,
            'methodLabel' => $withdrawal->method->label(),
            'status' => $withdrawal->status->value,
            'statusLabel' => $withdrawal->status->label(),
            'amountMinor' => $withdrawal->amount_minor,
            'currency' => $withdrawal->currency,
            'failureReason' => $withdrawal->failure_reason,
            'settledAt' => $withdrawal->settled_at?->format('d M Y, H:i') ?? '',
            'createdAt' => $withdrawal->created_at->format('d M Y, H:i'),
        ];
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
     * The amount the user may actually withdraw.
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
     * The message shown after a withdrawal settles.
     */
    private function outcomeMessage(WalletWithdrawal $withdrawal): string
    {
        return match ($withdrawal->status->value) {
            'SUCCEEDED' => 'Payment Successful. The amount has been sent to your account.',
            'FAILED' => 'Payment Failed. Your wallet balance was not changed.',
            'CANCELLED' => 'Payment Cancelled. Your wallet balance was not changed.',
            default => 'The payout is still awaiting confirmation.',
        };
    }
}
