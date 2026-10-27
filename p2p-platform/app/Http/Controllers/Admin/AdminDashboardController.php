<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\KycStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditRecord;
use App\Models\Dispute;
use App\Models\KycSubmission;
use App\Models\Reversal;
use App\Models\SettlementBatch;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The administrator's operational overview.
 */
class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $users = User::query()->count();
        $admins = User::query()->where('role', 'ADMIN')->count();
        $kycPending = KycSubmission::query()
            ->whereIn('status', [KycStatus::PENDING->value, KycStatus::IN_REVIEW->value])
            ->count();
        $kycApproved = KycSubmission::query()->where('status', KycStatus::APPROVED->value)->count();
        $transactions = Transaction::query()->count();
        $volume = (int) Transaction::query()
            ->whereNotIn('status', [TransactionStatus::FAILED->value])
            ->sum('amount_minor');
        $underReview = Transaction::query()->where('status', TransactionStatus::UNDER_REVIEW->value)->count();
        $reversals = Reversal::query()->count();
        $disputes = Dispute::query()->whereIn('status', ['OPEN', 'IN_REVIEW'])->count();
        $batches = SettlementBatch::query()->count();

        return Inertia::render('admin/dashboard', [
            'stats' => [
                'users' => $users,
                'admins' => $admins,
                'kycPending' => $kycPending,
                'kycApproved' => $kycApproved,
                'transactions' => $transactions,
                'volumeMinor' => $volume,
                'underReview' => $underReview,
                'reversals' => $reversals,
                'disputes' => $disputes,
                'settlementBatches' => $batches,
            ],
            'recentUsers' => User::query()->latest()->limit(6)->get()->map(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role->value,
                'roleLabel' => $u->role->label(),
                'createdAt' => $u->created_at?->format('d M Y, H:i') ?? '',
            ])->all(),
            'recentAudit' => AuditRecord::query()
                ->where('actor', '!=', '__sequence_counter__')
                ->latest('sequence')
                ->limit(8)
                ->get()
                ->map(fn (AuditRecord $a): array => [
                    'sequence' => $a->sequence,
                    'actor' => $a->actor,
                    'action' => $a->action,
                    'target' => $a->target_id,
                    'result' => $a->result,
                    'at' => $a->occurred_at->format('d M Y, H:i'),
                ])->all(),
        ]);
    }
}
