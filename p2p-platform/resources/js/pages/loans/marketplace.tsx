import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    Banknote,
    HandCoins,
    Plus,
    ShieldCheck,
    TrendingUp,
    Users,
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
import { Textarea } from '@/components/ui/textarea';
import { EmptyState, FundingMeter, LoanStatusBadge, NoticeBanner } from '@/p2p/components';
import { formatMoney } from '@/p2p/money';
import type { LoanStatus } from '@/p2p/types';

type Listing = {
    id: number;
    reference: string;
    principalMinor: number;
    fundedMinor: number;
    remainingMinor: number;
    fundingPercent: number;
    currency: string;
    interestRateBps: number;
    interestRateLabel: string;
    tenureMonths: number;
    purpose: string;
    status: LoanStatus;
    statusLabel: string;
    investorCount: number;
    createdAt: string;
    fundedAt: string;
    repaidAt: string;
};

type MarketplaceProps = {
    listings: Listing[];
    wallet: { balanceMinor: number; availableMinor: number };
    limits: { minPrincipalMinor: number; maxPrincipalMinor: number };
    canRequest: boolean;
};

export default function LoanMarketplace({
    listings,
    wallet,
    limits,
    canRequest,
}: MarketplaceProps) {
    const [showRequestForm, setShowRequestForm] = useState(false);

    const request = useForm({
        principal: '',
        tenure_months: '3',
        interest_rate_bps: '1200',
        purpose: '',
    });

    const minTaka = limits.minPrincipalMinor / 100;
    const maxTaka = limits.maxPrincipalMinor / 100;

    const submitRequest = (event: React.FormEvent) => {
        event.preventDefault();
        request.post('/loans');
    };

    return (
        <>
            <Head title="Loan Marketplace" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Loan Marketplace
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Borrow ৳{minTaka.toLocaleString()} – ৳
                            {maxTaka.toLocaleString()} from other members, or fund a
                            listing with your own wallet balance.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <div className="rounded-lg border bg-card px-4 py-3 text-right">
                            <p className="text-xs text-muted-foreground">
                                Available to commit
                            </p>
                            <p className="text-lg font-semibold tabular-nums">
                                {formatMoney(wallet.availableMinor)}
                            </p>
                        </div>

                        {canRequest ? (
                            <Button
                                type="button"
                                onClick={() => setShowRequestForm((open) => !open)}
                            >
                                <Plus className="size-4" />
                                Request a loan
                            </Button>
                        ) : (
                            <Button asChild variant="outline">
                                <Link href="/kyc">Complete KYC to borrow</Link>
                            </Button>
                        )}
                    </div>
                </div>

                {/* The request form. It only publishes a listing — no money moves
                    until a lender commits, and even then the money moves into
                    escrow, not to the borrower. */}
                {showRequestForm && canRequest && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Request a loan</CardTitle>
                            <CardDescription>
                                Your request is published on the marketplace
                                immediately. Lenders commit from their own wallet
                                balance, and you receive the principal only once the
                                loan is 100% funded.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submitRequest} className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-2">
                                        <Label htmlFor="principal">
                                            Amount (BDT)
                                        </Label>
                                        <Input
                                            id="principal"
                                            name="principal"
                                            inputMode="decimal"
                                            placeholder="20,000"
                                            value={request.data.principal}
                                            onChange={(event) =>
                                                request.setData(
                                                    'principal',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={Boolean(
                                                request.errors.principal,
                                            )}
                                        />
                                        {request.errors.principal && (
                                            <p className="text-sm text-destructive">
                                                {request.errors.principal}
                                            </p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="tenure_months">
                                            Repayment tenure
                                        </Label>
                                        <Select
                                            value={request.data.tenure_months}
                                            onValueChange={(value) =>
                                                request.setData(
                                                    'tenure_months',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger id="tenure_months">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {[1, 2, 3, 6, 9, 12].map(
                                                    (months) => (
                                                        <SelectItem
                                                            key={months}
                                                            value={String(months)}
                                                        >
                                                            {months} month
                                                            {months === 1
                                                                ? ''
                                                                : 's'}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        {request.errors.tenure_months && (
                                            <p className="text-sm text-destructive">
                                                {request.errors.tenure_months}
                                            </p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="interest_rate_bps">
                                            Interest rate
                                        </Label>
                                        <Input
                                            id="interest_rate_bps"
                                            name="interest_rate_bps"
                                            inputMode="numeric"
                                            value={request.data.interest_rate_bps}
                                            onChange={(event) =>
                                                request.setData(
                                                    'interest_rate_bps',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={Boolean(
                                                request.errors.interest_rate_bps,
                                            )}
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            In basis points — 1200 means 12% a
                                            year.
                                        </p>
                                        {request.errors.interest_rate_bps && (
                                            <p className="text-sm text-destructive">
                                                {
                                                    request.errors
                                                        .interest_rate_bps
                                                }
                                            </p>
                                        )}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="purpose">
                                        What is the loan for?
                                    </Label>
                                    <Textarea
                                        id="purpose"
                                        name="purpose"
                                        rows={3}
                                        placeholder="Working capital for a small trading inventory."
                                        value={request.data.purpose}
                                        onChange={(event) =>
                                            request.setData(
                                                'purpose',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={Boolean(
                                            request.errors.purpose,
                                        )}
                                    />
                                    {request.errors.purpose && (
                                        <p className="text-sm text-destructive">
                                            {request.errors.purpose}
                                        </p>
                                    )}
                                </div>

                                <div className="flex justify-end gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            setShowRequestForm(false)
                                        }
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={request.processing}
                                    >
                                        {request.processing
                                            ? 'Publishing…'
                                            : 'Publish request'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <NoticeBanner tone="info">
                    Funding a loan uses only your existing wallet balance — there is
                    no external payment step. Your capital is locked in escrow until
                    the loan is fully funded.
                </NoticeBanner>

                {listings.length === 0 ? (
                    <Card>
                        <EmptyState
                            icon={<HandCoins className="size-5" />}
                            title="No open listings right now"
                            description={
                                canRequest
                                    ? 'Be the first to list a loan request on the marketplace.'
                                    : 'Complete your KYC verification to request a loan.'
                            }
                        />
                    </Card>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {listings.map((listing) => (
                            <Card key={listing.id} className="gap-0 shadow-sm">
                                <CardHeader className="pb-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <CardTitle className="text-lg">
                                                {formatMoney(
                                                    listing.principalMinor,
                                                )}
                                            </CardTitle>
                                            <CardDescription className="mt-1 line-clamp-2">
                                                {listing.purpose}
                                            </CardDescription>
                                        </div>
                                        <LoanStatusBadge
                                            status={listing.status}
                                        />
                                    </div>
                                </CardHeader>

                                <CardContent className="space-y-4">
                                    <FundingMeter
                                        fundedMinor={listing.fundedMinor}
                                        principalMinor={listing.principalMinor}
                                    />

                                    <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Interest
                                            </dt>
                                            <dd className="font-medium">
                                                {listing.interestRateLabel}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Tenure
                                            </dt>
                                            <dd className="font-medium">
                                                {listing.tenureMonths} month
                                                {listing.tenureMonths === 1
                                                    ? ''
                                                    : 's'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Lenders
                                            </dt>
                                            <dd className="font-medium">
                                                {listing.investorCount}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Listed
                                            </dt>
                                            <dd className="font-medium">
                                                {listing.createdAt}
                                            </dd>
                                        </div>
                                    </dl>

                                    <Button asChild className="w-full">
                                        <Link href={`/loans/${listing.id}`}>
                                            Fund this loan
                                            <ArrowRight className="size-4" />
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-3">
                    <Card className="gap-0 border py-5 shadow-sm">
                        <CardContent className="flex items-start gap-3 px-5">
                            <span className="rounded-lg bg-muted p-2 text-muted-foreground">
                                <ShieldCheck className="size-4" />
                            </span>
                            <div>
                                <p className="text-sm font-medium">
                                    KYC-gated borrowers
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Only members with an approved identity
                                    verification can list a loan.
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="gap-0 border py-5 shadow-sm">
                        <CardContent className="flex items-start gap-3 px-5">
                            <span className="rounded-lg bg-muted p-2 text-muted-foreground">
                                <Banknote className="size-4" />
                            </span>
                            <div>
                                <p className="text-sm font-medium">
                                    Escrow-held capital
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Committed funds are locked until the loan is
                                    fully funded — you cannot withdraw them.
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="gap-0 border py-5 shadow-sm">
                        <CardContent className="flex items-start gap-3 px-5">
                            <span className="rounded-lg bg-muted p-2 text-muted-foreground">
                                <TrendingUp className="size-4" />
                            </span>
                            <div>
                                <p className="text-sm font-medium">
                                    Proportional returns
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Each installment is split across lenders in
                                    proportion to what they committed.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-card px-5 py-4">
                    <p className="text-sm text-muted-foreground">
                        Looking for your own loans or your commitments?
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href="/loans/my">
                                <Users className="size-4" />
                                My loans
                            </Link>
                        </Button>
                        <Button asChild variant="outline" size="sm">
                            <Link href="/loans/investments">
                                <HandCoins className="size-4" />
                                My investments
                            </Link>
                        </Button>
                    </div>
                </div>
            </div>
        </>
    );
}
