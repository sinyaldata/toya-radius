<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlets', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // kode outlet, auto-generate
            $table->string('name');
            $table->string('owner_name');
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0); // diskon harga jual utk outlet ini
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlets');
    }
};
