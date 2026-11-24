<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });
        Schema::create('payment_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('provider_reference', 100)->nullable();
            $table->string('reason', 100);
            $table->json('evidence')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['payment_id', 'reason']);
            $table->unique(['provider_reference', 'reason'], 'payment_resolution_reference_reason');
        });
        Schema::create('order_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('access_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('exchanged_at')->nullable();
            $table->timestamps();
        });
        Schema::create('commerce_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('deduplication_key', 100)->unique();
            $table->string('kind', 20);
            $table->text('recovery_token')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->index();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });
        DB::table('payments')->whereIn('status', ['created', 'initialized', 'failed'])->orderBy('id')->chunkById(200, function ($payments) {
            foreach ($payments as $payment) {
                DB::table('payment_reconciliations')->insertOrIgnore(['payment_id' => $payment->id, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_deliveries');
        Schema::dropIfExists('order_recoveries');
        Schema::dropIfExists('payment_resolutions');
        Schema::dropIfExists('payment_reconciliations');
    }
};
