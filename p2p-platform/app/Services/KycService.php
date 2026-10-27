<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\KycStatus;
use App\Models\KycSubmission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The KYC workflow.
 *
 * Two operations, with different authorisation:
 *
 *   submit()  — the customer submits their identity. Creates the record.
 *   decide()  — an ADMINISTRATOR approves or rejects. Enforces the state
 *               machine in `KycStatus::canTransitionTo()`.
 *
 * Both are audited. A rejected submission may be re-submitted, which creates
 * a fresh row rather than mutating the rejected one, so the compliance history
 * of a rejection is never erased.
 *
 * An approval also provisions the user's wallet. See
 * {@see WalletProvisioningService} for what that creates and, more
 * importantly, what it deliberately does not.
 */
final class KycService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly WalletProvisioningService $wallets,
    ) {}

    /**
     * Submit a KYC application for a user.
     *
     * @param  array{
     *     full_name: string,
     *     document_type: string,
     *     document_number: string,
     *     date_of_birth: string,
     *     address_line: string,
     *     city: string,
     *     mobile_number: string,
     *     country?: string
     * }  $data
     */
    public function submit(User $user, array $data): KycSubmission
    {
        $existing = $user->kycSubmission()->first();

        if ($existing !== null && $existing->status === KycStatus::APPROVED) {
            throw ValidationException::withMessages([
                'kyc' => 'Your identity is already verified. No further submission is needed.',
            ]);
        }

        $submission = DB::transaction(function () use ($user, $data, $existing): KycSubmission {
            // A re-submission REPLACES the prior record rather than mutating
            // it. The rejected row is deleted here only because a rejected
            // application is not yet compliance history — it has never been
            // decided upon. A decided record is never edited.
            $existing?->delete();

            return KycSubmission::query()->create([
                'user_id' => $user->id,
                'status' => KycStatus::PENDING,
                'full_name' => $data['full_name'],
                'document_type' => $data['document_type'],
                'document_number' => $data['document_number'],
                'date_of_birth' => $data['date_of_birth'],
                'address_line' => $data['address_line'],
                'city' => $data['city'],
                'country' => $data['country'] ?? 'BD',
                'mobile_number' => $data['mobile_number'],
            ]);
        });

        $this->audit->record(
            'KYC_SUBMITTED',
            $user,
            KycSubmission::class,
            $submission->id,
            context: ['status' => $submission->status->value],
        );

        return $submission;
    }

    /**
     * Approve or reject a KYC submission.
     *
     * Only an administrator may call this. The check is here, in the service,
     * not only in the route middleware, so the boundary holds for any caller.
     */
    public function decide(
        User $actor,
        KycSubmission $submission,
        KycStatus $decision,
        ?string $rejectionReason = null,
        ?int $riskScore = null,
    ): KycSubmission {
        if (! $actor->isAdmin()) {
            $this->audit->deny(
                'KYC_DECISION_DENIED',
                $actor,
                KycSubmission::class,
                $submission->id,
                ['attempted' => $decision->value],
            );

            throw ValidationException::withMessages([
                'kyc' => 'Only an administrator may decide a KYC application.',
            ]);
        }

        $current = $submission->status;

        if (! $current->canTransitionTo($decision)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'A KYC application in state "%s" cannot move to "%s".',
                    $current->value,
                    $decision->value,
                ),
            ]);
        }

        if ($decision === KycStatus::REJECTED && ($rejectionReason === null || trim($rejectionReason) === '')) {
            throw ValidationException::withMessages([
                'rejection_reason' => 'A rejection must state a reason.',
            ]);
        }

        $submission->status = $decision;
        $submission->reviewed_by = $actor->id;
        $submission->reviewed_at = Carbon::now();
        $submission->rejection_reason = $decision === KycStatus::REJECTED ? $rejectionReason : null;
        $submission->risk_score = $riskScore;
        $submission->save();

        // An APPROVED identity is what makes the user eligible for a wallet:
        // SPEC §11.1 makes verified KYC a hard precondition for any ledger
        // write path, and §7.1 step 2 requires it before a payer wallet may be
        // used. The wallet is provisioned HERE — at the moment the identity is
        // approved — so the user's account and their wallet always come into
        // existence together.
        //
        // The wallet starts at exactly zero. There is no approved funding path
        // (H-1 is 🟠 Proposed, not approved), so this creates the capacity to
        // hold money and not money. It writes no ledger entries.
        //
        // Provisioning is idempotent, so an approval for a user who already
        // has a wallet re-uses it rather than creating a second one.
        if ($decision === KycStatus::APPROVED) {
            $this->wallets->provisionForEligibleUser($submission->user);
        }

        $this->audit->record(
            $decision === KycStatus::APPROVED ? 'KYC_APPROVED' : 'KYC_REJECTED',
            $actor,
            KycSubmission::class,
            $submission->id,
            context: [
                'from' => $current->value,
                'to' => $decision->value,
                'risk_score' => $riskScore,
            ],
        );

        return $submission;
    }

    /**
     * Move a PENDING submission into IN_REVIEW.
     */
    public function beginReview(User $actor, KycSubmission $submission): KycSubmission
    {
        return $this->decide($actor, $submission, KycStatus::IN_REVIEW);
    }
}
