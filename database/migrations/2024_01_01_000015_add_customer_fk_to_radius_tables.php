<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radcheck', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('voucher_id')
                ->constrained('customers')->cascadeOnDelete();
        });

        Schema::table('radreply', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('voucher_id')
                ->constrained('customers')->cascadeOnDelete();
        });

        Schema::table('radusergroup', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('voucher_id')
                ->constrained('customers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('radusergroup', fn (Blueprint $table) => $table->dropConstrainedForeignId('customer_id'));
        Schema::table('radreply', fn (Blueprint $table) => $table->dropConstrainedForeignId('customer_id'));
        Schema::table('radcheck', fn (Blueprint $table) => $table->dropConstrainedForeignId('customer_id'));
    }
};
