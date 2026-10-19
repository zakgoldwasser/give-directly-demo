<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guest_workspace_id')->constrained('guest_workspaces')->cascadeOnDelete();
            $table->string('operation_type');
            $table->string('idempotency_key', 128);
            $table->string('input_fingerprint', 64);
            $table->string('status');
            $table->uuid('trace_id');
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_location')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();

            $table->unique(['guest_workspace_id', 'operation_type', 'idempotency_key']);
            $table->index(['guest_workspace_id', 'status', 'lease_expires_at']);
            $table->unique('trace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_operations');
    }
};
