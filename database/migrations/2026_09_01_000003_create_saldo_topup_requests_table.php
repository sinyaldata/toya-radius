<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saldo_topup_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->timestamp('reviewed_at')->nullable();
            $table->string('admin_chat_id')->nullable(); // chat_id admin yang dikirimi notif, buat edit pesan waktu diproses
            $table->integer('admin_message_id')->nullable(); // message_id notif admin, buat diedit jadi "sudah diproses"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saldo_topup_requests');
    }
};
