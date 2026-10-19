<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guest_workspace_id')->constrained('guest_workspaces')->cascadeOnDelete();
            $table->foreignUuid('reconciliation_run_id')->constrained('reconciliation_runs')->cascadeOnDelete();
            $table->foreignUuid('gift_reconciliation_id')->constrained('gift_reconciliations')->cascadeOnDelete();
            $table->string('transaction_id');
            $table->string('action');
            $table->unsignedInteger('revision')->default(1);
            $table->jsonb('before_values')->nullable();
            $table->jsonb('after_values');
            $table->jsonb('evidence');
            $table->string('actor_type')->default('guest_reviewer');
            $table->timestampTz('approved_at');
            $table->timestamps();

            $table->unique(['reconciliation_run_id', 'transaction_id']);
            $table->index(['guest_workspace_id', 'approved_at']);
        });

        Schema::create('export_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guest_workspace_id')->constrained('guest_workspaces')->cascadeOnDelete();
            $table->foreignUuid('reconciliation_run_id')->constrained('reconciliation_runs')->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('status');
            $table->string('profile_version');
            $table->jsonb('counts');
            $table->jsonb('file_checksums');
            $table->jsonb('manifest');
            $table->string('private_file_path');
            $table->unsignedBigInteger('byte_size');
            $table->timestampTz('generated_at');
            $table->timestampTz('downloaded_at')->nullable();
            $table->timestamps();

            $table->unique(['guest_workspace_id', 'fingerprint']);
            $table->index(['guest_workspace_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_batches');
        Schema::dropIfExists('reconciliation_approvals');
    }
};
