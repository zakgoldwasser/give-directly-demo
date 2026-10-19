<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guest_workspace_id')->constrained('guest_workspaces')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('input_fingerprint', 64);
            $table->string('status');
            $table->string('rule_version');
            $table->jsonb('input_snapshot');
            $table->jsonb('decisions');
            $table->jsonb('outcome_counts');
            $table->jsonb('payout_counts');
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['guest_workspace_id', 'input_fingerprint']);
            $table->unique(['guest_workspace_id', 'sequence']);
            $table->index(['guest_workspace_id', 'created_at']);
        });

        Schema::create('payout_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reconciliation_run_id')->constrained('reconciliation_runs')->cascadeOnDelete();
            $table->string('payout_id');
            $table->string('currency', 3)->nullable();
            $table->string('payout_status')->nullable();
            $table->unsignedInteger('component_count');
            $table->unsignedInteger('expected_item_count')->nullable();
            $table->bigInteger('component_total_minor');
            $table->bigInteger('reported_net_amount_minor')->nullable();
            $table->bigInteger('difference_minor')->nullable();
            $table->string('state');
            $table->jsonb('issue_codes');
            $table->jsonb('component_record_ids');
            $table->timestamps();

            $table->unique(['reconciliation_run_id', 'payout_id']);
            $table->index(['reconciliation_run_id', 'state']);
        });

        Schema::create('gift_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reconciliation_run_id')->constrained('reconciliation_runs')->cascadeOnDelete();
            $table->foreignUuid('canonical_gift_id')->constrained('canonical_records')->cascadeOnDelete();
            $table->foreignUuid('matched_crm_record_id')->nullable()->constrained('canonical_records')->nullOnDelete();
            $table->string('transaction_id');
            $table->string('outcome');
            $table->string('crm_match_state');
            $table->string('payout_state');
            $table->jsonb('payout_ids');
            $table->jsonb('issue_codes');
            $table->jsonb('differences');
            $table->jsonb('expected_values');
            $table->jsonb('destination_values')->nullable();
            $table->jsonb('candidate_crm_record_ids');
            $table->timestamps();

            $table->unique(['reconciliation_run_id', 'canonical_gift_id']);
            $table->index(['reconciliation_run_id', 'outcome']);
            $table->index(['reconciliation_run_id', 'crm_match_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_reconciliations');
        Schema::dropIfExists('payout_reconciliations');
        Schema::dropIfExists('reconciliation_runs');
    }
};
