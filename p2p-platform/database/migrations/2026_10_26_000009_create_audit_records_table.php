<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `audit_records` — the append-only record of who did what.
 *
 * This is the *audit* trail, which is distinct from the *ledger*. The ledger
 * records money; the audit trail records administrative and identity actions
 * (KYC decisions, reversals, role changes, logins).
 *
 * The table is append-only by construction: it carries no `updated_at`, and
 * the application never issues an UPDATE or DELETE against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_records', function (Blueprint $table): void {
            $table->id();

            // A monotonic position in the audit chain. Unique, so a gap or a
            // reorder is detectable.
            $table->unsignedBigInteger('sequence')->unique();

            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->foreign('actor_user_id')->references('id')->on('users');

            // The actor as a free-form string, so system actions and
            // pre-registration actions are representable too.
            $table->string('actor', 64);

            $table->string('action', 64);
            $table->string('target_type', 48)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->string('result', 10)->default('ALLOWED');
            $table->json('context')->nullable();

            $table->timestamp('occurred_at');

            $table->index('action');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_records');
    }
};
