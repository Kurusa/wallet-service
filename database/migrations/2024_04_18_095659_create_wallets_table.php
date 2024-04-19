<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('currency_id');
            $table->foreign('currency_id')->references('id')->on('currencies');
            $table->bigInteger('balance')->default(0);
            $table->boolean('is_technical')->default(false);
            $table->string('wallet_type')->default('normal');
            $table->timestamps();

            $table->unique(['user_id', 'currency_id', 'wallet_type', 'is_technical']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
