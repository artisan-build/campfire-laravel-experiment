<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presence is read from Reverb's presence channel, not tracked per tab in the database, so nothing
 * writes or reads these two columns any more. See App\Support\Presence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn(['connections', 'connected_at']);
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->unsignedInteger('connections')->default(0);
            $table->timestamp('connected_at', 6)->nullable();
        });
    }
};
