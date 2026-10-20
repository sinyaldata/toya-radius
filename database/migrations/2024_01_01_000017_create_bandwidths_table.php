<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bandwidths', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // mis. "10Mbps", "Paket Gold"
            $table->decimal('min_upload', 8, 2)->default(0); // Mbps, 0 = unlimited (limit-at)
            $table->decimal('max_upload', 8, 2); // Mbps, 0 = unlimited (max-limit)
            $table->decimal('min_download', 8, 2)->default(0);
            $table->decimal('max_download', 8, 2);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bandwidths');
    }
};
