<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guest_workspace_id')->constrained('guest_workspaces')->cascadeOnDelete();
            $table->string('record_type');
            $table->string('logical_key_hash', 64);
            $table->jsonb('identity');
            $table->string('resolution_status');
            $table->uuid('active_version_id')->nullable();
            $table->timestamps();

            $table->unique(['guest_workspace_id', 'record_type', 'logical_key_hash']);
            $table->index(['guest_workspace_id', 'record_type', 'resolution_status']);
        });

        Schema::create('canonical_record_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('canonical_record_id')->constrained('canonical_records')->cascadeOnDelete();
            $table->foreignUuid('source_import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->foreignUuid('source_import_row_id')->constrained('import_rows')->cascadeOnDelete();
            $table->timestampTz('revision_at');
            $table->string('revision_source');
            $table->string('content_fingerprint', 64);
            $table->jsonb('normalized_data');
            $table->string('resolution_state');
            $table->timestamps();

            $table->unique(
                ['canonical_record_id', 'revision_at', 'content_fingerprint'],
                'canonical_versions_identity_unique',
            );
            $table->index(['canonical_record_id', 'revision_at']);
            $table->index(['source_import_batch_id', 'resolution_state']);
        });

        Schema::table('canonical_records', function (Blueprint $table) {
            $table->foreign('active_version_id')
                ->references('id')
                ->on('canonical_record_versions')
                ->nullOnDelete();
        });

        Schema::create('source_occurrences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guest_workspace_id')->constrained('guest_workspaces')->cascadeOnDelete();
            $table->foreignUuid('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->foreignUuid('import_row_id')->constrained('import_rows')->cascadeOnDelete();
            $table->foreignUuid('mapping_profile_id')->constrained('mapping_profiles')->cascadeOnDelete();
            $table->foreignUuid('canonical_record_id')->constrained('canonical_records')->cascadeOnDelete();
            $table->foreignUuid('canonical_record_version_id')->constrained('canonical_record_versions')->cascadeOnDelete();
            $table->string('occurrence_kind');
            $table->timestamps();

            $table->unique(['import_row_id', 'mapping_profile_id']);
            $table->index(['guest_workspace_id', 'occurrence_kind']);
            $table->index(['canonical_record_id', 'occurrence_kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_occurrences');

        Schema::table('canonical_records', function (Blueprint $table) {
            $table->dropForeign(['active_version_id']);
        });

        Schema::dropIfExists('canonical_record_versions');
        Schema::dropIfExists('canonical_records');
    }
};
