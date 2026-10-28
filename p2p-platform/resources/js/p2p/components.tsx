/**
 * Shared presentational components for the DB-connected P2P pages.
 *
 * These are presentational only — none of them performs a network call or a
 * database write. A component that reads real data receives it as a prop from
 * an Inertia page; a component that writes posts through an Inertia form.
 */

import { AlertTriangle, Ban, Info, Loader2, ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type {
    InstallmentStatus,
    KycStatus,
    LedgerDirection,
    LoanStatus,
    RiskLevel,
    TransactionStatus,
    WithdrawalStatus,
} from '@/p2p/types';
import { formatMoney } from '@/p2p/money';

/**
 * A prominent banner marking a screen or action that is NOT live.
 */
export function NoticeBanner({
    children,
    tone = 'warning',
    className,
}: {
    children?: ReactNode;
    tone?: 'info' | 'warning' | 'success' | 'blocked';
    className?: string;
}) {
    const styles = {
        info: 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300',
        warning:
            'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
        success:
            'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        blocked:
            'border-destructive/30 bg-destructive/10 text-destructive dark:text-red-400',
    } as const;

    const Icon =
        tone === 'warning'
            ? AlertTriangle
            : tone === 'success'
              ? ShieldCheck
              : tone === 'blocked'
                ? Ban
                : Info;

    return (
        <div
            className={cn(
                'flex items-start gap-2.5 rounded-lg border px-3.5 py-2.5 text-xs font-medium',
                styles[tone],
                className,
            )}
            role="status"
        >
            <Icon className="mt-0.5 size-4 shrink-0" />
            <span className="leading-relaxed">
                {children ??
                    'This screen shows sample data only. Nothing here writes to the ledger.'}
            </span>
        </div>
    );
}

/**
 * A KPI tile with a label, a value, an optional hint and an optional icon.
 */
export function StatTile({
    label,
    value,
    hint,
    icon,
    accent = 'default',
    className,
}: {
    label: string;
    value: ReactNode;
    hint?: ReactNode;
    icon?: ReactNode;
    accent?: 'default' | 'primary' | 'success' | 'warning' | 'danger';
    className?: string;
}) {
    const accents = {
        default: '',
        primary: 'border-primary/20 bg-primary/[0.04] dark:bg-primary/[0.06]',
        success:
            'border-emerald-500/25 bg-emerald-500/[0.05] dark:bg-emerald-500/[0.08]',
        warning:
            'border-amber-500/25 bg-amber-500/[0.05] dark:bg-amber-500/[0.08]',
        danger: 'border-destructive/25 bg-destructive/[0.05] dark:bg-destructive/[0.08]',
    } as const;

    return (
        <Card
            className={cn(
                'gap-0 border py-5 shadow-sm',
                accents[accent],
                className,
            )}
        >
            <CardContent className="px-5">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {label}
                        </p>
                        <p className="mt-2 text-2xl font-semibold tracking-tight tabular-nums">
                            {value}
                        </p>
                        {hint && (
                            <div className="mt-2 text-xs text-muted-foreground">
                                {hint}
                            </div>
                        )}
                    </div>
                    {icon && (
                        <div className="shrink-0 rounded-lg bg-muted p-2 text-muted-foreground">
                            {icon}
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * The colour mapping for a transaction status.
 */
export function StatusBadge({ status }: { status: TransactionStatus }) {
    const map: Record<TransactionStatus, { label: string; className: string }> =
        {
            CHAIN_CLOSED: {
                label: 'Settled',
                className:
                    'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
            },
            CHAIN_PENDING: {
                label: 'Pending',
                className:
                    'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300',
            },
            POSTED: {
                label: 'Posted',
                className:
                    'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300',
            },
            UNDER_REVIEW: {
                label: 'Under review',
                className:
                    'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
            },
            REVERSED: {
                label: 'Reversed',
                className:
                    'border-violet-500/30 bg-violet-500/10 text-violet-700 dark:text-violet-300',
            },
            FAILED: {
                label: 'Failed',
                className:
                    'border-destructive/30 bg-destructive/10 text-destructive dark:text-red-400',
            },
        };

    const entry = map[status] ?? map.CHAIN_PENDING;

    return (
        <Badge variant="outline" className={cn('font-medium', entry.className)}>
            {entry.label}
        </Badge>
    );
}

/**
 * The colour mapping for a KYC status.
 */
export function KycBadge({ status }: { status: KycStatus }) {
    const map: Record<KycStatus, { label: string; className: string }> = {
        NOT_SUBMITTED: {
            label: 'Not submitted',
            className: 'border-border bg-muted text-muted-foreground',
        },
        PENDING: {
            label: 'Pending',
            className:
                'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
        },
        IN_REVIEW: {
            label: 'In review',
            className:
                'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300',
        },
        APPROVED: {
            label: 'Approved',
            className:
                'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        },
        REJECTED: {
            label: 'Rejected',
            className:
                'border-destructive/30 bg-destructive/10 text-destructive dark:text-red-400',
        },
    };

    const entry = map[status] ?? map.NOT_SUBMITTED;

    return (
        <Badge variant="outline" className={cn('font-medium', entry.className)}>
            {entry.label}
        </Badge>
    );
}

/**
 * A risk-level pill.
 */
export function RiskBadge({ level }: { level: RiskLevel }) {
    const map: Record<RiskLevel, string> = {
        LOW: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        MEDIUM: 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
        HIGH: 'border-destructive/30 bg-destructive/10 text-destructive dark:text-red-400',
    };

    return (
        <Badge
            variant="outline"
            className={cn('font-medium', map[level] ?? map.LOW)}
        >
            {level.charAt(0) + level.slice(1).toLowerCase()}
        </Badge>
    );
}

/**
 * A signed amount, coloured by ledger direction.
 */
export function DirectionAmount({
    amountMinor,
    direction,
    className,
}: {
    amountMinor: number;
    direction: LedgerDirection;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'font-semibold tabular-nums',
                direction === 'CREDIT'
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-foreground',
                className,
            )}
        >
            {direction === 'CREDIT' ? '+' : '−'}
            {formatMoney(amountMinor)}
        </span>
    );
}

/**
 * An empty-state block.
 */
export function EmptyState({
    icon,
    title,
    description,
    action,
}: {
    icon?: ReactNode;
    title: string;
    description?: ReactNode;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center">
            {icon && (
                <div className="rounded-full bg-muted p-3 text-muted-foreground">
                    {icon}
                </div>
            )}
            <div className="space-y-1">
                <p className="font-medium">{title}</p>
                {description && (
                    <p className="max-w-sm text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {action}
        </div>
    );
}

/**
 * A loading placeholder block.
 */
export function LoadingBlock({ label = 'Loading…' }: { label?: string }) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 px-6 py-14 text-muted-foreground">
            <Loader2 className="size-5 animate-spin" />
            <p className="text-sm">{label}</p>
        </div>
    );
}

/**
 * A labelled horizontal meter, used for limits and progress.
 */
export function Meter({
    label,
    used,
    total,
    hint,
}: {
    label: string;
    used: number;
    total: number;
    hint?: string;
}) {
    const percent =
        total > 0 ? Math.min(100, Math.round((used / total) * 100)) : 0;

    return (
        <div className="space-y-2">
            <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="text-muted-foreground">{label}</span>
                <span className="font-medium tabular-nums">
                    {formatMoney(used)}{' '}
                    <span className="font-normal text-muted-foreground">
                        / {formatMoney(total)}
                    </span>
                </span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                <div
                    className={cn(
                        'h-full rounded-full transition-[width]',
                        percent >= 90
                            ? 'bg-destructive'
                            : percent >= 70
                              ? 'bg-amber-500'
                              : 'bg-primary',
                    )}
                    style={{ width: `${percent}%` }}
                />
            </div>
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

/**
 * A key/value pair for detail panels.
 */
export function DetailRow({
    label,
    value,
    mono = false,
}: {
    label: string;
    value: ReactNode;
    mono?: boolean;
}) {
    return (
        <div className="flex items-start justify-between gap-4 py-2.5">
            <dt className="shrink-0 text-sm text-muted-foreground">{label}</dt>
            <dd
                className={cn(
                    'text-right text-sm font-medium',
                    mono && 'font-mono text-xs',
                )}
            >
                {value}
            </dd>
        </div>
    );
}

/**
 * A simple responsive data table wrapper.
 *
 * There is no `table` component in the UI kit, so this provides the shared
 * look: a scrollable viewport with consistent header and row padding.
 */
export function DataTable({
    headers,
    children,
    className,
}: {
    headers: string[];
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'w-full overflow-x-auto rounded-lg border',
                className,
            )}
        >
            <table className="w-full min-w-[640px] border-collapse text-sm">
                <thead>
                    <tr className="border-b bg-muted/40">
                        {headers.map((header) => (
                            <th
                                key={header}
                                scope="col"
                                className="px-4 py-3 text-left text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y">{children}</tbody>
            </table>
        </div>
    );
}

/**
 * The colour mapping for a loan listing status.
 */
export function LoanStatusBadge({ status }: { status: LoanStatus }) {
    const map: Record<LoanStatus, { label: string; className: string }> = {
        MARKETPLACE_LISTED: {
            label: 'Awaiting funding',
            className:
                'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300',
        },
        DISBURSED: {
            label: 'Disbursed',
            className:
                'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        },
        REPAYING: {
            label: 'Repaying',
            className:
                'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
        },
        REPAID: {
            label: 'Repaid',
            className:
                'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        },
        DEFAULTED: {
            label: 'Defaulted',
            className:
                'border-destructive/30 bg-destructive/10 text-destructive dark:text-red-400',
        },
        CANCELLED: {
            label: 'Cancelled',
            className: 'border-border bg-muted text-muted-foreground',
        },
    };

    const entry = map[status] ?? map.MARKETPLACE_LISTED;

    return (
        <Badge variant="outline" className={cn('font-medium', entry.className)}>
            {entry.label}
        </Badge>
    );
}

/**
 * The colour mapping for an installment status.
 */
export function InstallmentStatusBadge({
    status,
}: {
    status: InstallmentStatus;
}) {
    const map: Record<InstallmentStatus, { label: string; className: string }> =
        {
            PENDING: {
                label: 'Upcoming',
                className: 'border-border bg-muted text-muted-foreground',
            },
            PAID: {
                label: 'Paid',
                className:
                    'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
            },
            GRACE_PERIOD: {
                label: 'Grace period',
                className:
                    'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
            },
            DEFAULTED: {
                label: 'Defaulted',
                className:
                    'border-destructive/30 bg-destructive/10 text-destructive dark:text-red-400',
            },
        };

    const entry = map[status] ?? map.PENDING;

    return (
        <Badge variant="outline" className={cn('font-medium', entry.className)}>
            {entry.label}
        </Badge>
    );
}

/**
 * The colour mapping for a withdrawal attempt status.
 */
export function WithdrawalStatusBadge({
    status,
}: {
    status: WithdrawalStatus;
}) {
    const map: Record<WithdrawalStatus, { label: string; className: string }> =
        {
            PENDING: {
                label: 'Awaiting confirmation',
                className:
                    'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300',
            },
            SUCCEEDED: {
                label: 'Payment Successful',
                className:
                    'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
            },
            FAILED: {
                label: 'Payment Failed',
                className:
                    'border-destructive/30 bg-destructive/10 text-destructive dark:text-red-400',
            },
            CANCELLED: {
                label: 'Payment Cancelled',
                className: 'border-border bg-muted text-muted-foreground',
            },
        };

    const entry = map[status] ?? map.PENDING;

    return (
        <Badge variant="outline" className={cn('font-medium', entry.className)}>
            {entry.label}
        </Badge>
    );
}

/**
 * The funding progress of a loan listing.
 *
 * This is the PRD's `ESCROW_LOCKED` view: the bar shows how much of the principal is
 * committed and locked in escrow, and how much is still to come.
 */
export function FundingMeter({
    fundedMinor,
    principalMinor,
}: {
    fundedMinor: number;
    principalMinor: number;
}) {
    const percent =
        principalMinor > 0
            ? Math.min(100, Math.round((fundedMinor / principalMinor) * 100))
            : 0;

    const remaining = Math.max(0, principalMinor - fundedMinor);

    return (
        <div className="space-y-2">
            <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="text-muted-foreground">Funding progress</span>
                <span className="font-medium tabular-nums">
                    {percent}%
                    <span className="font-normal text-muted-foreground">
                        {' '}
                        · {formatMoney(fundedMinor)} of{' '}
                        {formatMoney(principalMinor)} locked in escrow
                    </span>
                </span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                <div
                    className={cn(
                        'h-full rounded-full transition-[width]',
                        percent >= 100 ? 'bg-emerald-500' : 'bg-primary',
                    )}
                    style={{ width: `${percent}%` }}
                />
            </div>
            {remaining > 0 && (
                <p className="text-xs text-muted-foreground">
                    {formatMoney(remaining)} still to be committed.
                </p>
            )}
        </div>
    );
}
