<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('commerce:dispatch', function () {
    // Releasing expired holds never reacquires inventory. Conditional writes
    // serialize with finalization's order lock without provider network calls.
    \App\Models\Order::where('status', 'pending_payment')->where('expires_at', '<=', now())->update(['status' => 'expired']);
    \Illuminate\Support\Facades\DB::table('payment_reconciliations')->whereNull('finished_at')->where('next_attempt_at', '<=', now())
        ->orderBy('id')->limit(200)->pluck('payment_id')->each(fn ($id) => \App\Jobs\ReconcilePayment::dispatch((int) $id));
    \Illuminate\Support\Facades\DB::table('commerce_deliveries')->whereNull('sent_at')->where('next_attempt_at', '<=', now())
        ->orderBy('id')->limit(200)->pluck('id')->each(fn ($id) => \App\Jobs\DeliverOrder::dispatch((int) $id));
})->purpose('Dispatch durable commerce reconciliation and ticket delivery work');

\Illuminate\Support\Facades\Schedule::command('commerce:dispatch')->everyMinute()->withoutOverlapping();

Artisan::command('admission:dispatch-invitations', function () {
    \Illuminate\Support\Facades\DB::table('event_staff_invitations')->whereNull('sent_at')->whereNull('accepted_at')->whereNull('revoked_at')
        ->where('expires_at', '>', now())->where('next_delivery_at', '<=', now())->orderBy('id')->limit(100)->pluck('id')
        ->each(fn ($id) => \App\Jobs\DeliverStaffInvitation::dispatch((int) $id));
})->purpose('Dispatch durable event staff invitations');
\Illuminate\Support\Facades\Schedule::command('admission:dispatch-invitations')->everyMinute()->withoutOverlapping();

Artisan::command('messages:dispatch', function () {
    $db = \Illuminate\Support\Facades\DB::class;
    // Recover workers that died during their last allowed attempt.
    $db::table('event_message_deliveries')->where('status', 'processing')->where('attempts', '>=', 5)->where('next_attempt_at', '<=', now())->update(['status' => 'failed', 'last_error' => 'Worker lease expired', 'next_attempt_at' => null, 'updated_at' => now()]);
    $db::table('event_messages')->where('status', 'preparing')->orderBy('id')->limit(100)->pluck('id')->each(fn ($id) => \App\Jobs\BuildEventMessageAudience::dispatch((int) $id));
    $db::table('event_message_deliveries as d')->join('event_messages as m', 'm.id', '=', 'd.message_id')->where('m.status', 'sending')->whereIn('d.status', ['pending', 'processing'])->where('d.next_attempt_at', '<=', now())->orderBy('d.id')->limit(200)->pluck('d.id')->each(fn ($id) => \App\Jobs\DeliverEventMessage::dispatch((int) $id));
    $db::table('event_messages')->where('status', 'sending')->orderBy('id')->limit(100)->pluck('id')->each(fn ($id) => app(\App\Services\EventMessagingService::class)->complete((int) $id));
})->purpose('Build bounded attendee audiences and drain durable message deliveries');
\Illuminate\Support\Facades\Schedule::command('messages:dispatch')->everyMinute()->withoutOverlapping();

Artisan::command('finance:dispatch', function () {
    $db = \Illuminate\Support\Facades\DB::class;
    $db::table('payout_accounts')->where('onboarding_state', 'creating')->where('next_attempt_at', '<=', now())->update(['onboarding_state' => 'review', 'last_error' => 'Provider creation lease expired; reconcile subaccount in Paystack before retrying', 'next_attempt_at' => null]);
    $db::table('payout_accounts')->whereIn('onboarding_state', ['queued', 'created'])->where('next_attempt_at', '<=', now())->orderBy('id')->limit(100)->pluck('id')->each(fn ($id) => \App\Jobs\OnboardPayoutAccount::dispatch((int) $id));
    $db::table('commerce_refunds')->whereIn('status', ['queued', 'processing'])->where('next_attempt_at', '<=', now())->orderBy('id')->limit(100)->pluck('id')->each(fn ($id) => \App\Jobs\ProcessRefund::dispatch((int) $id));
    $db::table('financial_notifications')->whereNull('sent_at')->where('next_attempt_at', '<=', now())->orderBy('id')->limit(200)->pluck('id')->each(fn ($id) => \App\Jobs\DeliverFinancialNotification::dispatch((int) $id));
    $db::table('event_cancellation_work')->where('finished', false)->where('next_attempt_at', '<=', now())->orderBy('id')->limit(20)->pluck('id')->each(fn ($id) => \App\Jobs\ProcessEventCancellation::dispatch((int) $id));
    $db::table('settlement_feeds')->insertOrIgnore(['scope' => 'platform', 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $db::table('payout_accounts as a')->whereNotNull('a.provider_subaccount_id')->where('a.provider_subaccount_id', '!=', '')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('settlement_feeds as f')->whereColumn('f.payout_account_id', 'a.id'))->orderBy('a.id')->limit(100)->get(['a.id'])->each(fn ($a) => $db::table('settlement_feeds')->insertOrIgnore(['scope' => 'payout:'.$a->id, 'payout_account_id' => $a->id, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]));
    $db::table('settlement_feeds')->where('next_attempt_at', '<=', now())->orderBy('id')->limit(100)->pluck('id')->each(fn ($id) => \App\Jobs\ReconcileSettlementFeed::dispatch((int) $id));
    $db::table('provider_settlements')->where('status', 'success')->where('reconciliation_status', 'pending')->where('next_attempt_at', '<=', now())->orderBy('id')->limit(100)->pluck('id')->each(fn ($id) => \App\Jobs\ReconcileSettlement::dispatch((int) $id));
})->purpose('Reconcile refunds, payout onboarding, settlement evidence and event cancellations');
\Illuminate\Support\Facades\Schedule::command('finance:dispatch')->everyMinute()->withoutOverlapping();
