<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Models\LedgerAccount;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's transaction history and detail views.
 *
 * Reads only what the requesting user is a party to. A user never sees
 * another user's transfers.
 */
class TransactionController extends Controller
{
    /**
     * The transaction history, with search and filters.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $account = LedgerAccount::query()->where('owner_user_id', $user->id)->first();

        $accountId = $account === null ? 0 : $account->id;

        $query = Transaction::query()
            ->where(function ($builder) use ($accountId): void {
                $builder->where('sender_account_id', $accountId)
                    ->orWhere('recipient_account_id', $accountId);
            });

        // Search: reference or counterparty name.
        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('reference', 'like', '%'.$search.'%')
                    ->orWhere('counterparty_name', 'like', '%'.$search.'%')
                    ->orWhere('counterparty_account', 'like', '%'.$search.'%');
            });
        }

        // Filter: status.
        $status = (string) $request->query('status', '');

        if ($status !== '' && $status !== 'ALL') {
            $query->where('status', $status);
        }

        // Filter: direction.
        $direction = (string) $request->query('direction', '');

        if ($direction !== '' && $direction !== 'ALL') {
            $query->where('direction', $direction);
        }

        $transactions = $query->latest()->paginate(15)->withQueryString();

        return Inertia::render('transactions/history', [
            'transactions' => $transactions->through(fn (Transaction $t): array => [
                'id' => $t->id,
                'reference' => $t->reference,
                'counterpartyName' => $t->counterparty_name,
                'counterpartyAccount' => $t->counterparty_account,
                'amountMinor' => $t->amount_minor,
                'feeMinor' => $t->fee_minor,
                'currency' => $t->currency,
                'direction' => $t->direction->value,
                'status' => $t->status->value,
                'statusLabel' => $t->status->label(),
                'riskLevel' => $t->risk_level->value,
                'method' => $t->method,
                'note' => $t->note,
                'kind' => $this->kindOf($t),
                'kindLabel' => $this->kindLabelOf($t),
                'createdAt' => $t->created_at?->format('d M Y, H:i') ?? '',
                'chainClosedAt' => $t->chain_closed_at?->format('d M Y, H:i'),
            ]),
            'filters' => [
                'search' => $search,
                'status' => $status === '' ? 'ALL' : $status,
                'direction' => $direction === '' ? 'ALL' : $direction,
            ],
            'statuses' => TransactionStatus::values(),
        ]);
    }

    /**
     * A single transaction's detail.
     */
    public function show(Request $request, Transaction $transaction): Response
    {
        $user = $request->user();
        $account = LedgerAccount::query()->where('owner_user_id', $user->id)->first();

        $isParty = $transaction->sender_account_id === $account?->id
            || $transaction->recipient_account_id === $account?->id;

        abort_unless($isParty, 403, 'This transfer belongs to another account.');

        return Inertia::render('transactions/show', [
            'transaction' => [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'counterpartyName' => $transaction->counterparty_name,
                'counterpartyAccount' => $transaction->counterparty_account,
                'amountMinor' => $transaction->amount_minor,
                'feeMinor' => $transaction->fee_minor,
                'currency' => $transaction->currency,
                'direction' => $transaction->direction->value,
                'status' => $transaction->status->value,
                'statusLabel' => $transaction->status->label(),
                'riskLevel' => $transaction->risk_level->value,
                'method' => $transaction->method,
                'note' => $transaction->note,
                'kind' => $this->kindOf($transaction),
                'kindLabel' => $this->kindLabelOf($transaction),
                'createdAt' => $transaction->created_at?->format('d M Y, H:i') ?? '',
                'chainClosedAt' => $transaction->chain_closed_at?->format('d M Y, H:i'),
                'idempotencyKey' => $transaction->idempotency_key,
            ],
        ]);
    }

    /**
     * What kind of movement this transaction records.
     *
     * A funding transaction is an "Add Money" credit, not a peer-to-peer transfer, and
     * the history says so.
     */
    private function kindOf(Transaction $transaction): string
    {
        return $transaction->note === 'Add Money — sandbox gateway funding'
            ? 'ADD_MONEY'
            : 'TRANSFER';
    }

    /**
     * The label shown for this kind of movement.
     */
    private function kindLabelOf(Transaction $transaction): string
    {
        return $this->kindOf($transaction) === 'ADD_MONEY'
            ? 'Add Money'
            : 'Transfer';
    }
}
