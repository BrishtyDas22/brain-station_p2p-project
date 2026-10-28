import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Building2,
    CheckCircle2,
    CreditCard,
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
import { DetailRow } from '@/p2p/components';
import { formatMoney } from '@/p2p/money';

type Funding = {
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

type MethodInfo = {
    value: string;
    label: string;
    description: string;
    inputKind: 'MOBILE' | 'BANK' | 'CARD';
};

type FundingProps = {
    funding: Funding;
    method: MethodInfo;
    details: Record<string, string>;
    banks: string[];
    testCard: {
        number: string;
        expiry: string;
        cvv: string;
        holder: string;
    };
    environmentLabel: string;
};

const METHOD_ICONS: Record<string, typeof Smartphone> = {
    BKASH: Smartphone,
    NAGAD: Smartphone,
    BANK_TRANSFER: Landmark,
    CARD: CreditCard,
};

export default function Funding({
    funding,
    method,
    details,
    environmentLabel,
}: FundingProps) {
    const [busy, setBusy] = useState<string | null>(null);

    const flash = (usePage().props as { flash?: { success?: string } }).flash
        ?.success;

    const Icon = METHOD_ICONS[funding.method] ?? CreditCard;

    const settle = (intent: 'CONFIRM' | 'FAIL' | 'CANCEL') => {
        setBusy(intent);
        router.post(
            `/wallet/fundings/${funding.id}/settle`,
            { intent },
            {
                preserveScroll: true,
                onFinish: () => setBusy(null),
            },
        );
    };

    const isPending = funding.status === 'PENDING';
    const succeeded = funding.status === 'SUCCEEDED';

    return (
        <>
            <Head
                title={succeeded ? 'Payment Successful' : 'Complete payment'}
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
                            : funding.status === 'FAILED'
                              ? 'Payment Failed'
                              : funding.status === 'CANCELLED'
                                ? 'Payment Cancelled'
                                : 'Complete your payment'}
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

                {/* The receipt */}
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
                                        ? 'The amount has been added to your wallet.'
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
                                {formatMoney(funding.amountMinor)}
                            </p>
                        </div>

                        <dl className="divide-y">
                            <DetailRow
                                label="Reference"
                                value={funding.reference}
                                mono
                            />
                            <DetailRow
                                label="Method"
                                value={funding.methodLabel}
                            />
                            <DetailRow
                                label="Status"
                                value={
                                    <span
                                        className={`inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium ${
                                            succeeded
                                                ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                                : funding.status === 'FAILED'
                                                  ? 'bg-destructive/10 text-destructive dark:text-red-400'
                                                  : funding.status ===
                                                      'CANCELLED'
                                                    ? 'bg-muted text-muted-foreground'
                                                    : 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
                                        }`}
                                    >
                                        {succeeded && (
                                            <CheckCircle2 className="size-3.5" />
                                        )}
                                        {(funding.status === 'FAILED' ||
                                            funding.status === 'CANCELLED') && (
                                            <XCircle className="size-3.5" />
                                        )}
                                        {funding.statusLabel}
                                    </span>
                                }
                            />
                            <DetailRow
                                label="Created"
                                value={funding.createdAt}
                            />
                            {funding.settledAt && (
                                <DetailRow
                                    label="Completed"
                                    value={funding.settledAt}
                                />
                            )}
                            {funding.failureReason && (
                                <DetailRow
                                    label="Reason"
                                    value={funding.failureReason}
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
                                <DetailRow label="Bank" value={details.bank} />
                            )}
                            {details.account_number && (
                                <DetailRow
                                    label="Account"
                                    value={details.account_number}
                                    mono
                                />
                            )}
                            {details.card_last_four && (
                                <DetailRow
                                    label="Card"
                                    value={`•••• ${details.card_last_four}`}
                                    mono
                                />
                            )}
                            {details.card_holder && (
                                <DetailRow
                                    label="Card holder"
                                    value={details.card_holder}
                                />
                            )}
                        </dl>
                    </CardContent>
                </Card>

                {/* Actions — only while the payment is still pending */}
                {isPending && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Confirm the payment</CardTitle>
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
                                    : 'Confirm payment'}
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => settle('CANCEL')}
                                disabled={busy !== null}
                            >
                                {busy === 'CANCEL'
                                    ? 'Processing…'
                                    : 'Cancel payment'}
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
                            <Link href="/transactions">
                                <Building2 className="size-4" />
                                Transaction history
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
