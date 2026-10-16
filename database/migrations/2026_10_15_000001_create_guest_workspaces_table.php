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
        Schema::create('guest_workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('session_binding_hash');
            $table->timestamp('expires_at');
            $table->timestamp('last_activity_at');
            $table->string('state');
            $table->timestamps();

            $table->index('session_binding_hash');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_workspaces');
    }
};
