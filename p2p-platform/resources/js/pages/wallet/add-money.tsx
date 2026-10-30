import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Clock,
    CreditCard,
    Landmark,
    ShieldCheck,
    Smartphone,
    Wallet as WalletIcon,
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { NoticeBanner } from '@/p2p/components';
import { formatMoney } from '@/p2p/money';
import type { KycStatus } from '@/p2p/types';

type MethodOption = {
    value: string;
    label: string;
    description: string;
    inputKind: 'MOBILE' | 'BANK' | 'CARD';
};

type AddMoneyProps = {
    methods: MethodOption[];
    balance: { balanceMinor: number; currency: string };
    limits: { maxAmountMinor: number; dailyMaxAmountMinor: number };
    banks: string[];
    testCard: {
        number: string;
        expiry: string;
        cvv: string;
        holder: string;
    };
    environmentLabel: string;
    kyc: { status: KycStatus; statusLabel: string };
};

const METHOD_ICONS: Record<string, typeof Smartphone> = {
    BKASH: Smartphone,
    NAGAD: Smartphone,
    BANK_TRANSFER: Landmark,
    CARD: CreditCard,
};

/**
 * What the page shows for each KYC status that is not APPROVED.
 *
 * `PENDING` and `IN_REVIEW` share one entry: both mean the application has
 * been submitted and is being looked at, so the user is told the same thing.
 * `NOT_SUBMITTED` and `REJECTED` each carry a link to the KYC page, because
 * both have something for the user to do there — start the application, or
 * correct and resubmit it.
 */
const KYC_GATE: Record<
    Exclude<KycStatus, 'APPROVED'>,
    {
        title: string;
        message: string;
        tone: 'info' | 'blocked';
        action?: { label: string; href: string };
    }
> = {
    NOT_SUBMITTED: {
        title: 'KYC verification required',
        message: 'Please complete KYC verification before adding money.',
        tone: 'info',
        action: { label: 'Complete KYC', href: '/kyc' },
    },
    PENDING: {
        title: 'KYC verification in progress',
        message:
            'Your KYC verification is under review. You can add money once it is approved.',
        tone: 'info',
    },
    IN_REVIEW: {
        title: 'KYC verification in progress',
        message:
            'Your KYC verification is under review. You can add money once it is approved.',
        tone: 'info',
    },
    REJECTED: {
        title: 'KYC verification not approved',
        message:
            'Your KYC verification was not approved. Please update and resubmit your details.',
        tone: 'blocked',
        action: { label: 'Update and resubmit', href: '/kyc' },
    },
};

