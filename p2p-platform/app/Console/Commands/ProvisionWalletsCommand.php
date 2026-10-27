<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\KycStatus;
use App\Models\User;
use App\Services\WalletProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Provisions wallets for users whose KYC is already APPROVED but who have no
 * wallet yet.
 *
 * This is the backfill for users who were approved before the wallet
 * provisioning rule existed. It is safe to run repeatedly: provisioning is
 * idempotent, so a user who already has a wallet keeps it and no second wallet
 * is ever created.
 *
 * The command refuses to run against the isolated TEST schema, so it can
 * never interfere with the automated test suite's data.
 */
class ProvisionWalletsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'p2p:provision-wallets
                            {--dry-run : List what would be provisioned without writing anything}';

    /**
     * @var string
     */
    protected $description = 'Provision a zero-balance wallet for every KYC-approved user who does not have one';

    public function handle(WalletProvisioningService $wallets): int
    {
        $db = DB::connection();

        $database = (string) $db->getDatabaseName();

        if (str_contains($database, '_test')) {
            $this->components->error(
                'REFUSED — the active connection is the isolated test schema. '
                .'This command only touches a development database.'
            );

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'connection: %s on port %s',
            $database,
            (string) $db->getConfig('port'),
        ));

        // Every user whose latest KYC decision was APPROVED.
        $approved = User::query()
            ->whereHas('kycSubmission', fn ($query) => $query
                ->where('status', KycStatus::APPROVED->value))
            ->with('kycSubmission')
            ->orderBy('id')
            ->get();

        if ($approved->isEmpty()) {
            $this->components->info('no KYC-approved users found');

            return self::SUCCESS;
        }

        $provisioned = 0;
        $alreadyPresent = 0;

        foreach ($approved as $user) {
            $hadWallet = $user->ledgerAccount()->with('wallet')->first()?->wallet !== null;

            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    '  would provision: %s (user #%d)%s',
                    $user->email,
                    $user->id,
                    $hadWallet ? ' — already provisioned' : '',
                ));

                continue;
            }

            $wallet = $wallets->provisionForEligibleUser($user);

            if ($hadWallet) {
                $alreadyPresent++;

                $this->line(sprintf(
                    '  kept existing: %s -> wallet #%d',
                    $user->email,
                    $wallet->id,
                ));

                continue;
            }

            $provisioned++;

            $this->line(sprintf(
                '  provisioned:    %s -> wallet #%d (account #%d, balance 0 Poisha)',
                $user->email,
                $wallet->id,
                $wallet->account_id,
            ));
        }

        if ($this->option('dry-run')) {
            $this->components->info('dry run — nothing was written');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'done — %d wallet(s) provisioned, %d already present. No balances and no ledger entries were created.',
            $provisioned,
            $alreadyPresent,
        ));

        return self::SUCCESS;
    }
}
