<?php

namespace App\Jobs;

use App\Services\EventMessagingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class DeliverEventMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $tries = 1; // Durable outbox controls retry count/backoff, including crashed worker leases.

    public int $timeout = 30;

    public function __construct(public int $deliveryId) {}

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(EventMessagingService $service): void
    {
        $claimed = DB::table('event_message_deliveries')->where('id', $this->deliveryId)->whereIn('status', ['pending', 'processing'])
            ->where('attempts', '<', 5)->where('next_attempt_at', '<=', now())->update(['status' => 'processing', 'attempts' => DB::raw('attempts + 1'), 'next_attempt_at' => now()->addMinutes(5), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $d = DB::table('event_message_deliveries')->find($this->deliveryId);
        $m = DB::table('event_messages')->find($d->message_id);
        try {
            if (! $m || $m->status !== 'sending' || ! $service->permitted($m, $d->email)) {
                DB::table('event_message_deliveries')->where('id', $d->id)->update(['status' => 'suppressed', 'next_attempt_at' => null, 'updated_at' => now()]);
            } else {
                $link = URL::signedRoute('event-message-unsubscribe', ['delivery' => $d->id]);
                Mail::raw($m->body."\n\nStop organiser messages for this event: ".$link, function ($mail) use ($m, $d) {
                    $mail->to($d->email)->subject($m->subject);
                    $mail->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', 'event-message-'.$d->id.'@'.parse_url(config('app.url'), PHP_URL_HOST));
                });
                DB::table('event_message_deliveries')->where('id', $d->id)->update(['status' => 'sent', 'sent_at' => now(), 'last_error' => null, 'next_attempt_at' => null, 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
            DB::table('event_message_deliveries')->where('id', $d->id)->update(['status' => $d->attempts >= 5 ? 'failed' : 'pending', 'last_error' => 'Delivery failed: '.class_basename($e), 'next_attempt_at' => $d->attempts >= 5 ? null : now()->addMinutes(2 ** $d->attempts), 'updated_at' => now()]);
        }
        $service->complete($d->message_id);
    }
}
