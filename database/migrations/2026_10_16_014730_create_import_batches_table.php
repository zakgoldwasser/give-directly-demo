<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guest_workspace_id')->constrained('guest_workspaces')->cascadeOnDelete();
            $table->string('report_type');
            $table->string('original_filename');
            $table->string('private_file_path');
            $table->string('sha256_checksum', 64);
            $table->jsonb('parsing_config')->nullable();
            $table->jsonb('context')->nullable();
            $table->string('status');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('review_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->string('processing_error_code')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['guest_workspace_id', 'status']);
            $table->index(['guest_workspace_id', 'report_type', 'sha256_checksum']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
