<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guest_workspace_id')->constrained('guest_workspaces')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('decision_type');
            $table->string('subject_key');
            $table->string('selected_value');
            $table->jsonb('evidence');
            $table->string('actor_type')->default('guest_reviewer');
            $table->timestamps();

            $table->unique(['guest_workspace_id', 'sequence']);
            $table->index(['guest_workspace_id', 'decision_type', 'subject_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_decisions');
    }
};
