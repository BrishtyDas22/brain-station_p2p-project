import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Plus, Wallet } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    EmptyState,
    FundingMeter,
    LoanStatusBadge,
    NoticeBanner,
    StatTile,
} from '@/p2p/components';
import { formatMoney } from '@/p2p/money';
import type { LoanStatus } from '@/p2p/types';

type Loan = {
    id: number;
    reference: string;
    principalMinor: number;
    fundedMinor: number;
    remainingMinor: number;
    fundingPercent: number;
    currency: string;
    interestRateBps: number;
    interestRateLabel: string;
    tenureMonths: number;
    purpose: string;
    status: LoanStatus;
    statusLabel: string;
    investorCount: number;
    createdAt: string;
    fundedAt: string;
    repaidAt: string;
};

type MyLoansProps = {
    loans: Loan[];
    canRequest: boolean;
    limits: {
        minPrincipalMinor: number;
        maxPrincipalMinor: number;
        minTenureMonths: number;
        maxTenureMonths: number;
        maxInterestRateBps: number;
        gracePeriodDays: number;
        latePenaltyBps: number;
    };
};

export default function MyLoans({ loans, canRequest, limits }: MyLoansProps) {
    const totalBorrowed = loans
        .filter((loan) => ['DISBURSED', 'REPAYING'].includes(loan.status))
        .reduce((sum, loan) => sum + loan.principalMinor, 0);

    const awaitingFunding = loans.filter(
        (loan) => loan.status === 'MARKETPLACE_LISTED',
    ).length;

    const repaid = loans.filter((loan) => loan.status === 'REPAID').length;

    const penaltyPercent = limits.latePenaltyBps / 100;

    return (
        <>
            <Head title="My Loans" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            My Loans
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Every loan you have requested, with its funding
                            progress and repayment status.
                        </p>
                    </div>

                    <Button asChild>
                        <Link href="/loans">
                            <Plus className="size-4" />
                            Request a loan
                        </Link>
                    </Button>
                </div>

                {!canRequest && (
                    <NoticeBanner tone="blocked">
                        Your identity must be approved before you can request a
                        loan.{' '}
                        <Link
                            href="/kyc"
                            className="font-semibold underline underline-offset-4"
                        >
                            Complete your KYC verification
                        </Link>
                        .
                    </NoticeBanner>
                )}

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatTile
                        label="Outstanding principal"
                        value={formatMoney(totalBorrowed)}
                        hint="Disbursed and still being repaid"
                        icon={<Wallet className="size-4" />}
                        accent="primary"
                    />
                    <StatTile
                        label="Awaiting funding"
                        value={awaitingFunding}
                        hint="Listed on the marketplace"
                        icon={<Plus className="size-4" />}
                        accent="warning"
                    />
                    <StatTile
                        label="Fully repaid"
                        value={repaid}
                        hint="Loans paid back in full"
                        icon={<ArrowRight className="size-4" />}
                        accent="success"
                    />
                </div>

                {loans.length === 0 ? (
                    <Card>
                        <EmptyState
                            icon={<Wallet className="size-5" />}
                            title="You have no loan requests yet"
                            description={
                                canRequest
                                    ? 'Request a loan of ৳' +
                                      (
                                          limits.minPrincipalMinor / 100
                                      ).toLocaleString() +
                                      ' – ৳' +
                                      (
                                          limits.maxPrincipalMinor / 100
                                      ).toLocaleString() +
                                      ' and it will be published on the marketplace.'
                                    : 'Complete your KYC verification to request a loan.'
                            }
                            action={
                                canRequest ? (
                                    <Button asChild>
                                        <Link href="/loans">
                                            <Plus className="size-4" />
                                            Request a loan
                                        </Link>
                                    </Button>
                                ) : undefined
                            }
                        />
                    </Card>
                ) : (
                    <div className="space-y-4">
                        {loans.map((loan) => (
                            <Card key={loan.id}>
                                <CardHeader className="pb-3">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <CardTitle>
                                                {formatMoney(
                                                    loan.principalMinor,
                                                )}
                                            </CardTitle>
                                            <CardDescription className="mt-1">
                                                {loan.reference} ·{' '}
                                                {loan.tenureMonths} month
                                                {loan.tenureMonths === 1
                                                    ? ''
                                                    : 's'}{' '}
                                                at {loan.interestRateLabel}
                                            </CardDescription>
                                        </div>
                                        <LoanStatusBadge status={loan.status} />
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <FundingMeter
                                        fundedMinor={loan.fundedMinor}
                                        principalMinor={loan.principalMinor}
                                    />

                                    <p className="line-clamp-2 text-sm text-muted-foreground">
                                        {loan.purpose}
                                    </p>

                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <span className="text-xs text-muted-foreground">
                                            {loan.investorCount} lender
                                            {loan.investorCount === 1
                                                ? ''
                                                : 's'}{' '}
                                            · listed {loan.createdAt}
                                        </span>

                                        <Button
                                            asChild
                                            size="sm"
                                            variant="outline"
                                        >
                                            <Link href={`/loans/${loan.id}`}>
                                                View loan
                                                <ArrowRight className="size-4" />
                                            </Link>
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                <NoticeBanner tone="info">
                    A missed payment puts the installment into a{' '}
                    {limits.gracePeriodDays}-day grace period. After that, a{' '}
                    {penaltyPercent}% daily late penalty applies.
                </NoticeBanner>
            </div>
        </>
    );
}
