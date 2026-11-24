<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_public_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained('events')->cascadeOnDelete();
            $table->text('summary')->nullable();
            $table->string('caption_title')->nullable();
            $table->text('caption_description')->nullable();
            $table->text('organiser_bio')->nullable();
            $table->string('organiser_image', 1000)->nullable();
            $table->json('guests')->nullable();
            $table->json('schedule')->nullable();
            $table->json('moments')->nullable();
            $table->json('faqs')->nullable();
            $table->json('crew')->nullable();
            $table->json('related_event_ids')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_public_profiles');
    }
};
