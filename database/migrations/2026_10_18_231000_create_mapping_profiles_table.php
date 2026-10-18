<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mapping_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guest_workspace_id')->constrained('guest_workspaces')->cascadeOnDelete();
            $table->string('report_type');
            $table->string('adapter_profile');
            $table->unsignedInteger('version');
            $table->unsignedInteger('schema_version');
            $table->string('header_fingerprint', 64);
            $table->jsonb('mapping');
            $table->jsonb('settings');
            $table->timestamps();

            $table->unique(['guest_workspace_id', 'report_type', 'version']);
            $table->index(['guest_workspace_id', 'report_type', 'header_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mapping_profiles');
    }
};
