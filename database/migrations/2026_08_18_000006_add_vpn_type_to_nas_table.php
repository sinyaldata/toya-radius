<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nas', function (Blueprint $table) {
            $table->string('vpn_type')->nullable()->after('connection_type');
        });
    }

    public function down(): void
    {
        Schema::table('nas', function (Blueprint $table) {
            $table->dropColumn('vpn_type');
        });
    }
};
