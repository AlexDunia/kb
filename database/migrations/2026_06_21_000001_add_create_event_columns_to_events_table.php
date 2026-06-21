<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'ends_at')) {
                $table->dateTime('ends_at')->nullable()->after('date');
            }
            if (! Schema::hasColumn('events', 'event_timezone')) {
                $table->string('event_timezone', 60)->nullable()->default('Africa/Lagos')->after('ends_at');
            }
            if (! Schema::hasColumn('events', 'event_type')) {
                $table->enum('event_type', ['one_time', 'recurring'])->default('one_time')->after('event_timezone');
            }
            if (! Schema::hasColumn('events', 'event_format')) {
                $table->enum('event_format', ['in-person', 'online', 'hybrid'])->default('in-person')->after('event_type');
            }
            if (! Schema::hasColumn('events', 'meeting_link')) {
                $table->string('meeting_link', 1000)->nullable()->after('event_format');
            }
            if (! Schema::hasColumn('events', 'organiser_website')) {
                $table->string('organiser_website', 500)->nullable()->after('meeting_link');
            }
            if (! Schema::hasColumn('events', 'tags')) {
                $table->string('tags', 500)->nullable()->after('organiser_website');
            }
            if (! Schema::hasColumn('events', 'ticket_mode')) {
                $table->string('ticket_mode', 20)->default('free')->after('tags');
            }
            if (! Schema::hasColumn('events', 'attendee_fields')) {
                $table->json('attendee_fields')->nullable()->after('ticket_mode');
            }
            if (! Schema::hasColumn('events', 'extra_details')) {
                $table->json('extra_details')->nullable()->after('attendee_fields');
            }
            if (! Schema::hasColumn('events', 'recurrence_data')) {
                $table->json('recurrence_data')->nullable()->after('extra_details');
            }
        });
    }

    public function down(): void
    {
        $columns = collect([
            'ends_at', 'event_timezone', 'event_type', 'event_format', 'meeting_link',
            'organiser_website', 'tags', 'ticket_mode', 'attendee_fields',
            'extra_details', 'recurrence_data',
        ])->filter(fn (string $column) => Schema::hasColumn('events', $column))->all();

        if ($columns !== []) {
            Schema::table('events', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
