import { Head, Link } from '@inertiajs/react';
import {
    Banknote,
    Copy,
    Download,
    HandCoins,
    QrCode,
    RefreshCcw,
    TrendingUp,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { NoticeBanner, DetailRow, StatTile } from '@/p2p/components';
import { formatMoney } from '@/p2p/money';

type ReceiveProps = {
    wallet: {
        id: number | null;
        name: string;
        currency: string;
        accountId: number | null;
        balanceMinor: number;
        createdAt: string;
    };
    incoming: {
        disbursedMinor: number;
        committedMinor: number;
    };
    availability: { enabled: boolean; reason: string };
};

export default function ReceiveMoney({
    wallet,
    incoming,
    availability,
}: ReceiveProps) {
    const accountLabel = wallet.accountId
        ? `ACC-${wallet.accountId}`
        : 'Not provisioned';

    return (
        <>
            <Head title="Receive money" />

            <div className="space-y-6">
                <NoticeBanner tone="blocked">
                    {availability.reason}
                </NoticeBanner>

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatTile
                        label="Wallet"
                        value={wallet.name}
                        hint={wallet.currency}
                        icon={<RefreshCcw className="size-4" />}
                        accent="primary"
                    />
                    <StatTile
                        label="Balance"
                        value={formatMoney(wallet.balanceMinor)}
                        hint="Live, read from the database"
                    />
                    <StatTile
                        label="Ledger account"
                        value={accountLabel}
                        hint="Live, read from the database"
                    />
                </div>

                {/* How money actually reaches a member on this platform. */}
                <Card>
                    <CardHeader>
                        <CardTitle>How money reaches your wallet</CardTitle>
                        <CardDescription>
                            On a lending platform the loan workflow does the
                            work that a one-to-one transfer would otherwise do.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-3">
                        <div className="space-y-2 rounded-lg border bg-muted/30 p-4">
                            <span className="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <Banknote className="size-4" />
                            </span>
                            <p className="text-sm font-medium">
                                Loan disbursement
                            </p>
                            <p className="text-xs text-muted-foreground">
                                When lenders fund your request to 100%, the
                                principal is released from escrow into your
                                wallet.
                            </p>
                            <p className="text-sm font-semibold tabular-nums">
                                {formatMoney(incoming.disbursedMinor)}
                            </p>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                className="w-full"
                            >
                                <Link href="/loans/my">My loans</Link>
                            </Button>
                        </div>

                        <div className="space-y-2 rounded-lg border bg-muted/30 p-4">
                            <span className="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <TrendingUp className="size-4" />
                            </span>
                            <p className="text-sm font-medium">
                                Repayment shares
                            </p>
                            <p className="text-xs text-muted-foreground">
                                As a lender, each installment a borrower pays is
                                split across lenders in proportion to what they
                                committed.
                            </p>
                            <p className="text-sm font-semibold tabular-nums">
                                {formatMoney(incoming.committedMinor)} committed
                            </p>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                className="w-full"
                            >
                                <Link href="/loans/investments">
                                    My investments
                                </Link>
                            </Button>
                        </div>

                        <div className="space-y-2 rounded-lg border bg-muted/30 p-4">
                            <span className="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <HandCoins className="size-4" />
                            </span>
                            <p className="text-sm font-medium">Add Money</p>
                            <p className="text-xs text-muted-foreground">
                                Top up your wallet through the simulated bKash,
                                Nagad, bank or card gateway so you can fund a
                                loan.
                            </p>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                className="w-full"
                            >
                                <Link href="/wallet/fundings">Add money</Link>
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Receive via account reference</CardTitle>
                            <CardDescription>
                                Share the reference below with a sender. A
                                transfer engine is required before an inbound
                                payment can be matched to this account.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center gap-2 rounded-lg border bg-muted/30 p-3">
                                <span className="flex-1 font-mono text-sm font-medium">
                                    {accountLabel}
                                </span>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        navigator.clipboard?.writeText(
                                            accountLabel,
                                        )
                                    }
                                    aria-label="Copy account reference"
                                >
                                    <Copy className="size-3.5" />
                                </Button>
                            </div>

                            <dl className="divide-y">
                                <DetailRow
                                    label="Wallet ID"
                                    value={wallet.id ? `#${wallet.id}` : '—'}
                                    mono
                                />
                                <DetailRow
                                    label="Ledger account"
                                    value={
                                        wallet.accountId
                                            ? `#${wallet.accountId}`
                                            : '—'
                                    }
                                    mono
                                />
                                <DetailRow
                                    label="Currency"
                                    value={wallet.currency}
                                />
                                <DetailRow
                                    label="Opened"
                                    value={wallet.createdAt || '—'}
                                />
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Request by QR</CardTitle>
                            <CardDescription>
                                A payment request encodes the account reference
                                and an amount. The QR image is not generated
                                yet.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex aspect-square w-full max-w-[240px] items-center justify-center rounded-lg border border-dashed bg-muted/30">
                                <div className="text-center">
                                    <QrCode className="mx-auto size-10 text-muted-foreground/40" />
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        QR generation not implemented
                                    </p>
                                </div>
                            </div>

                            <Button
                                variant="outline"
                                disabled
                                className="w-full"
                            >
                                <Download className="size-4" />
                                Download QR — not yet available
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