export default function AddMoney({
    methods,
    balance,
    limits,
    banks,
    testCard,
    environmentLabel,
    kyc,
}: AddMoneyProps) {
    const [method, setMethod] = useState<string>(methods[0]?.value ?? '');

    const { data, setData, post, processing, errors } = useForm({
        method: methods[0]?.value ?? '',
        amount: '',
        mobile_number: '',
        bank: banks[0] ?? '',
        account_number: '',
        card_number: testCard.number,
        card_expiry: testCard.expiry,
        card_cvv: testCard.cvv,
        card_holder: testCard.holder,
    });

    const selected = methods.find((m) => m.value === method) ?? methods[0];

    const chooseMethod = (value: string) => {
        setMethod(value);
        setData('method', value);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post('/wallet/fundings');
    };

    const maxTaka = limits.maxAmountMinor / 100;

    return (
        <>
            <Head title="Add Money" />

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
                            Add Money
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Top up your wallet with bKash, Nagad, a bank
                            transfer or a card.
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

                {/* One, accurate, unobtrusive note about the environment. It sits in the
                    page chrome — not on the payment form itself. */}
                <p className="text-xs text-muted-foreground">
                    {environmentLabel}
                </p>

                {/*
                    The KYC gate. When the identity is not approved, the form is
                    replaced by an explanation and a way forward.

                    This is PRESENTATION ONLY. The authorisation boundary is the
                    service's `assertEligible()`, which the store route goes
                    through, so a client that posts anyway is still refused.
                */}
                {kyc.status !== 'APPROVED' ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                {kyc.status === 'REJECTED' ? (
                                    <ShieldCheck className="size-4" />
                                ) : (
                                    <Clock className="size-4" />
                                )}
                                {KYC_GATE[kyc.status].title}
                            </CardTitle>
                            <CardDescription>
                                Adding money needs an approved identity. Your
                                current verification state is{' '}
                                {kyc.statusLabel.toLowerCase()}.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <NoticeBanner tone={KYC_GATE[kyc.status].tone}>
                                {KYC_GATE[kyc.status].message}
                            </NoticeBanner>

                            <div className="flex flex-wrap gap-2">
                                {KYC_GATE[kyc.status].action && (
                                    <Button asChild>
                                        <Link
                                            href={
                                                KYC_GATE[kyc.status].action!
                                                    .href
                                            }
                                        >
                                            {KYC_GATE[kyc.status].action!.label}
                                        </Link>
                                    </Button>
                                )}

                                <Button asChild variant="outline">
                                    <Link href="/wallet">Back to wallet</Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                ) : (
                    <form onSubmit={submit} className="space-y-6">
                        {/* Amount */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Amount</CardTitle>
                                <CardDescription>
                                    Enter the amount you want to add. The
                                    maximum per payment is ৳
                                    {maxTaka.toLocaleString()}.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-2">
                                    <Label htmlFor="amount">Amount (BDT)</Label>
                                    <div className="relative max-w-xs">
                                        <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground">
                                            ৳
                                        </span>
                                        <Input
                                            id="amount"
                                            name="amount"
                                            inputMode="decimal"
                                            placeholder="5,000"
                                            value={data.amount}
                                            onChange={(event) =>
                                                setData(
                                                    'amount',
                                                    event.target.value,
                                                )
                                            }
                                            className="pl-7"
                                            aria-invalid={Boolean(
                                                errors.amount,
                                            )}
                                        />
                                    </div>
                                    {errors.amount && (
                                        <p className="text-sm text-destructive">
                                            {errors.amount}
                                        </p>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Method */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Payment method</CardTitle>
                                <CardDescription>
                                    Choose how you want to pay.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {methods.map((option) => {
                                        const Icon =
                                            METHOD_ICONS[option.value] ??
                                            WalletIcon;
                                        const active = option.value === method;

                                        return (
                                            <button
                                                key={option.value}
                                                type="button"
                                                onClick={() =>
                                                    chooseMethod(option.value)
                                                }
                                                aria-pressed={active}
                                                className={`flex items-start gap-3 rounded-lg border p-4 text-left transition-colors ${
                                                    active
                                                        ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                                        : 'hover:bg-muted/50'
                                                }`}
                                            >
                                                <span
                                                    className={`flex size-9 shrink-0 items-center justify-center rounded-md ${
                                                        active
                                                            ? 'bg-primary/10 text-primary'
                                                            : 'bg-muted text-muted-foreground'
                                                    }`}
                                                >
                                                    <Icon className="size-4" />
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="block text-sm font-medium">
                                                        {option.label}
                                                    </span>
                                                    <span className="block text-xs text-muted-foreground">
                                                        {option.description}
                                                    </span>
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                                {errors.method && (
                                    <p className="mt-3 text-sm text-destructive">
                                        {errors.method}
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        {/* Method-specific details */}
                        {selected && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        {selected.label} details
                                    </CardTitle>
                                    <CardDescription>
                                        {selected.description}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {selected.inputKind === 'MOBILE' && (
                                        <div className="space-y-2">
                                            <Label htmlFor="mobile_number">
                                                Mobile number
                                            </Label>
                                            <Input
                                                id="mobile_number"
                                                name="mobile_number"
                                                inputMode="numeric"
                                                placeholder="01700000000"
                                                value={data.mobile_number}
                                                onChange={(event) =>
                                                    setData(
                                                        'mobile_number',
                                                        event.target.value,
                                                    )
                                                }
                                                className="max-w-xs"
                                                aria-invalid={Boolean(
                                                    errors.mobile_number,
                                                )}
                                            />
                                            {errors.mobile_number && (
                                                <p className="text-sm text-destructive">
                                                    {errors.mobile_number}
                                                </p>
                                            )}
                                        </div>
                                    )}

                                    {selected.inputKind === 'BANK' && (
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label htmlFor="bank">
                                                    Bank
                                                </Label>
                                                <Select
                                                    value={data.bank}
                                                    onValueChange={(value) =>
                                                        setData('bank', value)
                                                    }
                                                >
                                                    <SelectTrigger id="bank">
                                                        <SelectValue placeholder="Choose a bank" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {banks.map((bank) => (
                                                            <SelectItem
                                                                key={bank}
                                                                value={bank}
                                                            >
                                                                {bank}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                {errors.bank && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.bank}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="account_number">
                                                    Account number
                                                </Label>
                                                <Input
                                                    id="account_number"
                                                    name="account_number"
                                                    inputMode="numeric"
                                                    placeholder="0000000000"
                                                    value={data.account_number}
                                                    onChange={(event) =>
                                                        setData(
                                                            'account_number',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={Boolean(
                                                        errors.account_number,
                                                    )}
                                                />
                                                {errors.account_number && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.account_number}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    {selected.inputKind === 'CARD' && (
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="space-y-2 sm:col-span-2">
                                                <Label htmlFor="card_number">
                                                    Card number
                                                </Label>
                                                <Input
                                                    id="card_number"
                                                    name="card_number"
                                                    inputMode="numeric"
                                                    value={data.card_number}
                                                    onChange={(event) =>
                                                        setData(
                                                            'card_number',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="font-mono"
                                                    aria-invalid={Boolean(
                                                        errors.card_number,
                                                    )}
                                                />
                                                {errors.card_number && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.card_number}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="card_expiry">
                                                    Expiry
                                                </Label>
                                                <Input
                                                    id="card_expiry"
                                                    name="card_expiry"
                                                    placeholder="MM/YY"
                                                    value={data.card_expiry}
                                                    onChange={(event) =>
                                                        setData(
                                                            'card_expiry',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="font-mono"
                                                    aria-invalid={Boolean(
                                                        errors.card_expiry,
                                                    )}
                                                />
                                                {errors.card_expiry && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.card_expiry}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="card_cvv">
                                                    Security code
                                                </Label>
                                                <Input
                                                    id="card_cvv"
                                                    name="card_cvv"
                                                    inputMode="numeric"
                                                    value={data.card_cvv}
                                                    onChange={(event) =>
                                                        setData(
                                                            'card_cvv',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="font-mono"
                                                    aria-invalid={Boolean(
                                                        errors.card_cvv,
                                                    )}
                                                />
                                                {errors.card_cvv && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.card_cvv}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="space-y-2 sm:col-span-2">
                                                <Label htmlFor="card_holder">
                                                    Name on card
                                                </Label>
                                                <Input
                                                    id="card_holder"
                                                    name="card_holder"
                                                    value={data.card_holder}
                                                    onChange={(event) =>
                                                        setData(
                                                            'card_holder',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={Boolean(
                                                        errors.card_holder,
                                                    )}
                                                />
                                                {errors.card_holder && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.card_holder}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        <div className="flex justify-end gap-2">
                            <Button asChild variant="outline" type="button">
                                <Link href="/wallet">Cancel</Link>
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing
                                    ? 'Processing…'
                                    : 'Continue to payment'}
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </>
    );
}
