<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Commerce\OrderRecoveryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class DeliverOrder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $deliveryId) {}

    public function handle(OrderRecoveryService $recovery): void
    {
        $claimed = DB::table('commerce_deliveries')->where('id', $this->deliveryId)->whereNull('sent_at')
            ->where('next_attempt_at', '<=', now())->update([
                'next_attempt_at' => now()->addMinutes(5), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
            ]);
        if (! $claimed) {
            return;
        }
        $delivery = DB::table('commerce_deliveries')->find($this->deliveryId);
        $order = Order::findOrFail($delivery->order_id);
        try {
            // Persist an encrypted link so retrying email delivery never exposes a
            // raw recovery credential in database rows or queue payloads.
            if (! $delivery->recovery_token) {
                $token = $recovery->create($order);
                DB::table('commerce_deliveries')->where('id', $delivery->id)->update(['recovery_token' => Crypt::encryptString($token)]);
            } else {
                $token = Crypt::decryptString($delivery->recovery_token);
                $record = DB::table('order_recoveries')->where('token_hash', hash('sha256', $token))->first();
                if (! $record || $record->exchanged_at || now()->gte($record->expires_at)) {
                    $token = $recovery->create($order);
                    DB::table('commerce_deliveries')->where('id', $delivery->id)->update(['recovery_token' => Crypt::encryptString($token)]);
                }
            }
            $link = rtrim((string) config('app.frontend_url', 'http://localhost:5174'), '/').'/orders/recover#token='.rawurlencode($token);
            $message = "Order {$order->public_id}\nStatus: {$order->status}\n\nView your order and tickets securely (link expires in 24 hours):\n{$link}\n";
            Mail::raw($message, function ($mail) use ($order, $delivery) {
                $mail->to($order->customer_email)->subject($delivery->kind === 'purchase' ? 'Your Rush Hour purchase and tickets' : 'Recover your Rush Hour order');
            });
            DB::table('commerce_deliveries')->where('id', $delivery->id)->update(['sent_at' => now(), 'last_error' => null, 'recovery_token' => null, 'updated_at' => now()]);
        } catch (\Throwable $exception) {
            DB::table('commerce_deliveries')->where('id', $delivery->id)->update([
                'last_error' => 'Delivery failed: '.class_basename($exception), 'next_attempt_at' => now()->addMinutes(5), 'updated_at' => now(),
            ]);
            throw $exception;
        }
    }
}
