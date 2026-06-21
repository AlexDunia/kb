<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'category_id')) {
                $table->unsignedBigInteger('category_id')->nullable()->change();
            }
            if (Schema::hasColumn('events', 'date')) {
                $table->timestamp('date')->nullable()->change();
            }
            if (Schema::hasColumn('events', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        // Existing drafts may contain nulls, so restoring NOT NULL automatically is unsafe.
    }
};
