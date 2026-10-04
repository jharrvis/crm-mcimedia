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
        Schema::table('security_incidents', function (Blueprint $table) {
            $table->boolean('is_flapping')->default(false)->after('resolved_at');
            $table->unsignedInteger('flap_count')->default(0)->after('is_flapping');
            $table->boolean('is_major')->default(false)->after('flap_count');
            $table->timestamp('acknowledged_at')->nullable()->after('is_major');
            $table->json('wa_notification_meta')->nullable()->after('acknowledged_at');
            $table->index(['is_flapping', 'client_id']);
            $table->index(['is_major', 'client_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('security_incidents', function (Blueprint $table) {
            $table->dropIndex(['is_flapping', 'client_id']);
            $table->dropIndex(['is_major', 'client_id']);
            $table->dropColumn(['is_flapping', 'flap_count', 'is_major', 'acknowledged_at', 'wa_notification_meta']);
        });
    }
};