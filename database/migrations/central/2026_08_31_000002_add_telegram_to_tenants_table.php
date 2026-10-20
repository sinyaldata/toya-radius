<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->string('telegram_bot_token')->nullable()->after('database_name');
            $table->string('telegram_bot_username')->nullable()->after('telegram_bot_token');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->dropColumn(['telegram_bot_token', 'telegram_bot_username']);
        });
    }
};
