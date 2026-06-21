<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            if (! Schema::hasColumn('ticket_types', 'sales_start_date')) {
                $table->dateTime('sales_start_date')->nullable()->after('sales_end_date');
            }
        });

        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'banner_url')) {
                $table->string('banner_url', 2000)->nullable()->after('banner_image');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('ticket_types', 'sales_start_date')) {
            Schema::table('ticket_types', fn (Blueprint $table) => $table->dropColumn('sales_start_date'));
        }
        if (Schema::hasColumn('events', 'banner_url')) {
            Schema::table('events', fn (Blueprint $table) => $table->dropColumn('banner_url'));
        }
    }
};
