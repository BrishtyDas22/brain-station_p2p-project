<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `settlement_batches` — the grouping of entries into a network
 * settlement run.
 *
 * A batch is a REPORTING and OPERATIONS structure. It does not move money:
 * the entries it covers remain in the ledger, and the batch records which
 * network they were submitted to and whether the network accepted them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_batches', function (Blueprint $table): void {
            $table->id();

            $table->string('reference', 32)->unique();
            $table->string('network', 24);
            $table->string('status', 16)->default('PENDING');

            $table->unsignedBigInteger('entry_count')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->unsignedBigInteger('net_minor')->default(0);
            $table->char('currency', 3)->default('BDT');

            $table->timestamp('opened_at');
            $table->timestamp('settled_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('opened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_batches');
    }
};
