<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $invalid = \Illuminate\Support\Facades\DB::table('orders as o')->leftJoin('order_items as i', 'i.order_id', '=', 'o.id')
            ->where('o.discount_minor', '>', 0)->groupBy('o.id', 'o.discount_minor')->havingRaw('o.discount_minor > COALESCE(SUM(i.line_total_minor),0)')->select('o.id')->first();
        if ($invalid) {
            throw new \RuntimeException('Historical order discount exceeds item gross; reconcile before migrating.');
        }
        Schema::create('event_discounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained();
            $t->string('code', 20);
            $t->string('name', 100);
            $t->string('partner', 100)->nullable();
            $t->string('type', 10);
            $t->unsignedBigInteger('value');
            $t->char('currency', 3);
            $t->json('ticket_type_ids')->nullable();
            $t->unsignedInteger('usage_limit');
            $t->dateTime('starts_at')->nullable();
            $t->dateTime('ends_at')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['event_id', 'code']);
        });
        Schema::create('discount_redemptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('discount_id')->constrained('event_discounts');
            $t->foreignId('order_id')->unique()->constrained();
            $t->json('snapshot');
            $t->unsignedBigInteger('amount_minor');
            $t->dateTime('held_until')->nullable();
            $t->dateTime('redeemed_at')->nullable();
            $t->timestamps();
            $t->index(['discount_id', 'redeemed_at']);
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->unsignedBigInteger('discount_minor')->default(0);
            $t->unsignedBigInteger('platform_fee_minor')->default(0);
            $t->index(['event_id', 'currency', 'order_id'], 'event_currency_orders');
        });
        // Preserve any pre-existing order-level discount by distributing its historical value once.
        \Illuminate\Support\Facades\DB::table('orders')->where('discount_minor', '>', 0)->orderBy('id')->chunkById(100, function ($orders) {
            foreach ($orders as $order) {
                $items = \Illuminate\Support\Facades\DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get(['id', 'line_total_minor']);
                $remaining = (int) $order->discount_minor;
                $gross = (int) $items->sum('line_total_minor');
                if ($remaining > $gross) {
                    throw new \RuntimeException('Historical order discount exceeds item gross; reconcile before migrating.');
                }
                foreach ($items as $item) {
                    $part = $gross > 0 ? \App\Support\Money::proportional($remaining, (int) $item->line_total_minor, $gross) : 0;
                    \Illuminate\Support\Facades\DB::table('order_items')->where('id', $item->id)->update(['discount_minor' => $part]);
                    $remaining -= $part;
                    $gross -= (int) $item->line_total_minor;
                }
            }
        });
        // Preserve historical seller fees from existing allocations, rather than today's fee setting.
        \Illuminate\Support\Facades\DB::table('order_allocations')->orderBy('id')->chunkById(100, function ($allocations) {
            foreach ($allocations as $allocation) {
                $items = \Illuminate\Support\Facades\DB::table('order_items')->where('order_id', $allocation->order_id)->where('seller_user_id', $allocation->seller_user_id)->orderBy('id')->get(['id', 'line_total_minor', 'discount_minor']);
                $remaining = (int) $allocation->platform_fee_minor;
                $gross = (int) $items->sum(fn ($i) => $i->line_total_minor - $i->discount_minor);
                foreach ($items as $item) {
                    $item->line_total_minor -= $item->discount_minor;
                    $fee = $gross > 0 ? ($gross === (int) $item->line_total_minor ? $remaining : \App\Support\Money::proportional($remaining, (int) $item->line_total_minor, $gross)) : 0;
                    \Illuminate\Support\Facades\DB::table('order_items')->where('id', $item->id)->update(['platform_fee_minor' => $fee]);
                    $remaining -= $fee;
                    $gross -= (int) $item->line_total_minor;
                }
            }
        });
        Schema::table('event_traffic_views', function (Blueprint $t) {
            $t->index(['user_id', 'viewed_at', 'event_id'], 'traffic_user_recent');
        });
        Schema::table('attendees', fn (Blueprint $t) => $t->index(['event_id', 'id'], 'attendees_event_cursor'));
        Schema::create('event_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->string('subject', 150);
            $t->text('body');
            $t->string('purpose', 20);
            $t->string('audience', 30);
            $t->json('ticket_type_ids')->nullable();
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('audience_cursor')->default(0);
            $t->unsignedBigInteger('audience_max')->default(0);
            $t->dateTime('queued_at')->nullable();
            $t->timestamps();
            $t->index(['event_id', 'id']);
            $t->index(['status', 'id']);
        });
        Schema::create('event_message_preferences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained();
            $t->string('email', 255);
            $t->boolean('marketing_consent')->default(false);
            $t->dateTime('consented_at')->nullable();
            $t->dateTime('unsubscribed_at')->nullable();
            $t->timestamps();
            $t->unique(['event_id', 'email']);
        });
        Schema::create('event_message_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->constrained('event_messages');
            $t->string('email', 255);
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->dateTime('next_attempt_at')->nullable();
            $t->dateTime('sent_at')->nullable();
            $t->string('last_error', 150)->nullable();
            $t->timestamps();
            $t->unique(['message_id', 'email']);
            $t->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_message_deliveries');
        Schema::dropIfExists('event_message_preferences');
        Schema::dropIfExists('event_messages');
        Schema::table('attendees', fn (Blueprint $t) => $t->dropIndex('attendees_event_cursor'));
        Schema::table('event_traffic_views', fn (Blueprint $t) => $t->dropIndex('traffic_user_recent'));
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropIndex('event_currency_orders');
            $t->dropColumn(['discount_minor', 'platform_fee_minor']);
        });
        Schema::dropIfExists('discount_redemptions');
        Schema::dropIfExists('event_discounts');
    }
};
