<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhook;
use App\Models\Integration;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function verify(Request $request, string $publicId)
    {
        $integration = Integration::withoutGlobalScope('tenant')->where('public_id', $publicId)->where('provider', 'whatsapp')->firstOrFail();
        $token = $integration->credentials['verify_token'] ?? '';
        abort_unless($token !== '' && hash_equals($token, (string) $request->query('hub_verify_token')) && $request->query('hub_mode') === 'subscribe', 403);

        return response((string) $request->query('hub_challenge'))->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, string $publicId)
    {
        $integration = Integration::withoutGlobalScope('tenant')->where('public_id', $publicId)->whereIn('provider', ['whatsapp', 'resend'])->firstOrFail();
        $raw = $request->getContent();
        abort_if(strlen($raw) > 1048576, 413);
        $secret = $integration->credentials['webhook_secret'] ?? '';
        abort_if($secret === '', 403);
        if ($integration->provider === 'whatsapp') {
            abort_unless(hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), (string) $request->header('X-Hub-Signature-256')), 403);
            $id = $integration->id.':'.hash('sha256', $raw);
        } else {
            $id = $request->header('svix-id', '');
            $timestamp = $request->header('svix-timestamp', '');
            abort_unless($id && ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300, 403);
            $key = base64_decode(str_replace('whsec_', '', $secret), true);
            abort_unless($key !== false, 403);
            $signature = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$raw, $key, true));
            $valid = false;
            foreach (explode(' ', (string) $request->header('svix-signature')) as $candidate) {
                if (str_starts_with($candidate, 'v1,') && hash_equals($signature, substr($candidate, 3))) {
                    $valid = true;
                }
            }
            abort_unless($valid, 403);
            $id = $integration->id.':'.$id;
        }

        return $this->persist($integration->provider, $id, $request->json()->all(), $integration->tenant_id, $integration->id);
    }

    public function razorpay(Request $request)
    {
        $raw = $request->getContent();
        abort_if(strlen($raw) > 1048576, 413);
        $secret = config('growth.razorpay.webhook_secret');
        abort_unless($secret && hash_equals(hash_hmac('sha256', $raw, $secret), (string) $request->header('X-Razorpay-Signature')), 403);

        return $this->persist('razorpay', $request->header('X-Razorpay-Event-Id') ?: hash('sha256', $raw), $request->json()->all());
    }

    private function persist(string $provider, string $id, array $payload, ?int $tenantId = null, ?int $integrationId = null)
    {
        abort_if(strlen($id) > 160, 422);
        $event = WebhookEvent::firstOrCreate(['provider' => $provider, 'event_id' => $id], ['tenant_id' => $tenantId, 'payload' => ['integration_id' => $integrationId, 'event' => $payload]]);
        // Re-delivery also recovers persisted events if queue dispatch previously failed.
        if (! $event->processed_at) {
            ProcessWebhook::dispatch($event->id);
        }

        return response()->json(['received' => true]);
    }
}
