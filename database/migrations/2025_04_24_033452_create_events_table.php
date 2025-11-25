<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100);
            $table->text('description');
            $table->foreignId('category_id')->constrained()->onDelete('cascade');
            $table->foreignId('organizer_id')->constrained()->onDelete('cascade');
            $table->foreignId('address_id')->constrained('event_addresses')->onDelete('cascade');
            $table->timestamp('date');
            $table->decimal('price', 10, 2);
            $table->integer('total_tickets');
            $table->string('duration')->nullable();
            $table->boolean('featured')->default(false);
            $table->string('main_image')->nullable();
            $table->string('banner_image')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};