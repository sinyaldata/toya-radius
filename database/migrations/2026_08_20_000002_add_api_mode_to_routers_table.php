<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('api_mode')->default('legacy')->after('api_port'); // legacy | rest
            $table->integer('www_port')->nullable()->after('api_mode'); // dipakai kalau api_mode = rest (HTTPS)
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['api_mode', 'www_port']);
        });
    }
};
