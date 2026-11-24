<?php

namespace App\Services\Commerce;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderRecoveryService
{
    public function create(Order $order): string
    {
        $token = Str::random(64);
        DB::table('order_recoveries')->insert([
            'order_id' => $order->id, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(24), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $token;
    }

    public function exchange(string $token): array
    {
        return DB::transaction(function () use ($token) {
            $recovery = DB::table('order_recoveries')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            abort_unless($recovery && ! $recovery->exchanged_at && now()->lt($recovery->expires_at), 403, 'Recovery link is invalid or expired.');
            $access = Str::random(64);
            DB::table('order_recoveries')->where('id', $recovery->id)->update([
                'exchanged_at' => now(), 'expires_at' => now()->addMinutes(30),
                'access_hash' => hash('sha256', $access), 'updated_at' => now(),
            ]);

            return ['order_public_id' => Order::findOrFail($recovery->order_id)->public_id, 'access_token' => $access, 'expires_at' => now()->addMinutes(30)->toIso8601String()];
        }, 3);
    }
}
