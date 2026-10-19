<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('confirmation_batches', function (Blueprint $table) {
            $table->foreignUuid('verification_import_batch_id')
                ->nullable()
                ->after('export_batch_id')
                ->constrained('import_batches')
                ->nullOnDelete();
            $table->foreignUuid('verification_reconciliation_run_id')
                ->nullable()
                ->after('verification_import_batch_id')
                ->constrained('reconciliation_runs')
                ->nullOnDelete();
            $table->jsonb('verification_counts')->nullable()->after('counts');
            $table->timestampTz('verification_completed_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('confirmation_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verification_reconciliation_run_id');
            $table->dropConstrainedForeignId('verification_import_batch_id');
            $table->dropColumn(['verification_counts', 'verification_completed_at']);
        });
    }
};
