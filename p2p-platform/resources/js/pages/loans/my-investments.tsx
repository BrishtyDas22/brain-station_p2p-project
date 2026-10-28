import { Head, Link } from '@inertiajs/react';
import { ArrowRight, HandCoins, TrendingUp } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    DataTable,
    EmptyState,
    LoanStatusBadge,
    NoticeBanner,
    StatTile,
} from '@/p2p/components';
import { formatMoney } from '@/p2p/money';
import type { LoanStatus } from '@/p2p/types';

type Investment = {
    id: number;
    reference: string;
    loanId: number;
    loanReference: string;
    loanStatus: LoanStatus;
    loanStatusLabel: string;
    loanPrincipalMinor: number;
    amountMinor: number;
    committedAt: string;
};

type MyInvestmentsProps = {
    investments: Investment[];
    totals: { committedMinor: number };
};

export default function MyInvestments({
    investments,
    totals,
}: MyInvestmentsProps) {
    const inEscrow = investments
        .filter((investment) => investment.loanStatus === 'MARKETPLACE_LISTED')
        .reduce((sum, investment) => sum + investment.amountMinor, 0);

    const disbursed = investments
        .filter((investment) =>
            ['DISBURSED', 'REPAYING', 'REPAID'].includes(investment.loanStatus),
        )
        .reduce((sum, investment) => sum + investment.amountMinor, 0);

    return (
        <>
            <Head title="My Investments" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        My Investments
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Every loan you have funded, and what each commitment is
                        worth right now.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatTile
                        label="Total committed"
                        value={formatMoney(totals.committedMinor)}
                        hint="Across every loan you funded"
                        icon={<HandCoins className="size-4" />}
                        accent="primary"
                    />
                    <StatTile
                        label="Locked in escrow"
                        value={formatMoney(inEscrow)}
                        hint="Not yet disbursed to the borrower"
                        icon={<HandCoins className="size-4" />}
                        accent="warning"
                    />
                    <StatTile
                        label="Disbursed"
                        value={formatMoney(disbursed)}
                        hint="Earning repayments from the borrower"
                        icon={<TrendingUp className="size-4" />}
                        accent="success"
                    />
                </div>

                <NoticeBanner tone="info">
                    Your share of each repayment is proportional to what you
                    committed, so the largest commitment receives the largest
                    share — including any rounding remainder.
                </NoticeBanner>

                {investments.length === 0 ? (
                    <Card>
                        <EmptyState
                            icon={<HandCoins className="size-5" />}
                            title="You have not funded a loan yet"
                            description="Browse the marketplace and commit capital from your wallet balance to start earning repayments."
                            action={
                                <Button asChild>
                                    <Link href="/loans">
                                        Browse the marketplace
                                        <ArrowRight className="size-4" />
                                    </Link>
                                </Button>
                            }
                        />
                    </Card>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>Your commitments</CardTitle>
                            <CardDescription>
                                {investments.length} loan
                                {investments.length === 1 ? '' : 's'} funded.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <DataTable
                                headers={[
                                    'Loan',
                                    'Reference',
                                    'Committed',
                                    'Share of loan',
                                    'Status',
                                    'Date',
                                    '',
                                ]}
                            >
                                {investments.map((investment) => (
                                    <tr key={investment.id}>
                                        <td className="px-4 py-3 font-medium">
                                            {formatMoney(
                                                investment.loanPrincipalMinor,
                                            )}
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
                                            {investment.loanPrincipalMinor > 0
                                                ? `${Math.round(
                                                      (investment.amountMinor /
                                                          investment.loanPrincipalMinor) *
                                                          100,
                                                  )}%`
                                                : '0%'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <LoanStatusBadge
                                                status={investment.loanStatus}
                                            />
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {investment.committedAt}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="outline"
                                            >
                                                <Link
                                                    href={`/loans/${investment.loanId}`}
                                                >
                                                    View
                                                </Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </DataTable>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}
