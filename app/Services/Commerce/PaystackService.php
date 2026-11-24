<?php

namespace App\Services\Commerce;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaystackService
{
    public function __construct(private readonly PaystackSplitService $splits) {}

    private function config(): array
    {
        $s = (string) config('services.paystack.secret_key');
        if ($s === '') {
            throw new RuntimeException('Paystack secret key is not configured.');
        }

        return [$s, rtrim((string) config('services.paystack.base_url', 'https://api.paystack.co'), '/')];
    }

    public function initialize(Order $o, Payment $p): array
    {
        [$s,$u] = $this->config();
        $x = ['email' => $o->customer_email, 'amount' => (string) $o->total_minor, 'currency' => $o->currency, 'reference' => $p->reference, 'callback_url' => rtrim((string) config('app.frontend_url'), '/').'/payment-success/'.$o->public_id, 'metadata' => ['order' => $o->public_id]];
        $split = $this->splits->build($o);
        if ($split) {
            $x['split'] = $split;
        }$r = Http::withToken($s)->acceptJson()->connectTimeout(3)->timeout(10)->post("$u/transaction/initialize", $x);
        if (! $r->successful() || ! $r->json('status')) {
            throw new RuntimeException($r->json('message') ?: 'Paystack could not initialize payment.');
        }$d = $r->json('data');
        if (! is_string($d['access_code'] ?? null) || ($d['reference'] ?? null) !== $p->reference) {
            throw new RuntimeException('Invalid Paystack initialization response.');
        }

        return ['access_code' => $d['access_code'], 'authorization_url' => $d['authorization_url'] ?? null, 'split_used' => $split !== null];
    }

    public function verify(string $ref): array
    {
        [$s,$u] = $this->config();
        $r = Http::withToken($s)->acceptJson()->timeout(8)->get("$u/transaction/verify/".rawurlencode($ref));
        if (! $r->successful() || ! $r->json('status')) {
            throw new RuntimeException($r->json('message') ?: 'Payment verification failed.');
        }

        return $r->json('data');
    }

    public function request(string $method, string $path, array $data = []): array
    {
        [$secret,$url] = $this->config();
        $http = Http::withToken($secret)->acceptJson()->connectTimeout(3)->timeout(10);
        $response = $method === 'GET' ? $http->get($url.$path, $data) : $http->post($url.$path, $data);
        if (! $response->successful() || $response->json('status') !== true) {
            throw new RuntimeException('Provider request failed; reconciliation required.');
        }
        $body = $response->json();
        if (! is_array($body['data'] ?? null)) {
            throw new RuntimeException('Invalid provider response.');
        }

        return $body;
    }

    public function createRefund(array $data): array
    {
        return $this->request('POST', '/refund', $data)['data'];
    }

    public function findRefund(string $reference): ?array
    {
        $rows = $this->request('GET', '/refund', ['reference' => $reference, 'perPage' => 2, 'page' => 1])['data'];
        $matches = array_values(array_filter($rows, fn ($r) => is_array($r) && ($r['reference'] ?? null) === $reference));
        if (count($matches) > 1) {
            throw new RuntimeException('Ambiguous provider refund reference.');
        }

        return $matches[0] ?? null;
    }
}
