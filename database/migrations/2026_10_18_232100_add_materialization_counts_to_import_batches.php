<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->unsignedInteger('materialized_rows')->default(0)->after('rejected_rows');
            $table->unsignedInteger('duplicate_rows')->default(0)->after('materialized_rows');
            $table->unsignedInteger('stale_rows')->default(0)->after('duplicate_rows');
            $table->unsignedInteger('conflicting_rows')->default(0)->after('stale_rows');
            $table->timestamp('materialized_at')->nullable()->after('validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn([
                'materialized_rows',
                'duplicate_rows',
                'stale_rows',
                'conflicting_rows',
                'materialized_at',
            ]);
        });
    }
};
