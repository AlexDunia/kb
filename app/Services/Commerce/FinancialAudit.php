<?php

namespace App\Services\Commerce;

use Illuminate\Support\Facades\DB;

final class FinancialAudit
{
    public static function record(string $action, array $details = [], ?int $actor = null, ?int $event = null, ?int $order = null): void
    {
        DB::table('financial_audits')->insert(['actor_id' => $actor, 'event_id' => $event, 'order_id' => $order, 'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'occurred_at' => now()]);
    }

    public static function notify(int $order, string $key, string $kind, string $subject, string $body, ?int $event = null): void
    {
        DB::table('financial_notifications')->insertOrIgnore(['order_id' => $order, 'event_id' => $event, 'deduplication_key' => $key, 'kind' => $kind, 'subject' => $subject, 'body' => $body, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
