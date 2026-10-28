import { Head, router, useForm } from '@inertiajs/react';
import { CheckCircle2, FileSearch, Search, XCircle } from 'lucide-react';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { DataTable, KycBadge } from '@/p2p/components';
import type { KycStatus } from '@/p2p/types';

type SubmissionRow = {
    id: number;
    userId: number;
    applicantName: string;
    applicantEmail: string;
    nationalIdMasked: string;
    documentType: string;
    dateOfBirth: string;
    city: string;
    mobileNumber: string;
    status: KycStatus;
    statusLabel: string;
    riskScore: number | null;
    rejectionReason: string | null;
    submittedAt: string;
    reviewedAt: string | null;
    reviewer: string | null;
};

type KycReviewProps = {
    submissions: {
        data: SubmissionRow[];
        from: number | null;
        to: number | null;
        total: number;
        current_page: number;
        last_page: number;
    };
    filters: { status: string };
    statuses: string[];
};

export default function AdminKycReview({
    submissions,
    filters,
    statuses,
}: KycReviewProps) {
    const [status, setStatus] = useState(filters.status);

    const rows = submissions.data ?? [];

    return (
        <>
            <Head title="KYC review" />

            <div className="space-y-6">
                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <CardTitle className="flex items-center gap-2">
                                    <FileSearch className="size-4" />
                                    Review queue
                                </CardTitle>
                                <CardDescription>
                                    {submissions.total} application
                                    {submissions.total === 1 ? '' : 's'} on
                                    file. Every decision is validated
                                    server-side against the KYC state machine
                                    and written to the audit chain.
                                </CardDescription>
                            </div>

                            <Select
                                value={status}
                                onValueChange={(value) => {
                                    setStatus(value);
                                    router.get(
                                        '/admin/kyc',
                                        { status: value },
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                            replace: true,
                                        },
                                    );
                                }}
                            >
                                <SelectTrigger
                                    className="w-[170px]"
                                    aria-label="Status"
                                >
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="ALL">
                                        All statuses
                                    </SelectItem>
                                    {statuses.map((option) => (
                                        <SelectItem key={option} value={option}>
                                            {option.replace(/_/g, ' ')}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {rows.length === 0 ? (
                            <p className="py-10 text-center text-sm text-muted-foreground">
                                No applications match the current filter.
                            </p>
                        ) : (
                            <DataTable
                                headers={[
                                    'Applicant',
                                    'Document',
                                    'Status',
                                    'Risk',
                                    'Submitted',
                                    'Decision',
                                ]}
                            >
                                {rows.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="align-top hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3">
                                            <p className="font-medium">
                                                {row.applicantName}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {row.applicantEmail}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {row.city} · {row.mobileNumber}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="text-sm">
                                                {row.documentType}
                                            </p>
                                            <p className="font-mono text-xs text-muted-foreground">
                                                {row.nationalIdMasked}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                DOB {row.dateOfBirth || '—'}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <KycBadge status={row.status} />
                                            {row.rejectionReason && (
                                                <p className="mt-1 max-w-[200px] text-xs text-destructive">
                                                    {row.rejectionReason}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {row.riskScore ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                            {row.submittedAt}
                                            {row.reviewer && (
                                                <p className="text-xs">
                                                    by {row.reviewer}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <DecisionControls
                                                submission={row}
                                            />
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

/**
 * The approve / reject / begin-review controls for one application.
 */
function DecisionControls({ submission }: { submission: SubmissionRow }) {
    const approveForm = useForm({ risk_score: submission.riskScore ?? '' });
    const rejectForm = useForm({
        rejection_reason: '',
        risk_score: submission.riskScore ?? '',
    });

    const canDecide =
        submission.status === 'PENDING' || submission.status === 'IN_REVIEW';

    if (!canDecide) {
        return (
            <span className="text-xs text-muted-foreground">
                {submission.status === 'APPROVED' ? 'Decided' : 'No action'}
            </span>
        );
    }

    return (
        <div className="flex flex-col gap-2">
            {submission.status === 'PENDING' && (
                <Button
                    size="sm"
                    variant="outline"
                    disabled={approveForm.processing}
                    onClick={() =>
                        approveForm.post(`/admin/kyc/${submission.id}/review`, {
                            preserveScroll: true,
                        })
                    }
                >
                    <Search className="size-3.5" />
                    Begin review
                </Button>
            )}

            <div className="flex items-center gap-1.5">
                <Input
                    type="number"
                    min={0}
                    max={100}
                    placeholder="Risk 0–100"
                    className="h-8 w-24 text-xs"
                    aria-label={`Risk score for ${submission.applicantName}`}
                    value={approveForm.data.risk_score}
                    onChange={(event) =>
                        approveForm.setData('risk_score', event.target.value)
                    }
                />
                <Button
                    size="sm"
                    disabled={approveForm.processing}
                    onClick={() =>
                        approveForm.post(
                            `/admin/kyc/${submission.id}/approve`,
                            {
                                preserveScroll: true,
                            },
                        )
                    }
                >
                    <CheckCircle2 className="size-3.5" />
                    Approve
                </Button>
            </div>

            {approveForm.errors.risk_score && (
                <p className="text-xs text-destructive">
                    {approveForm.errors.risk_score}
                </p>
            )}

            <div className="flex items-center gap-1.5">
                <Input
                    placeholder="Rejection reason"
                    className="h-8 w-40 text-xs"
                    aria-label={`Rejection reason for ${submission.applicantName}`}
                    value={rejectForm.data.rejection_reason}
                    onChange={(event) =>
                        rejectForm.setData(
                            'rejection_reason',
                            event.target.value,
                        )
                    }
                />
                <Button
                    size="sm"
                    variant="destructive"
                    disabled={rejectForm.processing}
                    onClick={() =>
                        rejectForm.post(`/admin/kyc/${submission.id}/reject`, {
                            preserveScroll: true,
                        })
                    }
                >
                    <XCircle className="size-3.5" />
                    Reject
                </Button>
            </div>

            {rejectForm.errors.rejection_reason && (
                <p className="text-xs text-destructive">
                    {rejectForm.errors.rejection_reason}
                </p>
            )}
        </div>
    );
}
