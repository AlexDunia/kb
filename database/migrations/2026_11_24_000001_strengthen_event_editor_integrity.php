<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('main_image', 2000)->nullable()->change();
            $table->index(['status', 'category_id', 'date', 'id'], 'events_discovery_category_date');
            $table->index(['status', 'date', 'id'], 'events_discovery_date');
            $table->index(['status', 'created_at', 'id'], 'events_discovery_latest');
        });
        Schema::table('ticket_types', fn (Blueprint $table) => $table->timestamp('retired_at')->nullable());
        Schema::table('event_addresses', function (Blueprint $table) {
            $table->string('city_search', 255)->nullable()->index('event_addresses_city_search');
        });
        DB::table('event_addresses')->update(['city_search' => DB::raw('LOWER(city)')]);
    }

    public function down(): void
    {
        Schema::table('ticket_types', fn (Blueprint $table) => $table->dropColumn('retired_at'));
        // Do not truncate existing image URLs on rollback.
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_discovery_category_date');
            $table->dropIndex('events_discovery_date');
            $table->dropIndex('events_discovery_latest');
            $table->dropColumn('lock_version');
        });
        Schema::table('event_addresses', fn (Blueprint $table) => $table->dropColumn('city_search'));
    }
};
