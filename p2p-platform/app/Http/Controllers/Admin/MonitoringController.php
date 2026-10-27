<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The administrator's transaction monitoring view.
 */
class MonitoringController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $query = Transaction::query()->with('senderAccount.owner');

        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('reference', 'like', '%'.$search.'%')
                    ->orWhere('counterparty_name', 'like', '%'.$search.'%');
            });
        }

        $status = (string) $request->query('status', '');

        if ($status !== '' && $status !== 'ALL') {
            $query->where('status', $status);
        }

        $transactions = $query->latest()->paginate(15)->withQueryString();

        return Inertia::render('admin/monitoring', [
            'transactions' => $transactions->through(fn (Transaction $t): array => [
                'id' => $t->id,
                'reference' => $t->reference,
                'accountHolder' => $t->senderAccount->owner->name ?? '—',
                'counterpartyName' => $t->counterparty_name,
                'amountMinor' => $t->amount_minor,
                'direction' => $t->direction->value,
                'status' => $t->status->value,
                'statusLabel' => $t->status->label(),
                'riskLevel' => $t->risk_level->value,
                'method' => $t->method,
                'createdAt' => $t->created_at?->format('d M Y, H:i') ?? '',
            ]),
            'filters' => [
                'search' => $search,
                'status' => $status === '' ? 'ALL' : $status,
            ],
            'statuses' => TransactionStatus::values(),
        ]);
    }
}
