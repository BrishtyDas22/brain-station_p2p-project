<?php

namespace App\Http\Middleware;

use App\Enums\KycStatus;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        // The KYC status is needed for the shell on every page, so it is read
        // once here rather than once per field.
        $submission = $user?->kycSubmission()->first();

        $kycStatus = $submission === null
            ? KycStatus::NOT_SUBMITTED
            : $submission->status;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            // The P2P shell needs a role-aware, KYC-aware profile on every page.
            'p2pUser' => $user === null ? null : [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'roleLabel' => $user->role->label(),
                'initials' => $this->initials($user->name),
                'emailVerified' => $user->email_verified_at !== null,
                'kycStatus' => $kycStatus->value,
                'kycLabel' => $kycStatus->label(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // The shell's subtitle. Pages can override it by passing their own
            // `pageDescription` prop; the client prefers that over this.
            'pageDescription' => $this->pageDescription($request),
        ];
    }

    /**
     * A short, human description of the current page.
     */
    private function pageDescription(Request $request): string
    {
        $path = '/'.ltrim($request->path(), '/');

        return match (true) {
            $path === '/dashboard' => 'Your live ledger position and recent activity.',
            $path === '/wallet' => 'Balances held against your ledger account.',
            str_starts_with($path, '/transactions') => 'Every transfer recorded against your account.',
            $path === '/kyc' => 'Submit and track your identity verification.',
            str_starts_with($path, '/wallet/withdrawals') => 'Send money out of your BDT wallet.',
            str_starts_with($path, '/wallet/fundings') => 'Top up your BDT wallet.',
            $path === '/loans' => 'Browse and fund open loan requests.',
            $path === '/loans/my' => 'Every loan you have requested.',
            $path === '/loans/investments' => 'Every loan you have funded.',
            $path === '/receive' => 'Share your receiving details.',
            $path === '/admin' => 'Operational overview across the platform.',
            str_starts_with($path, '/admin/kyc') => 'Identity applications awaiting a decision.',
            str_starts_with($path, '/admin/users') => 'Customer accounts and their roles.',
            str_starts_with($path, '/admin/monitoring') => 'Every transfer on the platform.',
            str_starts_with($path, '/admin/settlement') => 'Settlement batches and their totals.',
            str_starts_with($path, '/admin/reversals') => 'Reversal requests and open disputes.',
            str_starts_with($path, '/admin/audit') => 'The append-only audit chain.',
            str_starts_with($path, '/admin/reconciliation') => 'Invariant checks over the live ledger.',
            default => '',
        };
    }

    /**
     * Two-letter avatar initials from a display name.
     */
    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        $letters = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $letters .= mb_strtoupper(mb_substr($part, 0, 1));

            if (mb_strlen($letters) >= 2) {
                break;
            }
        }

        return $letters === '' ? 'P2' : $letters;
    }
}
