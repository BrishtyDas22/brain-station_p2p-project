<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\KycStatus;
use App\Services\KycService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's KYC submission and status.
 */
class KycController extends Controller
{
    public function __construct(private readonly KycService $kyc) {}

    /**
     * Show the submission form and the current status.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();
        $submission = $user->kycSubmission()->first();
        $status = $submission === null ? KycStatus::NOT_SUBMITTED : $submission->status;

        return Inertia::render('kyc/index', [
            'submission' => $submission === null ? null : [
                'id' => $submission->id,
                'status' => $submission->status->value,
                'statusLabel' => $submission->status->label(),
                'fullName' => $submission->full_name,
                'documentType' => $submission->document_type,
                'documentNumberMasked' => $submission->maskedDocumentNumber(),
                'dateOfBirth' => $submission->date_of_birth->format('d M Y'),
                'addressLine' => $submission->address_line,
                'city' => $submission->city,
                'country' => $submission->country,
                'mobileNumber' => $submission->mobile_number,
                'submittedAt' => $submission->created_at?->format('d M Y, H:i') ?? '',
                'reviewedAt' => $submission->reviewed_at?->format('d M Y, H:i') ?? '',
                'reviewer' => $submission->reviewer?->name,
                'rejectionReason' => $submission->rejection_reason,
                'riskScore' => $submission->risk_score,
            ],
            'status' => $status->value,
            'statusLabel' => $status->label(),
            'documentTypes' => ['National ID (NID)', 'Passport', 'Driving Licence', 'Trade Licence'],
        ]);
    }

    /**
     * Store a submission.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'document_type' => ['required', 'string', Rule::in([
                'National ID (NID)', 'Passport', 'Driving Licence', 'Trade Licence',
            ])],
            'document_number' => ['required', 'string', 'min:4', 'max:40'],
            'date_of_birth' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'address_line' => ['required', 'string', 'max:180'],
            'city' => ['required', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'size:2'],
            'mobile_number' => ['required', 'string', 'max:20'],
        ], [
            'date_of_birth.before' => 'The date of birth must be in the past.',
            'document_number.min' => 'The document number is too short.',
        ]);

        $submission = $this->kyc->submit($request->user(), $validated);

        return back()->with('success', 'Your identity details were submitted for review.');
    }
}
