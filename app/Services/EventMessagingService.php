<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class EventMessagingService
{
    public function audience(object $message): Builder
    {
        $ids = is_array($message->ticket_type_ids) ? $message->ticket_type_ids : json_decode($message->ticket_type_ids ?? 'null', true);

        return DB::table('attendees as a')->join('order_items as i', 'i.id', '=', 'a.order_item_id')
            ->join('orders as o', 'o.id', '=', 'i.order_id')->join('tickets as t', 't.id', '=', 'a.ticket_id')
            ->leftJoin('event_message_preferences as p', function ($j) use ($message) {
                $j->where('p.event_id', $message->event_id)->whereRaw("p.email = LOWER(TRIM(COALESCE(NULLIF(a.email, ''), o.customer_email)))");
            })->whereNull('p.unsubscribed_at')
            ->when($message->purpose === 'marketing', fn ($q) => $q->where('p.marketing_consent', true)->whereNotNull('p.consented_at'))
            ->where('a.event_id', $message->event_id)->whereIn('o.status', ['paid', 'registered', 'partially_refunded'])->where('t.status', 'valid')
            ->when($ids, fn ($q) => $q->whereIn('i.ticket_type_id', $ids))
            ->when($message->audience === 'checked_in', fn ($q) => $q->where('a.entries', '>', 0))
            ->selectRaw('a.id, LOWER(TRIM(COALESCE(NULLIF(a.email, \'\'), o.customer_email))) AS recipient_email');
    }

    public function permitted(object $message, string $email): bool
    {
        $p = DB::table('event_message_preferences')->where('event_id', $message->event_id)->where('email', $email)->first();
        if ($p?->unsubscribed_at) {
            return false;
        }
        if ($message->purpose === 'marketing' && (! $p?->marketing_consent || ! $p?->consented_at)) {
            return false;
        }

        return $this->audience($message)->whereRaw('LOWER(TRIM(COALESCE(NULLIF(a.email, \'\'), o.customer_email))) = ?', [$email])->exists();
    }

    public function build(int $id): void
    {
        DB::transaction(function () use ($id) {
            $m = DB::table('event_messages')->where('id', $id)->lockForUpdate()->first();
            if (! $m || $m->status !== 'preparing') {
                return;
            }
            $rows = $this->audience($m)->where('a.id', '>', $m->audience_cursor)->where('a.id', '<=', $m->audience_max)->orderBy('a.id')->limit(200)->get();
            $recipients = [];
            foreach ($rows as $r) {
                if (! filter_var($r->recipient_email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $recipients[$r->recipient_email] = ['message_id' => $id, 'email' => $r->recipient_email, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()];
            }
            if ($recipients) {
                DB::table('event_message_deliveries')->insertOrIgnore(array_values($recipients));
            }
            DB::table('event_messages')->where('id', $id)->update(['audience_cursor' => $rows->last()?->id ?? $m->audience_max, 'status' => $rows->count() < 200 ? 'sending' : 'preparing', 'updated_at' => now()]);
        }, 3);
    }

    public function complete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $m = DB::table('event_messages')->where('id', $id)->lockForUpdate()->first();
            if (! $m || $m->status !== 'sending') {
                return;
            }
            $q = DB::table('event_message_deliveries')->where('message_id', $id);
            if ((clone $q)->whereIn('status', ['pending', 'processing'])->exists()) {
                return;
            }
            DB::table('event_messages')->where('id', $id)->update(['status' => (clone $q)->where('status', 'failed')->exists() ? 'failed' : 'completed', 'updated_at' => now()]);
        }, 3);
    }
}
