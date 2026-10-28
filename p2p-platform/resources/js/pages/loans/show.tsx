import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Banknote,
    CalendarClock,
    HandCoins,
    Lock,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    DataTable,
    DetailRow,
    EmptyState,
    FundingMeter,
    InstallmentStatusBadge,
    LoanStatusBadge,
    NoticeBanner,
} from '@/p2p/components';
import { formatMoney } from '@/p2p/money';
import type { InstallmentStatus, LoanStatus } from '@/p2p/types';

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

type Investment = {
    id: number;
    reference: string;
    loanId: number;
    loanReference: string;
    loanStatus: string;
    loanStatusLabel: string;
    loanPrincipalMinor: number;
    lenderName: string;
    amountMinor: number;
    committedAt: string;
};

type Installment = {
    id: number;
    sequence: number;
    dueDate: string;
    amountMinor: number;
    principalMinor: number;
    interestMinor: number;
    penaltyMinor: number;
    status: InstallmentStatus;
    statusLabel: string;
    paidAt: string;
};

type ShowProps = {
    loan: Loan;
    borrower: { name: string };
    investments: Investment[];
    installments: Installment[];
    viewer: {
        isBorrower: boolean;
        alreadyInvested: boolean;
        availableMinor: number;
        isApproved: boolean;
    };
};

