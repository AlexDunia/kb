<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: add_status_to_events_table
 *
 * Adds status column to events table
 * Ensures only 'active' events are recommended
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Add status column after 'featured' column (or adjust as needed)
            $table->enum('status', ['draft', 'active', 'cancelled', 'completed'])
                ->default('active')
                ->after('featured'); // Change 'featured' to another column if needed

            // Index for filtering active events in queries
            $table->index('status', 'idx_event_status');
        });

        // Set all existing events to 'active' status
        DB::table('events')->update(['status' => 'active']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('idx_event_status');
            $table->dropColumn('status');
        });
    }
};
