<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->string('login_method', 20)->nullable()->after('password'); // user_pass | user_only
            $table->string('code_combination', 20)->nullable()->after('login_method');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn(['login_method', 'code_combination']);
        });
    }
};
