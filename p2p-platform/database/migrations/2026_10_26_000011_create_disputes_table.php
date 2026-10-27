<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `disputes` — the review queue for contested transfers.
 *
 * A dispute is raised against a transaction by either party. It carries no
 * financial effect of its own; a dispute may lead to a reversal, which is a
 * separate record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('transaction_id');
            $table->foreign('transaction_id')->references('id')->on('transactions');

            $table->unsignedBigInteger('opened_by');
            $table->foreign('opened_by')->references('id')->on('users');

            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->foreign('assigned_to')->references('id')->on('users');

            $table->string('subject');
            $table->string('category', 32);
            $table->string('status', 16)->default('OPEN');
            $table->string('priority', 10)->default('MEDIUM');
            $table->text('description')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
