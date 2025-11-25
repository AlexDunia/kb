<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('event_sub_category', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained()->onDelete('cascade');
            $table->foreignId('sub_category_id')->constrained()->onDelete('cascade');
            $table->primary(['event_id', 'sub_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_sub_category');
    }
};
