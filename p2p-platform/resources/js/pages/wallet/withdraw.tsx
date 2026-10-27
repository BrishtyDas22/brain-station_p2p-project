import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Landmark,
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

type MethodOption = {
    value: string;
    label: string;
    description: string;
    inputKind: 'MOBILE' | 'BANK';
};

type WithdrawProps = {
    methods: MethodOption[];
    balance: {
        balanceMinor: number;
        availableMinor: number;
        currency: string;
    };
    limits: { maxAmountMinor: number };
    banks: string[];
    kycApproved: boolean;
    environmentLabel: string;
};

const METHOD_ICONS: Record<string, typeof Smartphone> = {
    BKASH: Smartphone,
    NAGAD: Smartphone,
    BANK_TRANSFER: Landmark,
};

export default function WithdrawMoney({
    methods,
    balance,
    limits,
    banks,
    kycApproved,
    environmentLabel,
}: WithdrawProps) {
    const [method, setMethod] = useState<string>(methods[0]?.value ?? '');

    const { data, setData, post, processing, errors } = useForm({
        method: methods[0]?.value ?? '',
        amount: '',
        mobile_number: '',
        bank: banks[0] ?? '',
        account_number: '',
        account_holder: '',
    });

    const selected = methods.find((m) => m.value === method) ?? methods[0];

    const chooseMethod = (value: string) => {
        setMethod(value);
        setData('method', value);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post('/wallet/withdrawals');
    };

    const maxTaka = limits.maxAmountMinor / 100;

    return (
        <>
            <Head title="Withdraw Money" />

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
                            Withdraw Money
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Send money from your wallet to a bKash, Nagad or bank
                            account.
                        </p>
                    </div>

                    <div className="rounded-lg border bg-card px-4 py-3 text-right">
                        <p className="text-xs text-muted-foreground">
                            Available balance
                        </p>
                        <p className="text-lg font-semibold tabular-nums">
                            {formatMoney(balance.availableMinor)}
                        </p>
                    </div>
                </div>

                {!kycApproved && (
                    <NoticeBanner tone="blocked">
                        Your identity must be approved before you can withdraw
                        money.{' '}
                        <Link
                            href="/kyc"
                            className="font-semibold underline underline-offset-4"
                        >
                            Complete your KYC verification
                        </Link>
                        .
                    </NoticeBanner>
                )}

                <p className="text-xs text-muted-foreground">
                    {environmentLabel}
                </p>

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Amount</CardTitle>
                            <CardDescription>
                                Enter the amount you want to withdraw. The maximum
                                per payout is ৳{maxTaka.toLocaleString()}.
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
                                            setData('amount', event.target.value)
                                        }
                                        className="pl-7"
                                        aria-invalid={Boolean(errors.amount)}
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

                    <Card>
                        <CardHeader>
                            <CardTitle>Withdrawal method</CardTitle>
                            <CardDescription>
                                Choose where the money should be sent.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-3 sm:grid-cols-3">
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

                    {selected && (
                        <Card>
                            <CardHeader>
                                <CardTitle>{selected.label} details</CardTitle>
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
                                        <p className="text-xs text-muted-foreground">
                                            We store only the last four digits. A
                                            PIN or OTP is never collected.
                                        </p>
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

                                        <div className="space-y-2 sm:col-span-2">
                                            <Label htmlFor="account_holder">
                                                Account holder name
                                            </Label>
                                            <Input
                                                id="account_holder"
                                                name="account_holder"
                                                value={data.account_holder}
                                                onChange={(event) =>
                                                    setData(
                                                        'account_holder',
                                                        event.target.value,
                                                    )
                                                }
                                                className="max-w-sm"
                                                aria-invalid={Boolean(
                                                    errors.account_holder,
                                                )}
                                            />
                                            {errors.account_holder && (
                                                <p className="text-sm text-destructive">
                                                    {errors.account_holder}
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
                            {processing ? 'Processing…' : 'Continue to payout'}
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
