import { Head } from '@inertiajs/react';
import { RefreshCcw } from 'lucide-react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
    DataTable,
    NoticeBanner,
    EmptyState,
    StatTile,
} from '@/p2p/components';
import { formatMoney } from '@/p2p/money';
import { cn } from '@/lib/utils';

type Batch = {
    id: number;
    reference: string;
    network: string;
    status: 'PENDING' | 'PROCESSING' | 'SETTLED' | 'FAILED';
    entryCount: number;
    totalMinor: number;
    feeMinor: number;
    netMinor: number;
    currency: string;
    openedAt: string;
    settledAt: string | null;
    failureReason: string | null;
};

type SettlementProps = {
    batches: Batch[];
    totals: {
        batches: number;
        settled: number;
        pending: number;
        processing: number;
        failed: number;
        netMinor: number;
        entries: number;
    };
};

const STATUS_STYLES: Record<Batch['status'], string> = {
    SETTLED:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    PROCESSING:
        'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300',
    PENDING:
        'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
    FAILED: 'border-destructive/30 bg-destructive/10 text-destructive dark:text-red-400',
};

export default function AdminSettlement({ batches, totals }: SettlementProps) {
    return (
        <>
            <Head title="Settlement" />

            <div className="space-y-6">
                <NoticeBanner tone="info">
                    Settlement batches are read from the database. The
                    settlement network itself is not connected — no batch is
                    dispatched to a bank or mobile operator, and no settled
                    figure here represents money in transit.
                </NoticeBanner>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatTile
                        label="Batches"
                        value={totals.batches}
                        hint={`${totals.entries} ledger entries in scope`}
                        icon={<RefreshCcw className="size-4" />}
                    />
                    <StatTile
                        label="Settled"
                        value={totals.settled}
                        hint={`${totals.processing} processing`}
                        accent="success"
                    />
                    <StatTile
                        label="Pending"
                        value={totals.pending}
                        hint={`${totals.failed} failed`}
                        accent={totals.failed > 0 ? 'danger' : 'warning'}
                    />
                    <StatTile
                        label="Net settled value"
                        value={formatMoney(totals.netMinor)}
                        hint="Sum of settled batch net amounts"
                        accent="primary"
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Settlement batches</CardTitle>
                        <CardDescription>
                            Each batch groups ledger entries for a single
                            network.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {batches.length === 0 ? (
                            <EmptyState
                                icon={<RefreshCcw className="size-5" />}
                                title="No settlement batches"
                                description="No batch has been opened. Batches are created when a settlement run groups closed ledger entries."
                            />
                        ) : (
                            <DataTable
                                headers={[
                                    'Reference',
                                    'Network',
                                    'Status',
                                    'Entries',
                                    'Gross',
                                    'Fees',
                                    'Net',
                                    'Opened',
                                ]}
                            >
                                {batches.map((batch) => (
                                    <tr
                                        key={batch.id}
                                        className="hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3 font-mono text-xs font-medium">
                                            {batch.reference}
                                        </td>
                                        <td className="px-4 py-3">
                                            {batch.network}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant="outline"
                                                className={cn(
                                                    'font-medium',
                                                    STATUS_STYLES[batch.status],
                                                )}
                                            >
                                                {batch.status}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {batch.entryCount}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {formatMoney(batch.totalMinor)}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground tabular-nums">
                                            {formatMoney(batch.feeMinor)}
                                        </td>
                                        <td className="px-4 py-3 font-medium tabular-nums">
                                            {formatMoney(batch.netMinor)}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                            {batch.openedAt}
                                            {batch.failureReason && (
                                                <p className="text-xs text-destructive">
                                                    {batch.failureReason}
                                                </p>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </DataTable>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
