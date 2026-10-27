<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the authorisation role to `users`.
 *
 * BMAD: Epic 1 story 6 groundwork; the role drives the admin boundary in
 * Priority 3 of the delivery brief — "Do not make every newly registered user
 * an admin".
 *
 * The default is `USER`. An administrator is granted by an explicit UPDATE
 * performed by an operator, never by the registration flow. The role is
 * therefore a column with a safe default rather than a boolean that could be
 * set by a mass-assignment mistake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('USER')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
