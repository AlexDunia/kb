<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('ticket_type_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('total_price', 10, 2);
            $table->enum('status', ['pending', 'completed', 'cancelled', 'refunded'])->default('pending');
            $table->string('payment_method', 50)->nullable();
            $table->string('transaction_id', 100)->nullable()->unique();
            $table->timestamps();

            // Indexes for fast queries
            $table->index(['user_id', 'event_id'], 'idx_user_event_purchase');
            $table->index(['user_id', 'created_at'], 'idx_user_created');
            $table->index('status', 'idx_status');
            $table->index('event_id', 'idx_event_purchase');

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('ticket_type_id')->references('id')->on('ticket_types')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
