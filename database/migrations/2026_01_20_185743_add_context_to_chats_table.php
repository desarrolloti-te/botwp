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
        Schema::table('chats', function (Blueprint $table) {
            $table->json('conversation_history')->nullable()->after('context');
            $table->string('last_bot_question')->nullable()->after('conversation_history');
            $table->json('metadata')->nullable()->after('last_bot_question');
            $table->index(['status', 'context']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            //
        });
    }
};