export default function LoanShow({
    loan,
    borrower,
    investments,
    installments,
    viewer,
}: ShowProps) {
    const [showFundForm, setShowFundForm] = useState(false);

    const fund = useForm({ amount: '' });

    const collect = useForm({ installment: '' });

    const openForFunding = loan.status === 'MARKETPLACE_LISTED';

    const submitFunding = (event: React.FormEvent) => {
        event.preventDefault();
        fund.post(`/loans/${loan.id}/fund`);
    };

    const payInstallment = (installmentId: number) => {
        collect.post(`/loans/installments/${installmentId}/collect`);
    };

    const totalRepayable = installments.reduce(
        (sum, installment) => sum + installment.amountMinor,
        0,
    );

    const totalInterest = installments.reduce(
        (sum, installment) => sum + installment.interestMinor,
        0,
    );

    return (
        <>
            <Head title={`Loan ${loan.reference}`} />

            <div className="space-y-6">
                <div>
                    <Button asChild variant="ghost" size="sm">
                        <Link href="/loans">
                            <ArrowLeft className="size-4" />
                            Back to marketplace
                        </Link>
                    </Button>
                    <div className="mt-2 flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {formatMoney(loan.principalMinor)} loan
                        </h1>
                        <LoanStatusBadge status={loan.status} />
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Reference {loan.reference} · requested by{' '}
                        {viewer.isBorrower ? 'you' : borrower.name}
                    </p>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* The listing */}
                    <div className="space-y-6 lg:col-span-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Funding progress</CardTitle>
                                <CardDescription>
                                    Committed capital is locked in escrow and
                                    released to the borrower at 100%.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <FundingMeter
                                    fundedMinor={loan.fundedMinor}
                                    principalMinor={loan.principalMinor}
                                />

                                {viewer.isBorrower ? (
                                    <NoticeBanner tone="info">
                                        You receive the principal in your wallet
                                        the moment this loan reaches 100%
                                        funding.
                                    </NoticeBanner>
                                ) : openForFunding ? (
                                    <NoticeBanner tone="info">
                                        Your commitment comes from your wallet
                                        balance only — there is no external
                                        payment step, and no admin approval is
                                        needed.
                                    </NoticeBanner>
                                ) : null}
                            </CardContent>
                        </Card>

                        {/* Fund this loan */}
                        {!viewer.isBorrower && openForFunding && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Fund this loan</CardTitle>
                                    <CardDescription>
                                        Commit capital from your wallet. It is
                                        held in escrow until the loan is fully
                                        funded.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {viewer.alreadyInvested ? (
                                        <NoticeBanner tone="success">
                                            You have already committed to this
                                            loan. A lender may only fund a
                                            listing once.
                                        </NoticeBanner>
                                    ) : !viewer.isApproved ? (
                                        <NoticeBanner tone="blocked">
                                            Your identity must be approved
                                            before you can fund a loan.
                                        </NoticeBanner>
                                    ) : (
                                        <>
                                            <div className="mb-4 flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-4 py-3">
                                                <span className="text-sm text-muted-foreground">
                                                    Available to commit
                                                </span>
                                                <span className="font-semibold tabular-nums">
                                                    {formatMoney(
                                                        viewer.availableMinor,
                                                    )}
                                                </span>
                                            </div>

                                            {showFundForm ? (
                                                <form
                                                    onSubmit={submitFunding}
                                                    className="space-y-4"
                                                >
                                                    <div className="space-y-2">
                                                        <Label htmlFor="amount">
                                                            Amount to commit
                                                            (BDT)
                                                        </Label>
                                                        <Input
                                                            id="amount"
                                                            name="amount"
                                                            inputMode="decimal"
                                                            placeholder="5,000"
                                                            value={
                                                                fund.data.amount
                                                            }
                                                            onChange={(event) =>
                                                                fund.setData(
                                                                    'amount',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            aria-invalid={Boolean(
                                                                fund.errors
                                                                    .amount,
                                                            )}
                                                        />
                                                        <p className="text-xs text-muted-foreground">
                                                            Up to{' '}
                                                            {formatMoney(
                                                                loan.remainingMinor,
                                                            )}{' '}
                                                            remaining on this
                                                            listing.
                                                        </p>
                                                        {fund.errors.amount && (
                                                            <p className="text-sm text-destructive">
                                                                {
                                                                    fund.errors
                                                                        .amount
                                                                }
                                                            </p>
                                                        )}
                                                    </div>

                                                    <div className="flex justify-end gap-2">
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setShowFundForm(
                                                                    false,
                                                                )
                                                            }
                                                        >
                                                            Cancel
                                                        </Button>
                                                        <Button
                                                            type="submit"
                                                            disabled={
                                                                fund.processing
                                                            }
                                                        >
                                                            <Lock className="size-4" />
                                                            {fund.processing
                                                                ? 'Locking…'
                                                                : 'Commit to escrow'}
                                                        </Button>
                                                    </div>
                                                </form>
                                            ) : (
                                                <Button
                                                    type="button"
                                                    onClick={() =>
                                                        setShowFundForm(true)
                                                    }
                                                >
                                                    <HandCoins className="size-4" />
                                                    Fund this loan
                                                </Button>
                                            )}
                                        </>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* The lenders */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Lenders</CardTitle>
                                <CardDescription>
                                    {loan.investorCount} lender
                                    {loan.investorCount === 1 ? '' : 's'} have
                                    committed capital to this loan.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {investments.length === 0 ? (
                                    <EmptyState
                                        icon={<Users className="size-5" />}
                                        title="No lenders yet"
                                        description="Be the first to fund this listing."
                                    />
                                ) : (
                                    <DataTable
                                        headers={[
                                            'Lender',
                                            'Reference',
                                            'Committed',
                                            'Share',
                                            'Date',
                                        ]}
                                    >
                                        {investments.map((investment) => (
                                            <tr key={investment.id}>
                                                <td className="px-4 py-3 font-medium">
                                                    {investment.lenderName}
                                                </td>
                                                <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                                                    {investment.reference}
                                                </td>
                                                <td className="px-4 py-3 font-medium tabular-nums">
                                                    {formatMoney(
                                                        investment.amountMinor,
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground tabular-nums">
                                                    {loan.fundedMinor > 0
                                                        ? `${Math.round(
                                                              (investment.amountMinor /
                                                                  loan.fundedMinor) *
                                                                  100,
                                                          )}%`
                                                        : '0%'}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {investment.committedAt}
                                                </td>
                                            </tr>
                                        ))}
                                    </DataTable>
                                )}
                            </CardContent>
                        </Card>

                        {/* The repayment schedule */}
                        {installments.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Repayment schedule</CardTitle>
                                    <CardDescription>
                                        {installments.length} monthly
                                        installment
                                        {installments.length === 1
                                            ? ''
                                            : 's'}{' '}
                                        of {formatMoney(totalRepayable)} in
                                        total, including{' '}
                                        {formatMoney(totalInterest)} in
                                        interest.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <DataTable
                                        headers={[
                                            '#',
                                            'Due',
                                            'Principal',
                                            'Interest',
                                            'Total',
                                            'Status',
                                            '',
                                        ]}
                                    >
                                        {installments.map((installment) => (
                                            <tr key={installment.id}>
                                                <td className="px-4 py-3 font-medium">
                                                    {installment.sequence}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {installment.dueDate}
                                                </td>
                                                <td className="px-4 py-3 tabular-nums">
                                                    {formatMoney(
                                                        installment.principalMinor,
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground tabular-nums">
                                                    {formatMoney(
                                                        installment.interestMinor,
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 font-medium tabular-nums">
                                                    {formatMoney(
                                                        installment.amountMinor,
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <InstallmentStatusBadge
                                                        status={
                                                            installment.status
                                                        }
                                                    />
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    {viewer.isBorrower &&
                                                        installment.status !==
                                                            'PAID' && (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                type="button"
                                                                disabled={
                                                                    collect.processing
                                                                }
                                                                onClick={() =>
                                                                    payInstallment(
                                                                        installment.id,
                                                                    )
                                                                }
                                                            >
                                                                Pay now
                                                            </Button>
                                                        )}
                                                </td>
                                            </tr>
                                        ))}
                                    </DataTable>

                                    {collect.errors.installment && (
                                        <p className="mt-3 text-sm text-destructive">
                                            {collect.errors.installment}
                                        </p>
                                    )}

                                    {viewer.isBorrower && (
                                        <NoticeBanner
                                            tone="info"
                                            className="mt-4"
                                        >
                                            If a payment cannot be made, the
                                            installment enters a 3-day grace
                                            period, after which a 0.5% daily
                                            late penalty applies.
                                        </NoticeBanner>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* The summary */}
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Loan details</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="divide-y">
                                    <DetailRow
                                        label="Principal"
                                        value={formatMoney(loan.principalMinor)}
                                    />
                                    <DetailRow
                                        label="Committed"
                                        value={formatMoney(loan.fundedMinor)}
                                    />
                                    <DetailRow
                                        label="Remaining"
                                        value={formatMoney(loan.remainingMinor)}
                                    />
                                    <DetailRow
                                        label="Interest rate"
                                        value={loan.interestRateLabel}
                                    />
                                    <DetailRow
                                        label="Tenure"
                                        value={`${loan.tenureMonths} month${loan.tenureMonths === 1 ? '' : 's'}`}
                                    />
                                    <DetailRow
                                        label="Total repayable"
                                        value={
                                            totalRepayable > 0
                                                ? formatMoney(totalRepayable)
                                                : '—'
                                        }
                                    />
                                    <DetailRow
                                        label="Lenders"
                                        value={loan.investorCount}
                                    />
                                    <DetailRow
                                        label="Listed"
                                        value={loan.createdAt}
                                    />
                                    {loan.fundedAt && (
                                        <DetailRow
                                            label="Funded"
                                            value={loan.fundedAt}
                                        />
                                    )}
                                    {loan.repaidAt && (
                                        <DetailRow
                                            label="Repaid"
                                            value={loan.repaidAt}
                                        />
                                    )}
                                </dl>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Purpose</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-sm leading-relaxed">
                                    {loan.purpose}
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Quick links</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                <Button
                                    asChild
                                    variant="outline"
                                    className="w-full justify-start"
                                >
                                    <Link href="/loans/my">
                                        <CalendarClock className="size-4" />
                                        My loans
                                    </Link>
                                </Button>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="w-full justify-start"
                                >
                                    <Link href="/loans/investments">
                                        <HandCoins className="size-4" />
                                        My investments
                                    </Link>
                                </Button>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="w-full justify-start"
                                >
                                    <Link href="/wallet/withdrawals">
                                        <Banknote className="size-4" />
                                        Withdraw money
                                    </Link>
                                </Button>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}
