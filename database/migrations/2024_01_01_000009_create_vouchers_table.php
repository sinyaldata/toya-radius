<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64)->unique(); // sama dgn radcheck.username
            $table->string('password', 64);
            $table->string('batch_code', 30)->nullable()->index(); // grup hasil generate sekali proses
            $table->foreignId('voucher_profile_id')->constrained('voucher_profiles')->restrictOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete(); // null = stok pusat
            $table->string('status', 20)->default('belum_terjual'); // enum VoucherStatus
            $table->decimal('cost_price', 12, 2);
            $table->decimal('sale_price', 12, 2);
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('activated_at')->nullable(); // saat login pertama kali
            $table->timestamp('expired_at')->nullable(); // dihitung dari activated_at + validity_days
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
        });

        // Sekarang tambahkan FK voucher_id yang tertunda dari migration radius auth tables
        Schema::table('radcheck', function (Blueprint $table) {
            $table->foreign('voucher_id')->references('id')->on('vouchers')->cascadeOnDelete();
        });
        Schema::table('radreply', function (Blueprint $table) {
            $table->foreign('voucher_id')->references('id')->on('vouchers')->cascadeOnDelete();
        });
        Schema::table('radusergroup', function (Blueprint $table) {
            $table->foreign('voucher_id')->references('id')->on('vouchers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('radusergroup', fn (Blueprint $table) => $table->dropForeign(['voucher_id']));
        Schema::table('radreply', fn (Blueprint $table) => $table->dropForeign(['voucher_id']));
        Schema::table('radcheck', fn (Blueprint $table) => $table->dropForeign(['voucher_id']));
        Schema::dropIfExists('vouchers');
    }
};
