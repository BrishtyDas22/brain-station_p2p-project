import { Head } from '@inertiajs/react';
import { Gavel, RefreshCcw } from 'lucide-react';
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
    DetailRow,
    EmptyState,
    StatTile,
} from '@/p2p/components';
import { formatMoney } from '@/p2p/money';
import { cn } from '@/lib/utils';

type Reversal = {
    id: number;
    transactionReference: string;
    requestedBy: string;
    amountMinor: number;
    currency: string;
    status: string;
    reason: string;
    createdAt: string;
    decidedAt: string | null;
    compensatingEntryId: number | null;
};

type Dispute = {
    id: number;
    transactionReference: string;
    openedBy: string;
    subject: string;
    category: string;
    status: string;
    priority: string;
    description: string;
    openedAt: string;
    resolvedAt: string | null;
};

type ReversalsProps = {
    reversals: Reversal[];
    disputes: Dispute[];
};

const REVERSAL_STYLES: Record<string, string> = {
    REQUESTED:
        'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
    APPROVED: 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300',
    EXECUTED:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    REJECTED:
        'border-destructive/30 bg-destructive/10 text-destructive dark:text-red-400',
};

const PRIORITY_STYLES: Record<string, string> = {
    HIGH: 'border-destructive/30 bg-destructive/10 text-destructive dark:text-red-400',
    MEDIUM: 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
    LOW: 'border-border bg-muted text-muted-foreground',
};

export default function AdminReversals({
    reversals,
    disputes,
}: ReversalsProps) {
    const openDisputes = disputes.filter((d) =>
        ['OPEN', 'IN_REVIEW'].includes(d.status),
    ).length;
    const pendingReversals = reversals.filter(
        (r) => r.status === 'REQUESTED',
    ).length;

    return (
        <>
            <Head title="Reversals & disputes" />

            <div className="space-y-6">
                <NoticeBanner tone="blocked">
                    The reversal and dispute tables are live records, but the
                    compensating-entry engine is not built yet. No reversal can
                    be executed and no dispute can be resolved from this screen
                    — the one-shot guard and the balance-protection rules are
                    enforced by the schema, not yet by a running service.
                </NoticeBanner>

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatTile
                        label="Reversal requests"
                        value={reversals.length}
                        hint={`${pendingReversals} awaiting a decision`}
                        icon={<RefreshCcw className="size-4" />}
                        accent={pendingReversals > 0 ? 'warning' : 'default'}
                    />
                    <StatTile
                        label="Disputes"
                        value={disputes.length}
                        hint={`${openDisputes} open or in review`}
                        icon={<Gavel className="size-4" />}
                        accent={openDisputes > 0 ? 'warning' : 'default'}
                    />
                    <StatTile
                        label="Compensating entries"
                        value={
                            reversals.filter(
                                (r) => r.compensatingEntryId !== null,
                            ).length
                        }
                        hint="Reversals that produced a compensating entry"
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Reversal requests</CardTitle>
                        <CardDescription>
                            A transaction can have at most one reversal — the
                            unique index on <code>transaction_id</code> enforces
                            that at the database level.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {reversals.length === 0 ? (
                            <EmptyState
                                icon={<RefreshCcw className="size-5" />}
                                title="No reversal requests"
                                description="No reversal has been requested against a transaction."
                            />
                        ) : (
                            <DataTable
                                headers={[
                                    'Reference',
                                    'Requested by',
                                    'Amount',
                                    'Status',
                                    'Reason',
                                    'Requested',
                                    'Decided',
                                ]}
                            >
                                {reversals.map((reversal) => (
                                    <tr
                                        key={reversal.id}
                                        className="hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3 font-mono text-xs font-medium">
                                            {reversal.transactionReference}
                                        </td>
                                        <td className="px-4 py-3">
                                            {reversal.requestedBy}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {formatMoney(reversal.amountMinor)}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant="outline"
                                                className={cn(
                                                    'font-medium',
                                                    REVERSAL_STYLES[
                                                        reversal.status
                                                    ] ?? '',
                                                )}
                                            >
                                                {reversal.status}
                                            </Badge>
                                        </td>
                                        <td className="max-w-[220px] px-4 py-3 text-xs text-muted-foreground">
                                            {reversal.reason || '—'}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                            {reversal.createdAt}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                            {reversal.decidedAt ?? 'Pending'}
                                        </td>
                                    </tr>
                                ))}
                            </DataTable>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Disputes</CardTitle>
                        <CardDescription>
                            Disputes raised against a transfer.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {disputes.length === 0 ? (
                            <EmptyState
                                icon={<Gavel className="size-5" />}
                                title="No disputes"
                                description="No dispute has been raised on the platform."
                            />
                        ) : (
                            <div className="space-y-3">
                                {disputes.map((dispute) => (
                                    <div
                                        key={dispute.id}
                                        className="rounded-lg border p-4"
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="font-medium">
                                                    {dispute.subject}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {
                                                        dispute.transactionReference
                                                    }{' '}
                                                    · opened by{' '}
                                                    {dispute.openedBy}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 gap-2">
                                                <Badge
                                                    variant="outline"
                                                    className={cn(
                                                        'font-medium',
                                                        PRIORITY_STYLES[
                                                            dispute.priority
                                                        ] ?? '',
                                                    )}
                                                >
                                                    {dispute.priority}
                                                </Badge>
                                                <Badge variant="outline">
                                                    {dispute.status}
                                                </Badge>
                                            </div>
                                        </div>

                                        {dispute.description && (
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                {dispute.description}
                                            </p>
                                        )}

                                        <dl className="mt-2 divide-y">
                                            <DetailRow
                                                label="Category"
                                                value={dispute.category}
                                            />
                                            <DetailRow
                                                label="Opened"
                                                value={dispute.openedAt}
                                            />
                                            <DetailRow
                                                label="Resolved"
                                                value={
                                                    dispute.resolvedAt ??
                                                    'Not resolved'
                                                }
                                            />
                                        </dl>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
