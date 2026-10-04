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
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('kanban_card_id')->nullable()->after('completed_at');
            $table->string('kanban_status')->nullable()->after('kanban_card_id');
            $table->text('kanban_summary')->nullable()->after('kanban_status');
            $table->json('kanban_comments')->nullable()->after('kanban_summary');
            $table->timestamp('kanban_synced_at')->nullable()->after('kanban_comments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn([
                'kanban_card_id',
                'kanban_status',
                'kanban_summary',
                'kanban_comments',
                'kanban_synced_at',
            ]);
        });
    }
};