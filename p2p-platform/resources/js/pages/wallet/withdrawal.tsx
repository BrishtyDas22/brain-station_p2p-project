import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    Landmark,
    ShieldCheck,
    Smartphone,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { DetailRow, WithdrawalStatusBadge } from '@/p2p/components';
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

type MethodInfo = {
    value: string;
    label: string;
    description: string;
    inputKind: 'MOBILE' | 'BANK';
};

type WithdrawalProps = {
    withdrawal: Withdrawal;
    method: MethodInfo;
    details: Record<string, string>;
    environmentLabel: string;
};

const METHOD_ICONS: Record<string, typeof Smartphone> = {
    BKASH: Smartphone,
    NAGAD: Smartphone,
    BANK_TRANSFER: Landmark,
};

export default function Withdrawal({
    withdrawal,
    method,
    details,
    environmentLabel,
}: WithdrawalProps) {
    const [busy, setBusy] = useState<string | null>(null);

    const flash = (usePage().props as { flash?: { success?: string } }).flash
        ?.success;

    const Icon = METHOD_ICONS[withdrawal.method] ?? Smartphone;

    const settle = (intent: 'CONFIRM' | 'FAIL' | 'CANCEL') => {
        setBusy(intent);
        router.post(
            `/wallet/withdrawals/${withdrawal.id}/settle`,
            { intent },
            {
                preserveScroll: true,
                onFinish: () => setBusy(null),
            },
        );
    };

    const isPending = withdrawal.status === 'PENDING';
    const succeeded = withdrawal.status === 'SUCCEEDED';

    return (
        <>
            <Head
                title={
                    succeeded
                        ? 'Payment Successful'
                        : 'Complete your withdrawal'
                }
            />

            <div className="mx-auto max-w-2xl space-y-6">
                <div>
                    <Button asChild variant="ghost" size="sm">
                        <Link href="/wallet">
                            <ArrowLeft className="size-4" />
                            Back to wallet
                        </Link>
                    </Button>
                    <h1 className="mt-2 text-2xl font-semibold tracking-tight">
                        {succeeded
                            ? 'Payment Successful'
                            : withdrawal.status === 'FAILED'
                              ? 'Payment Failed'
                              : withdrawal.status === 'CANCELLED'
                                ? 'Payment Cancelled'
                                : 'Complete your withdrawal'}
                    </h1>
                </div>

                {flash && (
                    <p
                        className={`rounded-lg border px-4 py-3 text-sm ${
                            succeeded
                                ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                : 'border-destructive/30 bg-destructive/10 text-destructive dark:text-red-400'
                        }`}
                    >
                        {flash}
                    </p>
                )}

                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-3">
                            <span className="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <Icon className="size-5" />
                            </span>
                            <div>
                                <CardTitle>{method.label}</CardTitle>
                                <CardDescription>
                                    {succeeded
                                        ? 'The amount has been sent to your account.'
                                        : method.description}
                                </CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="rounded-lg border bg-muted/30 p-5 text-center">
                            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                Amount
                            </p>
                            <p className="mt-1 text-3xl font-semibold tracking-tight tabular-nums">
                                {formatMoney(withdrawal.amountMinor)}
                            </p>
                        </div>

                        <dl className="divide-y">
                            <DetailRow
                                label="Reference"
                                value={withdrawal.reference}
                                mono
                            />
                            <DetailRow
                                label="Method"
                                value={withdrawal.methodLabel}
                            />
                            <DetailRow
                                label="Status"
                                value={
                                    <WithdrawalStatusBadge
                                        status={withdrawal.status}
                                    />
                                }
                            />
                            <DetailRow
                                label="Created"
                                value={withdrawal.createdAt}
                            />
                            {withdrawal.settledAt && (
                                <DetailRow
                                    label="Completed"
                                    value={withdrawal.settledAt}
                                />
                            )}
                            {withdrawal.failureReason && (
                                <DetailRow
                                    label="Reason"
                                    value={withdrawal.failureReason}
                                />
                            )}
                            {details.mobile_number && (
                                <DetailRow
                                    label="Mobile number"
                                    value={details.mobile_number}
                                    mono
                                />
                            )}
                            {details.bank && (
                                <DetailRow
                                    label="Bank"
                                    value={details.bank}
                                />
                            )}
                            {details.account_number && (
                                <DetailRow
                                    label="Account"
                                    value={details.account_number}
                                    mono
                                />
                            )}
                            {details.account_holder && (
                                <DetailRow
                                    label="Account holder"
                                    value={details.account_holder}
                                />
                            )}
                        </dl>
                    </CardContent>
                </Card>

                {/* Actions — only while the payout is still pending */}
                {isPending && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Confirm the payout</CardTitle>
                            <CardDescription>
                                The gateway confirms the outcome. You can also
                                cancel at this step.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            <Button
                                onClick={() => settle('CONFIRM')}
                                disabled={busy !== null}
                            >
                                {busy === 'CONFIRM'
                                    ? 'Processing…'
                                    : 'Confirm payout'}
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => settle('CANCEL')}
                                disabled={busy !== null}
                            >
                                {busy === 'CANCEL'
                                    ? 'Processing…'
                                    : 'Cancel payout'}
                            </Button>
                        </CardContent>
                    </Card>
                )}

                {/* Settled — offer the next step */}
                {!isPending && (
                    <div className="flex flex-wrap gap-2">
                        <Button asChild>
                            <Link href="/wallet">
                                <ShieldCheck className="size-4" />
                                Back to wallet
                            </Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/wallet/withdrawals/history">
                                Withdrawal history
                            </Link>
                        </Button>
                    </div>
                )}

                <p className="text-xs text-muted-foreground">
                    {environmentLabel}
                </p>
            </div>
        </>
    );
}
