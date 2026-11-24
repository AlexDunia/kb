<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $t) {
            $t->string('lifecycle_state', 24)->default('open');
            $t->dateTime('booking_cutoff_at')->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->dateTime('archived_at')->nullable();
            $t->index(['lifecycle_state', 'id']);
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->unsignedInteger('refunded_quantity')->default(0);
            $t->unsignedBigInteger('refunded_minor')->default(0);
            $t->unsignedBigInteger('provider_fee_minor')->nullable();
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->unsignedBigInteger('provider_fee_minor')->nullable();
            $t->unsignedBigInteger('refunded_minor')->default(0);
        });
        Schema::table('order_allocations', function (Blueprint $t) {
            $t->unsignedBigInteger('gross_before_discount_minor')->nullable();
            $t->unsignedBigInteger('discount_minor')->default(0);
            $t->unsignedBigInteger('refunded_minor')->default(0);
            $t->unsignedBigInteger('seller_refund_liability_minor')->default(0);
            $t->unsignedBigInteger('provider_fee_minor')->nullable();
            $t->unsignedBigInteger('settled_minor')->default(0);
            $t->dateTime('settled_at')->nullable();
            $t->dateTime('platform_received_at')->nullable();
        });
        \Illuminate\Support\Facades\DB::table('order_allocations')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $r) {
                $sum = \Illuminate\Support\Facades\DB::table('order_items')->where('order_id', $r->order_id)->where('seller_user_id', $r->seller_user_id)->selectRaw('SUM(line_total_minor) AS gross, SUM(discount_minor) AS discount')->first();
                \Illuminate\Support\Facades\DB::table('order_allocations')->where('id', $r->id)->update(['gross_before_discount_minor' => (int) $sum->gross, 'discount_minor' => (int) $sum->discount]);
            }
        });
        Schema::table('payout_accounts', function (Blueprint $t) {
            $t->index(['user_id', 'status']);
            $t->dropUnique('payout_accounts_user_id_unique');
            $t->string('bank_code', 20)->nullable();
            $t->text('account_number')->nullable();
            $t->char('fingerprint', 64)->nullable();
            $t->string('provider_subaccount_id', 40)->nullable();
            $t->string('onboarding_state', 24)->default('legacy');
            $t->unsignedInteger('attempts')->default(0);
            $t->dateTime('next_attempt_at')->nullable();
            $t->dateTime('verified_at')->nullable();
            $t->string('last_error', 150)->nullable();
            $t->unique(['user_id', 'fingerprint']);
        });
        Schema::create('commerce_refunds', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('reference', 64)->unique();
            $t->foreignId('order_id')->constrained();
            $t->foreignId('payment_id')->nullable()->constrained();
            $t->foreignId('requested_by')->constrained('users');
            $t->char('request_key', 64);
            $t->char('fingerprint', 64);
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('status', 24)->default('queued');
            $t->string('provider_id', 40)->nullable()->unique();
            $t->string('reason', 300);
            $t->unsignedInteger('attempts')->default(0);
            $t->dateTime('next_attempt_at')->nullable();
            $t->dateTime('processed_at')->nullable();
            $t->string('last_error', 150)->nullable();
            $t->timestamps();
            $t->unique(['requested_by', 'request_key']);
            $t->index(['status', 'next_attempt_at']);
        });
        Schema::create('commerce_refund_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('refund_id')->constrained('commerce_refunds');
            $t->foreignId('order_item_id')->unique()->constrained();
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('amount_minor');
            $t->unsignedBigInteger('platform_fee_minor');
            $t->unsignedBigInteger('seller_net_minor');
        });
        Schema::create('financial_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->foreignId('event_id')->nullable()->constrained();
            $t->foreignId('order_id')->nullable()->constrained();
            $t->string('action', 60);
            $t->json('details');
            $t->dateTime('occurred_at');
            $t->index(['event_id', 'id']);
            $t->index(['order_id', 'id']);
        });
        Schema::create('event_cancellation_work', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->unique()->constrained();
            $t->foreignId('actor_id')->constrained('users');
            $t->unsignedBigInteger('cursor')->default(0);
            $t->boolean('finished')->default(false);
            $t->dateTime('next_attempt_at');
            $t->string('last_error', 150)->nullable();
            $t->timestamps();
        });
        Schema::create('financial_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained();
            $t->foreignId('event_id')->nullable()->constrained();
            $t->string('deduplication_key', 150)->unique();
            $t->string('kind', 30);
            $t->string('subject', 150);
            $t->text('body');
            $t->unsignedInteger('attempts')->default(0);
            $t->dateTime('sent_at')->nullable();
            $t->dateTime('next_attempt_at');
            $t->string('last_error', 150)->nullable();
            $t->timestamps();
        });
        Schema::create('settlement_feeds', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 60)->unique();
            $t->foreignId('payout_account_id')->nullable()->constrained();
            $t->unsignedInteger('page')->default(1);
            $t->dateTime('from_at')->nullable();
            $t->dateTime('scan_started_at')->nullable();
            $t->dateTime('next_attempt_at');
            $t->string('last_error', 150)->nullable();
            $t->timestamps();
        });
        Schema::create('provider_settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('feed_id')->constrained('settlement_feeds');
            $t->string('provider_id', 40);
            $t->string('status', 24);
            $t->char('currency', 3);
            $t->unsignedBigInteger('total_minor');
            $t->unsignedBigInteger('effective_minor');
            $t->unsignedBigInteger('fees_minor');
            $t->unsignedInteger('page')->default(1);
            $t->string('reconciliation_status', 24)->default('pending');
            $t->dateTime('next_attempt_at');
            $t->dateTime('settled_at')->nullable();
            $t->dateTime('provider_created_at')->nullable();
            $t->string('last_error', 150)->nullable();
            $t->timestamps();
            $t->unique(['feed_id', 'provider_id']);
        });
        Schema::create('settlement_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('settlement_id')->constrained('provider_settlements');
            $t->foreignId('payment_id')->nullable()->constrained();
            $t->foreignId('allocation_id')->nullable()->constrained('order_allocations');
            $t->string('reference', 100);
            $t->unsignedBigInteger('expected_minor')->default(0);
            $t->string('scope_payment_key', 100)->nullable()->unique();
            $t->unique('allocation_id');
            $t->string('status', 24);
            $t->unique(['settlement_id', 'reference']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Financial history migration is forward only.');
    }
};
