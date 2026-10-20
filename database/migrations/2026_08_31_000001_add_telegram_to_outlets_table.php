<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->string('telegram_link_code', 10)->nullable()->unique()->after('is_active');
            $table->string('telegram_chat_id')->nullable()->after('telegram_link_code');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn(['telegram_link_code', 'telegram_chat_id']);
        });
    }
};
