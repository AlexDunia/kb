<?php

namespace App\Services\Commerce;

use Illuminate\Support\Facades\DB;

class FinancialWebhookService
{
    public function record(array $event): void
    {
        $name = $event['event'] ?? '';
        if (! is_string($name) || ! str_starts_with($name, 'refund.')) {
            return;
        }
        $data = $event['data'] ?? [];
        if (! is_array($data)) {
            return;
        }
        $reference = $data['reference'] ?? null;
        $providerId = $data['id'] ?? null;
        $query = DB::table('commerce_refunds')->whereIn('status', ['queued', 'processing']);
        $query->where(function ($q) use ($reference, $providerId, $data) {
            $q->whereRaw('1=0');
            if (is_string($reference) && strlen($reference) <= 64) {
                $q->orWhere('reference', $reference);
            }
            if ((is_int($providerId) || is_string($providerId)) && strlen((string) $providerId) <= 40) {
                $q->orWhere('provider_id', (string) $providerId);
            }
            $transaction = $data['transaction_reference'] ?? (is_array($data['transaction'] ?? null) ? ($data['transaction']['reference'] ?? null) : null);
            if (is_string($transaction) && strlen($transaction) <= 100) {
                $q->orWhereIn('payment_id', \App\Models\Payment::where('reference', $transaction)->select('id'));
            }
        });
        $query->update(['next_attempt_at' => now(), 'updated_at' => now()]);
        // Never mark a refund processed from a notification alone: durable jobs independently fetch provider evidence.
    }
}
