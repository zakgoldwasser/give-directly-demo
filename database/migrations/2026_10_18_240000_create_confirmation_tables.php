<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('confirmation_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guest_workspace_id')->constrained('guest_workspaces')->cascadeOnDelete();
            $table->foreignUuid('export_batch_id')->constrained('export_batches')->cascadeOnDelete();
            $table->string('snapshot_filename');
            $table->string('snapshot_checksum', 64);
            $table->timestampTz('snapshot_generated_at');
            $table->string('coverage_status');
            $table->string('status');
            $table->jsonb('counts');
            $table->timestampTz('completed_at');
            $table->timestamps();

            $table->unique(['export_batch_id', 'snapshot_checksum']);
            $table->index(['guest_workspace_id', 'completed_at']);
        });

        Schema::create('confirmation_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('confirmation_batch_id')->constrained('confirmation_batches')->cascadeOnDelete();
            $table->foreignUuid('reconciliation_approval_id')->constrained('reconciliation_approvals')->cascadeOnDelete();
            $table->string('transaction_id');
            $table->string('expected_action');
            $table->string('outcome');
            $table->jsonb('matched_crm_record_ids');
            $table->jsonb('differences');
            $table->jsonb('expected_values');
            $table->jsonb('observed_values')->nullable();
            $table->timestamps();

            $table->unique(['confirmation_batch_id', 'reconciliation_approval_id']);
            $table->index(['confirmation_batch_id', 'outcome']);
        });

        Schema::table('export_batches', function (Blueprint $table) {
            $table->timestampTz('checked_at')->nullable()->after('downloaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('export_batches', function (Blueprint $table) {
            $table->dropColumn('checked_at');
        });

        Schema::dropIfExists('confirmation_results');
        Schema::dropIfExists('confirmation_batches');
    }
};
