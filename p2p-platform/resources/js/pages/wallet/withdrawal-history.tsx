import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Banknote } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { DataTable, EmptyState, WithdrawalStatusBadge } from '@/p2p/components';
import { formatMoney } from '@/p2p/money';
import type { WithdrawalStatus } from '@/p2p/types';

type Withdrawal = {
    id: number;
    reference: string;
    method: string;
    methodLabel: string;
    status: WithdrawalStatus;
    statusLabel: string;
    amountMinor: number;
    currency: string;
    failureReason: string | null;
    settledAt: string;
    createdAt: string;
};

type WithdrawalHistoryProps = {
    balance: { balanceMinor: number; currency: string };
    withdrawals: Withdrawal[];
    environmentLabel: string;
};

export default function WithdrawalHistory({
    balance,
    withdrawals,
    environmentLabel,
}: WithdrawalHistoryProps) {
    const totalPaidOut = withdrawals
        .filter((withdrawal) => withdrawal.status === 'SUCCEEDED')
        .reduce((sum, withdrawal) => sum + withdrawal.amountMinor, 0);

    return (
        <>
            <Head title="Withdrawal History" />

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
                            Withdrawal History
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Every payout attempt from your wallet.
                        </p>
                    </div>

                    <div className="rounded-lg border bg-card px-4 py-3 text-right">
                        <p className="text-xs text-muted-foreground">
                            Wallet balance
                        </p>
                        <p className="text-lg font-semibold tabular-nums">
                            {formatMoney(balance.balanceMinor)}
                        </p>
                    </div>
                </div>

                {withdrawals.length === 0 ? (
                    <Card>
                        <EmptyState
                            icon={<Banknote className="size-5" />}
                            title="No withdrawals yet"
                            description="Payouts you make from your wallet will be listed here."
                            action={
                                <Button asChild>
                                    <Link href="/wallet/withdrawals">
                                        Withdraw money
                                    </Link>
                                </Button>
                            }
                        />
                    </Card>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>Your payouts</CardTitle>
                            <CardDescription>
                                {withdrawals.length} attempt
                                {withdrawals.length === 1 ? '' : 's'} ·{' '}
                                {formatMoney(totalPaidOut)} sent successfully.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <DataTable
                                headers={[
                                    'Reference',
                                    'Method',
                                    'Amount',
                                    'Status',
                                    'Requested',
                                    'Completed',
                                ]}
                            >
                                {withdrawals.map((withdrawal) => (
                                    <tr key={withdrawal.id}>
                                        <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                                            {withdrawal.reference}
                                        </td>
                                        <td className="px-4 py-3 font-medium">
                                            {withdrawal.methodLabel}
                                        </td>
                                        <td className="px-4 py-3 font-medium tabular-nums">
                                            {formatMoney(
                                                withdrawal.amountMinor,
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <WithdrawalStatusBadge
                                                status={withdrawal.status}
                                            />
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {withdrawal.createdAt}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {withdrawal.settledAt || '—'}
                                        </td>
                                    </tr>
                                ))}
                            </DataTable>
                        </CardContent>
                    </Card>
                )}

                <p className="text-xs text-muted-foreground">
                    {environmentLabel}
                </p>
            </div>
        </>
    );
}
