import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Receipt } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    DetailRow,
    DirectionAmount,
    RiskBadge,
    StatusBadge,
} from '@/p2p/components';
import { formatMoney } from '@/p2p/money';
import type {
    LedgerDirection,
    RiskLevel,
    TransactionStatus,
} from '@/p2p/types';

type TransactionProps = {
    transaction: {
        id: number;
        reference: string;
        counterpartyName: string;
        counterpartyAccount: string;
        amountMinor: number;
        feeMinor: number;
        currency: string;
        direction: LedgerDirection;
        status: TransactionStatus;
        statusLabel: string;
        riskLevel: RiskLevel;
        method: string;
        note: string;
        kind: 'ADD_MONEY' | 'TRANSFER';
        kindLabel: string;
        createdAt: string;
        chainClosedAt: string | null;
        idempotencyKey: string;
    };
};

export default function TransactionShow({ transaction }: TransactionProps) {
    const t = transaction;

    return (
        <>
            <Head title={`${t.kindLabel} ${t.reference}`} />

            <div className="space-y-6">
                <Button asChild variant="ghost" className="-ml-2">
                    <Link href="/transactions">
                        <ArrowLeft className="size-4" />
                        Back to history
                    </Link>
                </Button>

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Receipt className="size-4" />
                                {t.kindLabel} details
                            </CardTitle>
                            <CardDescription>
                                Reference{' '}
                                <span className="font-mono">{t.reference}</span>
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <dl className="divide-y">
                                <DetailRow label="Type" value={t.kindLabel} />
                                <DetailRow
                                    label="Amount"
                                    value={
                                        <DirectionAmount
                                            amountMinor={t.amountMinor}
                                            direction={t.direction}
                                        />
                                    }
                                />
                                <DetailRow
                                    label="Fee"
                                    value={formatMoney(t.feeMinor)}
                                />
                                <DetailRow
                                    label="Total"
                                    value={formatMoney(
                                        t.amountMinor + t.feeMinor,
                                    )}
                                />
                                <DetailRow
                                    label="Currency"
                                    value={t.currency}
                                />
                                <DetailRow
                                    label="Direction"
                                    value={
                                        t.direction === 'CREDIT'
                                            ? 'Received'
                                            : 'Sent'
                                    }
                                />
                                <DetailRow
                                    label="Counterparty"
                                    value={t.counterpartyName || '—'}
                                />
                                <DetailRow
                                    label="Counterparty account"
                                    value={t.counterpartyAccount || '—'}
                                    mono
                                />
                                <DetailRow
                                    label="Method"
                                    value={t.method || '—'}
                                />
                                <DetailRow label="Note" value={t.note || '—'} />
                                <DetailRow
                                    label="Created"
                                    value={t.createdAt}
                                />
                                <DetailRow
                                    label="Chain closed"
                                    value={t.chainClosedAt ?? 'Not yet closed'}
                                />
                                <DetailRow
                                    label="Idempotency key"
                                    value={t.idempotencyKey || '—'}
                                    mono
                                />
                            </dl>
                        </CardContent>
                    </Card>

                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Status</CardTitle>
                                <CardDescription>
                                    The ledger is the authority. This status is
                                    the projection maintained alongside it.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex items-center gap-3">
                                    <StatusBadge status={t.status} />
                                    <span className="text-sm text-muted-foreground">
                                        {t.statusLabel}
                                    </span>
                                </div>
                                <div className="flex items-center gap-3">
                                    <RiskBadge level={t.riskLevel} />
                                </div>
                            </CardContent>
                        </Card>

                        {t.status !== 'CHAIN_CLOSED' && (
                            <Card className="border-amber-500/30 bg-amber-500/[0.04]">
                                <CardHeader>
                                    <CardTitle className="text-amber-700 dark:text-amber-300">
                                        Not settled
                                    </CardTitle>
                                    <CardDescription>
                                        This transfer has not been closed on the
                                        chain. It must not be treated as settled
                                        money.
                                    </CardDescription>
                                </CardHeader>
                            </Card>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
