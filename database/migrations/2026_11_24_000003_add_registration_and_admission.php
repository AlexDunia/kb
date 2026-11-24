<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', fn (Blueprint $t) => $t->boolean('allow_reentry')->default(false));
        Schema::table('ticket_types', fn (Blueprint $t) => $t->boolean('is_registration')->default(false)->index());
        Schema::table('order_items', fn (Blueprint $t) => $t->json('registration_schema')->nullable());
        Schema::table('tickets', function (Blueprint $t) {
            $t->unsignedInteger('unit_index')->nullable();
            $t->unique(['order_item_id', 'unit_index']);
        });
        Schema::create('attendees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $t->foreignId('ticket_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('unit_index');
            $t->unsignedInteger('seat_index');
            $t->string('name', 200)->nullable();
            $t->string('email', 255)->nullable();
            $t->json('answers');
            $t->boolean('inside')->default(false);
            $t->unsignedInteger('entries')->default(0);
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestamps();
            $t->unique(['order_item_id', 'unit_index', 'seat_index'], 'attendees_entitlement_slot');
            $t->index(['event_id', 'name']);
            $t->index(['event_id', 'email']);
            $t->index(['ticket_id', 'inside', 'entries']);
        });
        Schema::create('event_entrances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->string('name', 100);
            $t->boolean('active')->default(true);
            $t->dateTime('opens_at')->nullable();
            $t->dateTime('closes_at')->nullable();
            $t->timestamps();
            $t->index(['event_id', 'active']);
        });
        Schema::create('event_staff', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('entrance_id')->nullable()->constrained('event_entrances')->restrictOnDelete();
            $t->string('role', 20);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['event_id', 'user_id']);
        });
        Schema::create('event_staff_invitations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('entrance_id')->nullable()->constrained('event_entrances')->restrictOnDelete();
            $t->string('email', 255);
            $t->string('role', 20);
            $t->string('token_hash', 64)->unique();
            $t->text('encrypted_token')->nullable();
            $t->dateTime('expires_at');
            $t->dateTime('accepted_at')->nullable();
            $t->dateTime('revoked_at')->nullable();
            $t->dateTime('sent_at')->nullable();
            $t->dateTime('next_delivery_at')->useCurrent()->index();
            $t->timestamps();
        });
        Schema::create('admission_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->uuid('request_key');
            $t->string('fingerprint', 64);
            $t->json('response')->nullable();
            $t->timestamps();
            $t->unique(['event_id', 'request_key']);
        });
        Schema::create('admission_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $t->foreignId('entrance_id')->constrained('event_entrances')->restrictOnDelete();
            $t->foreignId('staff_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('admission_request_id')->unique()->constrained()->restrictOnDelete();
            $t->string('direction', 8);
            $t->unsignedInteger('count');
            $t->json('attendee_ids');
            $t->timestamp('occurred_at')->useCurrent();
            $t->index(['event_id', 'id']);
            $t->index(['ticket_id', 'id']);
        });
        // Preserve every historical QR and entitlement. Unknown attendee identities
        // remain unnamed; a buyer is never silently assigned to a seat.
        DB::table('tickets')->orderBy('id')->chunkById(200, function ($tickets) {
            foreach ($tickets as $ticket) {
                $unit = DB::table('tickets')->where('order_item_id', $ticket->order_item_id)->where('id', '<', $ticket->id)->count();
                DB::table('tickets')->where('id', $ticket->id)->update(['unit_index' => $unit]);
                for ($seat = 0; $seat < $ticket->admit_count; $seat++) {
                    DB::table('attendees')->insert(['event_id' => $ticket->event_id, 'order_item_id' => $ticket->order_item_id, 'ticket_id' => $ticket->id, 'unit_index' => $unit, 'seat_index' => $seat, 'answers' => '{}', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        foreach (['admission_movements', 'admission_requests', 'event_staff_invitations', 'event_staff', 'event_entrances', 'attendees'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('tickets', function (Blueprint $t) {
            $t->dropUnique(['order_item_id', 'unit_index']);
            $t->dropColumn('unit_index');
        });
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('registration_schema'));
        Schema::table('ticket_types', fn (Blueprint $t) => $t->dropColumn('is_registration'));
        Schema::table('events', fn (Blueprint $t) => $t->dropColumn('allow_reentry'));
    }
};
