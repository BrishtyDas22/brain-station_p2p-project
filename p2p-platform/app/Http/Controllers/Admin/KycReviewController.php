<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\KycStatus;
use App\Http\Controllers\Controller;
use App\Models\KycSubmission;
use App\Services\KycService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The administrator's KYC review queue.
 *
 * Every route here is behind the `admin` middleware AND the service re-checks
 * the role, so the boundary holds even if a route were mounted by mistake.
 */
class KycReviewController extends Controller
{
    public function __construct(private readonly KycService $kyc) {}

    /**
     * The review queue.
     */
    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', '');

        $query = KycSubmission::query()->with(['user', 'reviewer']);

        if ($status !== '' && $status !== 'ALL') {
            $query->where('status', $status);
        }

        $submissions = $query->latest()->paginate(12)->withQueryString();

        return Inertia::render('admin/kyc-review', [
            'submissions' => $submissions->through(fn (KycSubmission $s): array => [
                'id' => $s->id,
                'userId' => $s->user_id,
                'applicantName' => $s->full_name,
                'applicantEmail' => $s->user->email,
                'nationalIdMasked' => $s->maskedDocumentNumber(),
                'documentType' => $s->document_type,
                'dateOfBirth' => $s->date_of_birth->format('d M Y'),
                'city' => $s->city,
                'mobileNumber' => $s->mobile_number,
                'status' => $s->status->value,
                'statusLabel' => $s->status->label(),
                'riskScore' => $s->risk_score,
                'rejectionReason' => $s->rejection_reason,
                'submittedAt' => $s->created_at?->format('d M Y, H:i') ?? '',
                'reviewedAt' => $s->reviewed_at?->format('d M Y, H:i'),
                'reviewer' => $s->reviewer?->name,
            ]),
            'filters' => ['status' => $status === '' ? 'ALL' : $status],
            'statuses' => KycStatus::values(),
        ]);
    }

    /**
     * Approve an application.
     */
    public function approve(Request $request, KycSubmission $submission): RedirectResponse
    {
        $validated = $request->validate([
            'risk_score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $this->kyc->decide(
            $request->user(),
            $submission,
            KycStatus::APPROVED,
            null,
            $validated['risk_score'] ?? null,
        );

        return back()->with('success', 'The application was approved.');
    }

    /**
     * Reject an application.
     */
    public function reject(Request $request, KycSubmission $submission): RedirectResponse
    {
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:500'],
            'risk_score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $this->kyc->decide(
            $request->user(),
            $submission,
            KycStatus::REJECTED,
            $validated['rejection_reason'],
            $validated['risk_score'] ?? null,
        );

        return back()->with('success', 'The application was rejected.');
    }

    /**
     * Move an application into review.
     */
    public function beginReview(Request $request, KycSubmission $submission): RedirectResponse
    {
        $this->kyc->beginReview($request->user(), $submission);

        return back()->with('success', 'The application is now under review.');
    }
}
