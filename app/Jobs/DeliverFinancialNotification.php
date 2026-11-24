<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class DeliverFinancialNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $notificationId) {}

    public function uniqueId(): string
    {
        return (string) $this->notificationId;
    }

    public function handle(): void
    {
        $q = DB::table('financial_notifications')->where('id', $this->notificationId)->whereNull('sent_at');
        if (! (clone $q)->where('next_attempt_at', '<=', now())->update(['next_attempt_at' => now()->addMinutes(5), 'attempts' => DB::raw('attempts + 1')])) {
            return;
        }
        $n = $q->first();
        $o = \App\Models\Order::findOrFail($n->order_id);
        try {
            Mail::raw($n->body, fn ($m) => $m->to($o->customer_email)->subject($n->subject));
            $q->update(['sent_at' => now(), 'last_error' => null, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            $q->update(['last_error' => 'Delivery failed: '.class_basename($e), 'next_attempt_at' => now()->addHour(), 'updated_at' => now()]);
        }
    }
}
