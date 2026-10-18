<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('import_batches')) {
            if (! Schema::hasColumn('import_batches', 'report_type')) {
                Schema::table('import_batches', function (Blueprint $table) {
                    $table->string('report_type')->nullable();
                });

                DB::table('import_batches')
                    ->whereNull('report_type')
                    ->update(['report_type' => 'donations']);

                Schema::table('import_batches', function (Blueprint $table) {
                    $table->string('report_type')->nullable(false)->change();
                });
            }

            $obsoleteBatchColumns = array_values(array_filter([
                'source_partner',
                'mapping_config',
                'processing_version',
            ], fn (string $column): bool => Schema::hasColumn('import_batches', $column)));

            if ($obsoleteBatchColumns !== []) {
                Schema::table('import_batches', function (Blueprint $table) use ($obsoleteBatchColumns) {
                    $table->dropColumn($obsoleteBatchColumns);
                });
            }

            if (Schema::hasIndex('import_batches', ['guest_workspace_id', 'sha256_checksum'])) {
                Schema::table('import_batches', function (Blueprint $table) {
                    $table->dropIndex(['guest_workspace_id', 'sha256_checksum']);
                });
            }

            if (! Schema::hasIndex('import_batches', ['guest_workspace_id', 'report_type', 'sha256_checksum'])) {
                Schema::table('import_batches', function (Blueprint $table) {
                    $table->index(['guest_workspace_id', 'report_type', 'sha256_checksum']);
                });
            }
        }

        if (Schema::hasTable('import_rows')) {
            $obsoleteRowColumns = array_values(array_filter([
                'normalized_data',
                'corrected_data',
                'row_version',
            ], fn (string $column): bool => Schema::hasColumn('import_rows', $column)));

            if ($obsoleteRowColumns !== []) {
                Schema::table('import_rows', function (Blueprint $table) use ($obsoleteRowColumns) {
                    $table->dropColumn($obsoleteRowColumns);
                });
            }
        }

        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('import_batches')) {
            if (Schema::hasIndex('import_batches', ['guest_workspace_id', 'report_type', 'sha256_checksum'])) {
                Schema::table('import_batches', function (Blueprint $table) {
                    $table->dropIndex(['guest_workspace_id', 'report_type', 'sha256_checksum']);
                });
            }

            Schema::table('import_batches', function (Blueprint $table) {
                $table->string('source_partner')->nullable();
                $table->jsonb('mapping_config')->nullable();
                $table->unsignedInteger('processing_version')->default(1);
                $table->dropColumn('report_type');
                $table->index(['guest_workspace_id', 'sha256_checksum']);
            });
        }

        if (Schema::hasTable('import_rows')) {
            Schema::table('import_rows', function (Blueprint $table) {
                $table->jsonb('normalized_data')->nullable();
                $table->jsonb('corrected_data')->nullable();
                $table->unsignedInteger('row_version')->default(1);
            });
        }

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedSmallInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->string('connection');
                $table->string('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
                $table->index(['connection', 'queue', 'failed_at']);
            });
        }
    }
};
