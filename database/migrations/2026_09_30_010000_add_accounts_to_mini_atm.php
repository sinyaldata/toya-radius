<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mini_atm_accounts')) {
            Schema::create('mini_atm_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id')->index();
                $table->string('name', 60);
                $table->string('bank_name', 60)->nullable();
                $table->string('account_number', 40)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        Schema::table('mini_atm_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('mini_atm_transactions', 'account_id')) {
                $table->unsignedBigInteger('account_id')->nullable()->after('type')->index();
            }
            if (! Schema::hasColumn('mini_atm_transactions', 'to_account_id')) {
                $table->unsignedBigInteger('to_account_id')->nullable()->after('account_id');
            }
        });

        // Transaksi lama -> masuk ke "Rekening Utama" milik tokonya.
        $storeIds = DB::table('mini_atm_transactions')->whereNull('account_id')->distinct()->pluck('store_id');
        foreach ($storeIds as $storeId) {
            $accountId = DB::table('mini_atm_accounts')->where('store_id', $storeId)->orderBy('id')->value('id')
                ?? DB::table('mini_atm_accounts')->insertGetId([
                    'store_id' => $storeId, 'name' => 'Rekening Utama', 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

            DB::table('mini_atm_transactions')->where('store_id', $storeId)->whereNull('account_id')->update(['account_id' => $accountId]);
        }
    }

    public function down(): void
    {
        Schema::table('mini_atm_transactions', function (Blueprint $table) {
            $table->dropColumn(['account_id', 'to_account_id']);
        });
        Schema::dropIfExists('mini_atm_accounts');
    }
};
