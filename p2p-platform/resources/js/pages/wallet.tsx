import { Head, Link } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Banknote,
    Coins,
    HandCoins,
    Landmark,
    Lock,
    Plus,
    Receipt,
    RefreshCcw,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { DetailRow, EmptyState, StatTile } from '@/p2p/components';
import { formatMoney } from '@/p2p/money';

type WalletProps = {
    wallet: {
        id: number;
        name: string;
        currency: string;
        balanceMinor: number;
        reservedMinor: number;
        availableMinor: number;
        accountId: number;
        ledgerSequence: number;
        createdAt: string;
    } | null;
    account: {
        id: number;
        currency: string;
        nextSequenceNumber: number;
        createdAt: string;
    } | null;
    hasWallet: boolean;
    kycApproved: boolean;
    totals: { added: number };
};

export default function Wallet({
    wallet,
    account,
    hasWallet,
    kycApproved,
    totals,
}: WalletProps) {
    if (!hasWallet || wallet === null) {
        return (
            <>
                <Head title="Wallet" />

                <Card>
                    <CardContent className="px-0">
                        <EmptyState
                            icon={<Landmark className="size-5" />}
                            title="No wallet provisioned yet"
                            description={
                                kycApproved ? (
                                    <>
                                        Your identity is approved, so a wallet
                                        is being provisioned for you now. If
                                        this message is still here, refresh the
                                        page.
                                    </>
                                ) : (
                                    <>
                                        A wallet is created once your identity
                                        is approved by an administrator. Until
                                        then there is no ledger account, so
                                        there is no balance to show.
                                    </>
                                )
                            }
                            action={
                                <Button asChild variant="outline">
                                    <Link href="/kyc">Complete your KYC</Link>
                                </Button>
                            }
                        />
                    </CardContent>
                </Card>
            </>
        );
    }

    return (
        <>
            <Head title="Wallet" />

            <div className="space-y-6">
                {/* The primary action. */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Wallet
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Your balance is derived from the entries posted to
                            your ledger account.
                        </p>
                    </div>

                    <Button asChild>
                        <Link href="/wallet/fundings">
                            <Plus className="size-4" />
                            Add Money
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatTile
                        label="Wallet balance"
                        value={formatMoney(wallet.balanceMinor)}
                        hint="posted to the ledger"
                        icon={<Coins className="size-4" />}
                        accent="primary"
                    />
                    <StatTile
                        label="Available"
                        value={formatMoney(wallet.availableMinor)}
                        hint="balance − reserved"
                        icon={<Banknote className="size-4" />}
                    />
                    <StatTile
                        label="Reserved"
                        value={formatMoney(wallet.reservedMinor)}
                        hint="held against pending transfers"
                        icon={<Lock className="size-4" />}
                        accent="warning"
                    />
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Wallet</CardTitle>
                            <CardDescription>
                                The wallet record and its link to the ledger
                                account.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <dl className="divide-y">
                                <DetailRow
                                    label="Wallet name"
                                    value={wallet.name}
                                />
                                <DetailRow
                                    label="Wallet ID"
                                    value={`#${wallet.id}`}
                                    mono
                                />
                                <DetailRow
                                    label="Ledger account"
                                    value={account ? `#${account.id}` : '—'}
                                    mono
                                />
                                <DetailRow
                                    label="Currency"
                                    value={wallet.currency}
                                />
                                <DetailRow
                                    label="Added so far"
                                    value={formatMoney(totals.added)}
                                />
                                <DetailRow
                                    label="Created"
                                    value={wallet.createdAt || '—'}
                                />
                            </dl>

                            <div className="mt-4 flex flex-wrap gap-2">
                                <Button asChild>
                                    <Link href="/wallet/fundings">
                                        <Plus className="size-4" />
                                        Add Money
                                    </Link>
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href="/wallet/fundings/history">
                                        <Receipt className="size-4" />
                                        Payment history
                                    </Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Ledger account</CardTitle>
                            <CardDescription>
                                The account owns the append-only ledger entries.
                                It does not hold a balance column — the wallet
                                does.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <dl className="divide-y">
                                <DetailRow
                                    label="Account ID"
                                    value={account ? `#${account.id}` : '—'}
                                    mono
                                />
                                <DetailRow
                                    label="Next sequence"
                                    value={account?.nextSequenceNumber ?? '—'}
                                />
                                <DetailRow
                                    label="Account type"
                                    value={
                                        account ? (
                                            <span className="rounded-md bg-muted px-2 py-0.5 text-xs font-medium">
                                                USER
                                            </span>
                                        ) : (
                                            '—'
                                        )
                                    }
                                />
                                <DetailRow
                                    label="Account created"
                                    value={account?.createdAt || '—'}
                                />
                            </dl>

                            <div className="mt-4 flex flex-wrap gap-2">
                                <Button asChild variant="outline">
                                    <Link href="/wallet/withdrawals">
                                        <ArrowUpRight className="size-4" />
                                        Withdraw money
                                    </Link>
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href="/loans">
                                        <HandCoins className="size-4" />
                                        Fund Loan
                                    </Link>
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href="/receive">
                                        <ArrowDownLeft className="size-4" />
                                        Receive money
                                    </Link>
                                </Button>
                                <Button asChild variant="ghost">
                                    <Link href="/transactions">
                                        <RefreshCcw className="size-4" />
                                        Transaction history
                                    </Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
