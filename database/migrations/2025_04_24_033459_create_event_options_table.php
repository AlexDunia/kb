<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('event_options', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->boolean('is_custom')->default(false);
            $table->timestamps();
        });

        Schema::create('event_event_option', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained()->onDelete('cascade');
            $table->foreignId('event_option_id')->constrained()->onDelete('cascade');
            $table->primary(['event_id', 'event_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_event_option');
        Schema::dropIfExists('event_options');
    }
};