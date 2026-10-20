<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ip_address', 45);
            $table->unsignedInteger('api_port')->default(8728);
            $table->string('username');
            $table->text('password'); // encrypted via cast
            $table->string('secret')->nullable(); // radius shared secret untuk NAS terkait
            $table->boolean('use_ssl')->default(false);
            $table->string('connection_status')->default('unknown'); // connected | disconnected | unknown
            $table->timestamp('last_checked_at')->nullable();
            $table->string('routeros_version')->nullable();
            $table->string('identity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routers');
    }
};
