/**
 * Shared types for the DB-connected P2P pages.
 *
 * These mirror the PHP enums and the Inertia page props the controllers send.
 * They are the contract between Laravel and React — nothing here is mock data.
 */

export type Minor = number;

export type LedgerDirection = 'DEBIT' | 'CREDIT';

export type TransactionStatus =
    | 'CHAIN_PENDING'
    | 'CHAIN_CLOSED'
    | 'POSTED'
    | 'FAILED'
    | 'REVERSED'
    | 'UNDER_REVIEW';

export type KycStatus =
    | 'NOT_SUBMITTED'
    | 'PENDING'
    | 'IN_REVIEW'
    | 'APPROVED'
    | 'REJECTED';

export type RiskLevel = 'LOW' | 'MEDIUM' | 'HIGH';

export type UserRole = 'USER' | 'ADMIN';

/**
 * The lifecycle of a loan listing.
 *
 * These mirror `App\Enums\LoanStatus` exactly.
 */
export type LoanStatus =
    | 'MARKETPLACE_LISTED'
    | 'DISBURSED'
    | 'REPAYING'
    | 'REPAID'
    | 'DEFAULTED'
    | 'CANCELLED';

/**
 * The lifecycle of one repayment installment.
 *
 * These mirror `App\Enums\InstallmentStatus` exactly.
 */
export type InstallmentStatus =
    | 'PENDING'
    | 'PAID'
    | 'GRACE_PERIOD'
    | 'DEFAULTED';

/**
 * The lifecycle of a "Withdraw Money" attempt.
 *
 * These mirror `App\Enums\WithdrawalStatus` exactly.
 */
export type WithdrawalStatus =
    | 'PENDING'
    | 'SUCCEEDED'
    | 'FAILED'
    | 'CANCELLED';

/** The shape the app shell receives from Laravel's shared view data. */
export type P2PUser = {
    id: number;
    name: string;
    email: string;
    role: UserRole;
    roleLabel: string;
    initials: string;
    emailVerified: boolean;
    kycStatus: KycStatus;
    kycLabel: string;
};
