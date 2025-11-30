<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('event_id');
            $table->timestamp('viewed_at')->useCurrent();
            $table->string('ip_address', 45)->nullable();

            // Indexes for fast queries
            $table->index(['user_id', 'event_id', 'viewed_at'], 'idx_user_event_viewed');
            $table->index(['event_id', 'viewed_at'], 'idx_event_viewed');
            $table->index('user_id', 'idx_user');

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_views');
    }
};
