<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->foreignUuid('mapping_profile_id')->nullable()->after('context')->constrained('mapping_profiles')->nullOnDelete();
            $table->string('schema_fingerprint', 64)->nullable()->after('mapping_profile_id');
            $table->jsonb('schema_issues')->nullable()->after('schema_fingerprint');
            $table->jsonb('unmapped_headers')->nullable()->after('schema_issues');
            $table->timestamp('validated_at')->nullable()->after('completed_at');
        });

        Schema::table('import_rows', function (Blueprint $table) {
            $table->jsonb('normalized_data')->nullable()->after('raw_data');
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropColumn('normalized_data');
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mapping_profile_id');
            $table->dropColumn(['schema_fingerprint', 'schema_issues', 'unmapped_headers', 'validated_at']);
        });
    }
};
