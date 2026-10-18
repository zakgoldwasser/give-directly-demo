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
        Schema::create('import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->jsonb('raw_data');
            $table->jsonb('normalized_data')->nullable();
            $table->jsonb('corrected_data')->nullable();
            $table->string('status');
            $table->jsonb('validation_issues')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->unique(['import_batch_id', 'row_number']);
            $table->index(['import_batch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
