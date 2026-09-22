<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which part of the centre somebody belongs to.
 *
 * Distinct from the three groupings already on a staff record, all of which
 * were tried first and none of which is this:
 *
 *   role       — the account. Admin or teacher, and it decides which screens
 *                somebody sees. A security question, not an org-chart one.
 *   job_role   — what they do. Lead Teacher, Assistant, Cook.
 *   title      — the room they lead, chosen from the room list.
 *
 * A department is none of those: Kitchen, Front Office and Infant Programme
 * each hold several jobs, and a cook and a dishwasher are one department and
 * two job roles. Reports that wanted to total by department were grouping by
 * job role instead, which put the cook in with the caretaker.
 *
 * Nullable on users, and it stays nullable: a centre that does not run
 * departments should not be made to invent one to hire somebody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // nullOnDelete rather than cascade: closing a department is an
            // org change, not a reason to delete the people who were in it.
            $table->foreignId('department_id')
                ->nullable()
                ->after('job_role')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::dropIfExists('departments');
    }
};
