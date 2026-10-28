import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Plus, Receipt } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/p2p/components';
import { formatMoney } from '@/p2p/money';

type FundingRow = {
    id: number;
    reference: string;
    method: string;
    methodLabel: string;
    status: 'PENDING' | 'SUCCEEDED' | 'FAILED' | 'CANCELLED';
    statusLabel: string;
    amountMinor: number;
    currency: string;
    failureReason: string | null;
    settledAt: string;
    createdAt: string;
};

type FundingHistoryProps = {
    balance: { balanceMinor: number; currency: string };
    fundings: FundingRow[];
    environmentLabel: string;
};

export default function FundingHistory({
    balance,
    fundings,
    environmentLabel,
}: FundingHistoryProps) {
    return (
        <>
            <Head title="Payment History" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <Button asChild variant="ghost" size="sm">
                            <Link href="/wallet">
                                <ArrowLeft className="size-4" />
                                Back to wallet
                            </Link>
                        </Button>
                        <h1 className="mt-2 text-2xl font-semibold tracking-tight">
                            Payment History
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Every Add Money attempt on your account.
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <div className="rounded-lg border bg-card px-4 py-3 text-right">
                            <p className="text-xs text-muted-foreground">
                                Wallet balance
                            </p>
                            <p className="text-lg font-semibold tabular-nums">
                                {formatMoney(balance.balanceMinor)}
                            </p>
                        </div>
                        <Button asChild>
                            <Link href="/wallet/fundings">
                                <Plus className="size-4" />
                                Add Money
                            </Link>
                        </Button>
                    </div>
                </div>

                <p className="text-xs text-muted-foreground">
                    {environmentLabel}
                </p>

                <Card>
                    <CardHeader>
                        <CardTitle>Payments</CardTitle>
                        <CardDescription>
                            {fundings.length} payment attempt
                            {fundings.length === 1 ? '' : 's'}.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="px-0">
                        {fundings.length === 0 ? (
                            <EmptyState
                                icon={<Receipt className="size-5" />}
                                title="No payments yet"
                                description="Once you add money, your payment receipts will appear here."
                            />
                        ) : (
                            <ul className="divide-y">
                                {fundings.map((row) => (
                                    <li
                                        key={row.id}
                                        className="flex flex-wrap items-center justify-between gap-4 px-6 py-4"
                                    >
                                        <div className="min-w-0">
                                            <Link
                                                href={`/wallet/fundings/${row.id}`}
                                                className="truncate text-sm font-medium hover:underline"
                                            >
                                                {row.methodLabel}
                                            </Link>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {row.reference} ·{' '}
                                                {row.createdAt}
                                            </p>
                                            {row.failureReason && (
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {row.failureReason}
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex shrink-0 items-center gap-3">
                                            <span
                                                className={`text-sm font-medium tabular-nums ${
                                                    row.status === 'SUCCEEDED'
                                                        ? 'text-emerald-600 dark:text-emerald-400'
                                                        : 'text-muted-foreground'
                                                }`}
                                            >
                                                {row.status === 'SUCCEEDED'
                                                    ? '+'
                                                    : ''}
                                                {formatMoney(row.amountMinor)}
                                            </span>
                                            <span
                                                className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${
                                                    row.status === 'SUCCEEDED'
                                                        ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                                        : row.status ===
                                                            'FAILED'
                                                          ? 'bg-destructive/10 text-destructive dark:text-red-400'
                                                          : row.status ===
                                                              'CANCELLED'
                                                            ? 'bg-muted text-muted-foreground'
                                                            : 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
                                                }`}
                                            >
                                                {row.statusLabel}
                                            </span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
