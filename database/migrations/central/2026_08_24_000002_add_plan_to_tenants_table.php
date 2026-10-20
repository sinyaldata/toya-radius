<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->string('owner_name')->nullable()->after('name');
            $table->foreignId('plan_catalog_id')->nullable()->after('database_name')
                ->constrained('plan_catalog')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_catalog_id');
            $table->dropColumn('owner_name');
        });
    }
};
