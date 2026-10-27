import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    ArrowDownLeft,
    ArrowUpRight,
    HandCoins,
    Landmark,
    Plus,
    ShieldCheck,
    Wallet as WalletIcon,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    DirectionAmount,
    EmptyState,
    KycBadge,
    StatTile,
    StatusBadge,
} from '@/p2p/components';
import { formatMoney } from '@/p2p/money';
import type { KycStatus, LedgerDirection, TransactionStatus } from '@/p2p/types';

type RecentTransaction = {
    id: number;
    reference: string;
    counterpartyName: string;
    amountMinor: number;
    direction: LedgerDirection;
    status: TransactionStatus;
    statusLabel: string;
    createdAt: string;
};

type DashboardProps = {
    account: {
        id: number;
        currency: string;
        nextSequenceNumber: number;
    } | null;
    wallet: {
        id: number;
        name: string;
        balanceMinor: number;
        reservedMinor: number;
        availableMinor: number;
    } | null;
    kycStatus: KycStatus;
    kycLabel: string;
    recentTransactions: RecentTransaction[];
    totals: { sent: number; received: number; count: number; added: number };
    lending: {
        borrowedMinor: number;
        committedMinor: number;
        earningMinor: number;
    };
};

export default function Dashboard({
    account,
    wallet,
    kycStatus,
    kycLabel,
    recentTransactions,
    totals,
    lending,
}: DashboardProps) {
    const hasWallet = wallet !== null;

    return (
        <>
            <Head title="Dashboard" />

            <div className="space-y-6">
                {/* Balance hero */}
                <Card className="overflow-hidden border-0 bg-gradient-to-br from-primary via-primary to-primary/80 text-primary-foreground shadow-lg">
                    <CardContent className="p-6 lg:p-8">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p className="text-xs font-medium tracking-widest uppercase opacity-80">
                                    Wallet balance
                                </p>
                                <p className="mt-2 text-4xl font-semibold tracking-tight tabular-nums">
                                    {hasWallet ? formatMoney(wallet.balanceMinor) : '৳0.00'}
                                </p>
                                <p className="mt-1 text-sm opacity-80">
                                    {hasWallet
                                        ? `${formatMoney(wallet.reservedMinor)} reserved · ${formatMoney(wallet.availableMinor)} available`
                                        : 'No ledger account has been provisioned for this user yet.'}
                                </p>
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <Button
                                    asChild
                                    variant="secondary"
                                    className="font-semibold"
                                >
                                    <Link href="/wallet/fundings">
                                        <Plus className="size-4" />
                                        Add Money
                                    </Link>
                                </Button>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="border-primary-foreground/40 bg-transparent font-semibold text-primary-foreground hover:bg-primary-foreground/10 hover:text-primary-foreground"
                                >
                                    <Link href="/loans">
                                        <HandCoins className="size-4" />
                                        Fund Loan
                                    </Link>
                                </Button>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="border-primary-foreground/40 bg-transparent font-semibold text-primary-foreground hover:bg-primary-foreground/10 hover:text-primary-foreground"
                                >
                                    <Link href="/wallet/withdrawals">
                                        <ArrowUpRight className="size-4" />
                                        Withdraw money
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Stats */}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatTile
                        label="Total added"
                        value={formatMoney(totals.added)}
                        hint="Credited via Add Money"
                        icon={<Plus className="size-4" />}
                        accent="success"
                    />
                    <StatTile
                        label="Total sent"
                        value={formatMoney(totals.sent)}
                        hint="Excludes failed transfers"
                        icon={<ArrowUpRight className="size-4" />}
                    />
                    <StatTile
                        label="Total received"
                        value={formatMoney(totals.received)}
                        hint="Credited to this account"
                        icon={<ArrowDownLeft className="size-4" />}
                    />
                    <StatTile
                        label="Transactions"
                        value={totals.count}
                        hint="Across this ledger account"
                        icon={<Activity className="size-4" />}
                    />
                </div>

                {/* The lending position. This is the micro-lending workflow the
                    platform is actually for — a member either borrows, lends, or
                    both — so it gets its own row of tiles. */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <StatTile
                        label="Borrowed"
                        value={formatMoney(lending.borrowedMinor)}
                        hint="Principal disbursed to you, still being repaid"
                        icon={<ArrowDownLeft className="size-4" />}
                        accent="primary"
                    />
                    <StatTile
                        label="Committed"
                        value={formatMoney(lending.committedMinor)}
                        hint="Your capital locked in escrow across loans"
                        icon={<HandCoins className="size-4" />}
                        accent="warning"
                    />
                    <StatTile
                        label="Earning"
                        value={formatMoney(lending.earningMinor)}
                        hint="Principal you funded that is being repaid to you"
                        icon={<ShieldCheck className="size-4" />}
                        accent="success"
                    />
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-card px-5 py-4">
                    <p className="text-sm text-muted-foreground">
                        Borrow ৳5,000 – ৳50,000, or fund another member&apos;s loan
                        from your own wallet balance.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href="/loans/my">My loans</Link>
                        </Button>
                        <Button asChild variant="outline" size="sm">
                            <Link href="/loans/investments">
                                My investments
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Recent activity */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div>
                                    <CardTitle>Recent activity</CardTitle>
                                    <CardDescription>
                                        Movements recorded against your ledger account.
                                    </CardDescription>
                                </div>
                                <Button asChild variant="outline" size="sm">
                                    <Link href="/transactions">View all</Link>
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent className="px-0">
                            {recentTransactions.length === 0 ? (
                                <EmptyState
                                    icon={<Activity className="size-5" />}
                                    title="No transactions yet"
                                    description="Once money is added or a transfer is posted to the ledger it will appear here with its live status."
                                />
                            ) : (
                                <ul className="divide-y">
                                    {recentTransactions.map((transaction) => (
                                        <li
                                            key={transaction.id}
                                            className="flex items-center justify-between gap-4 px-6 py-3.5"
                                        >
                                            <div className="min-w-0">
                                                <Link
                                                    href={`/transactions/${transaction.id}`}
                                                    className="truncate text-sm font-medium hover:underline"
                                                >
                                                    {transaction.counterpartyName || '—'}
                                                </Link>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {transaction.reference} · {transaction.createdAt}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-3">
                                                <StatusBadge status={transaction.status} />
                                                <DirectionAmount
                                                    amountMinor={transaction.amountMinor}
                                                    direction={transaction.direction}
                                                />
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    {/* Account facts */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Account</CardTitle>
                            <CardDescription>
                                Read directly from the ledger account record.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                                <span className="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <Landmark className="size-4" />
                                </span>
                                <div className="min-w-0">
                                    <p className="text-xs text-muted-foreground">Account ID</p>
                                    <p className="truncate text-sm font-medium">
                                        {account ? `#${account.id}` : 'Not provisioned'}
                                    </p>
                                </div>
                            </div>

                            <div className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                                <span className="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <WalletIcon className="size-4" />
                                </span>
                                <div className="min-w-0">
                                    <p className="text-xs text-muted-foreground">Next sequence</p>
                                    <p className="truncate text-sm font-medium">
                                        {account ? account.nextSequenceNumber : '—'}
                                    </p>
                                </div>
                            </div>

                            <div className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                                <span className="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <ShieldCheck className="size-4" />
                                </span>
                                <div className="min-w-0">
                                    <p className="text-xs text-muted-foreground">Currency</p>
                                    <p className="truncate text-sm font-medium">
                                        {account?.currency ?? 'BDT'}
                                    </p>
                                </div>
                            </div>

                            <div className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                                <span className="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <ShieldCheck className="size-4" />
                                </span>
                                <div className="min-w-0">
                                    <p className="text-xs text-muted-foreground">KYC status</p>
                                    <div className="truncate text-sm font-medium">
                                        <KycBadge status={kycStatus} />
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
