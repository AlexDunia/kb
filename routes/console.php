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
