import { Head, useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    Circle,
    FileText,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
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
import { NoticeBanner, DetailRow, KycBadge } from '@/p2p/components';
import type { KycStatus } from '@/p2p/types';

type Submission = {
    id: number;
    status: KycStatus;
    statusLabel: string;
    fullName: string;
    documentType: string;
    documentNumberMasked: string;
    dateOfBirth: string;
    addressLine: string;
    city: string;
    country: string;
    mobileNumber: string;
    submittedAt: string;
    reviewedAt: string | null;
    reviewer: string | null;
    rejectionReason: string | null;
    riskScore: number | null;
};

type KycProps = {
    submission: Submission | null;
    status: KycStatus;
    statusLabel: string;
    documentTypes: string[];
};

export default function Kyc({
    submission,
    status,
    statusLabel,
    documentTypes,
}: KycProps) {
    const form = useForm({
        full_name: '',
        document_type: documentTypes[0] ?? 'National ID (NID)',
        document_number: '',
        date_of_birth: '',
        address_line: '',
        city: '',
        country: 'BD',
        mobile_number: '',
    });

    const canSubmit =
        submission === null ||
        submission.status === 'NOT_SUBMITTED' ||
        submission.status === 'REJECTED';

    const steps: {
        label: string;
        state: 'done' | 'current' | 'todo' | 'failed';
    }[] = [
        {
            label: 'Submitted',
            state:
                submission === null
                    ? 'todo'
                    : submission.status === 'REJECTED'
                      ? 'failed'
                      : 'done',
        },
        {
            label: 'In review',
            state:
                submission?.status === 'IN_REVIEW'
                    ? 'current'
                    : submission?.status === 'APPROVED'
                      ? 'done'
                      : 'todo',
        },
        {
            label: 'Approved',
            state: submission?.status === 'APPROVED' ? 'done' : 'todo',
        },
    ];

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post('/kyc');
    };

    return (
        <>
            <Head title="KYC verification" />

            <div className="space-y-6">
                {/* Status */}
                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <CardTitle className="flex items-center gap-2">
                                    <ShieldCheck className="size-4" />
                                    Identity verification
                                </CardTitle>
                                <CardDescription>
                                    Your details are stored in the database and
                                    reviewed by an administrator.
                                </CardDescription>
                            </div>
                            <KycBadge status={status} />
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        {/* Progress */}
                        <ol className="flex flex-wrap gap-4">
                            {steps.map((step) => {
                                const Icon =
                                    step.state === 'done'
                                        ? CheckCircle2
                                        : step.state === 'failed'
                                          ? XCircle
                                          : step.state === 'current'
                                            ? Circle
                                            : Circle;

                                return (
                                    <li
                                        key={step.label}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <Icon
                                            className={
                                                step.state === 'done'
                                                    ? 'size-4 text-emerald-600 dark:text-emerald-400'
                                                    : step.state === 'failed'
                                                      ? 'size-4 text-destructive'
                                                      : step.state === 'current'
                                                        ? 'size-4 text-sky-600 dark:text-sky-400'
                                                        : 'size-4 text-muted-foreground/40'
                                            }
                                        />
                                        <span
                                            className={
                                                step.state === 'todo'
                                                    ? 'text-muted-foreground'
                                                    : 'font-medium'
                                            }
                                        >
                                            {step.label}
                                        </span>
                                    </li>
                                );
                            })}
                        </ol>

                        {submission === null ? (
                            <NoticeBanner tone="info">
                                You have not submitted any identity details yet.
                                The form below writes a real row to the database
                                — nothing here is simulated.
                            </NoticeBanner>
                        ) : (
                            <>
                                {submission.status === 'REJECTED' && (
                                    <NoticeBanner tone="blocked">
                                        Your previous submission was rejected
                                        {submission.rejectionReason
                                            ? `: ${submission.rejectionReason}`
                                            : '.'}{' '}
                                        You may submit corrected details below.
                                    </NoticeBanner>
                                )}

                                {submission.status === 'APPROVED' && (
                                    <NoticeBanner tone="success">
                                        Your identity has been verified by an
                                        administrator. Your wallet is
                                        provisioned with a zero balance. Actual
                                        money transfers are not enabled yet.
                                    </NoticeBanner>
                                )}

                                {submission.status === 'PENDING' && (
                                    <NoticeBanner tone="info">
                                        Your submission is queued for review. An
                                        administrator will move it to review and
                                        record a decision.
                                    </NoticeBanner>
                                )}

                                <dl className="divide-y rounded-lg border">
                                    <DetailRow
                                        label="Full name"
                                        value={submission.fullName}
                                    />
                                    <DetailRow
                                        label="Document type"
                                        value={submission.documentType}
                                    />
                                    <DetailRow
                                        label="Document number"
                                        value={submission.documentNumberMasked}
                                        mono
                                    />
                                    <DetailRow
                                        label="Date of birth"
                                        value={submission.dateOfBirth || '—'}
                                    />
                                    <DetailRow
                                        label="Address"
                                        value={submission.addressLine || '—'}
                                    />
                                    <DetailRow
                                        label="City"
                                        value={submission.city || '—'}
                                    />
                                    <DetailRow
                                        label="Country"
                                        value={submission.country || '—'}
                                    />
                                    <DetailRow
                                        label="Mobile"
                                        value={submission.mobileNumber || '—'}
                                    />
                                    <DetailRow
                                        label="Submitted"
                                        value={submission.submittedAt || '—'}
                                    />
                                    <DetailRow
                                        label="Reviewed"
                                        value={
                                            submission.reviewedAt ?? 'Pending'
                                        }
                                    />
                                    <DetailRow
                                        label="Reviewer"
                                        value={submission.reviewer ?? '—'}
                                    />
                                </dl>
                            </>
                        )}
                    </CardContent>
                </Card>

                {/* Form */}
                {canSubmit ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <FileText className="size-4" />
                                {submission?.status === 'REJECTED'
                                    ? 'Resubmit your details'
                                    : 'Submit your details'}
                            </CardTitle>
                            <CardDescription>
                                These values are validated on the server and
                                written to MySQL.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="space-y-5">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="full_name">
                                            Full name
                                        </Label>
                                        <Input
                                            id="full_name"
                                            value={form.data.full_name}
                                            onChange={(event) =>
                                                form.setData(
                                                    'full_name',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                            maxLength={120}
                                        />
                                        {form.errors.full_name && (
                                            <p className="text-xs text-destructive">
                                                {form.errors.full_name}
                                            </p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="mobile_number">
                                            Mobile number
                                        </Label>
                                        <Input
                                            id="mobile_number"
                                            value={form.data.mobile_number}
                                            onChange={(event) =>
                                                form.setData(
                                                    'mobile_number',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="+8801XXXXXXXXX"
                                            required
                                            maxLength={20}
                                        />
                                        {form.errors.mobile_number && (
                                            <p className="text-xs text-destructive">
                                                {form.errors.mobile_number}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="document_type">
                                            Document type
                                        </Label>
                                        <Select
                                            value={form.data.document_type}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'document_type',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger id="document_type">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {documentTypes.map((type) => (
                                                    <SelectItem
                                                        key={type}
                                                        value={type}
                                                    >
                                                        {type}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.document_type && (
                                            <p className="text-xs text-destructive">
                                                {form.errors.document_type}
                                            </p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="document_number">
                                            Document number
                                        </Label>
                                        <Input
                                            id="document_number"
                                            value={form.data.document_number}
                                            onChange={(event) =>
                                                form.setData(
                                                    'document_number',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                            minLength={4}
                                            maxLength={40}
                                        />
                                        {form.errors.document_number && (
                                            <p className="text-xs text-destructive">
                                                {form.errors.document_number}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-2">
                                        <Label htmlFor="date_of_birth">
                                            Date of birth
                                        </Label>
                                        <Input
                                            id="date_of_birth"
                                            type="date"
                                            value={form.data.date_of_birth}
                                            onChange={(event) =>
                                                form.setData(
                                                    'date_of_birth',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        />
                                        {form.errors.date_of_birth && (
                                            <p className="text-xs text-destructive">
                                                {form.errors.date_of_birth}
                                            </p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="city">City</Label>
                                        <Input
                                            id="city"
                                            value={form.data.city}
                                            onChange={(event) =>
                                                form.setData(
                                                    'city',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                            maxLength={80}
                                        />
                                        {form.errors.city && (
                                            <p className="text-xs text-destructive">
                                                {form.errors.city}
                                            </p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="country">Country</Label>
                                        <Input
                                            id="country"
                                            value={form.data.country}
                                            onChange={(event) =>
                                                form.setData(
                                                    'country',
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={2}
                                        />
                                        {form.errors.country && (
                                            <p className="text-xs text-destructive">
                                                {form.errors.country}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="address_line">
                                        Address
                                    </Label>
                                    <Input
                                        id="address_line"
                                        value={form.data.address_line}
                                        onChange={(event) =>
                                            form.setData(
                                                'address_line',
                                                event.target.value,
                                            )
                                        }
                                        required
                                        maxLength={180}
                                    />
                                    {form.errors.address_line && (
                                        <p className="text-xs text-destructive">
                                            {form.errors.address_line}
                                        </p>
                                    )}
                                </div>

                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    {form.processing
                                        ? 'Submitting…'
                                        : 'Submit for review'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardContent className="px-0">
                            <div className="px-6 py-10 text-center">
                                <p className="font-medium">
                                    Your submission is{' '}
                                    {statusLabel.toLowerCase()}.
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    The form is locked while a decision is
                                    pending. You can edit your details again
                                    after a rejection.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}
