<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Integration;
use App\Models\MessageTemplate;
use App\Services\Audit;
use App\Services\Integrations\ProviderException;
use App\Services\Integrations\ProviderHttp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class IntegrationController extends Controller
{
    public const PROVIDERS = ['openai', 'deepseek', 'claude', 'whatsapp', 'resend'];

    public function index()
    {
        Gate::authorize('viewAny', Contact::class);

        return view('modules.integrations', ['integrations' => Integration::get()->keyBy('provider'), 'providers' => self::PROVIDERS]);
    }

    public function save(Request $request, string $provider)
    {
        Gate::authorize('manage-settings');
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        $rules = ['api_key' => ['nullable', 'string', 'max:1000'], 'webhook_secret' => ['nullable', 'string', 'max:1000'], 'verify_token' => ['nullable', 'string', 'max:200']];
        $rules += match ($provider) {
            'whatsapp' => ['phone_number_id' => ['required', 'regex:/^[0-9]{5,30}$/'], 'business_account_id' => ['required', 'regex:/^[0-9]{5,30}$/']],
            'resend' => ['from_email' => ['required', 'email', 'max:255']],
            default => ['model' => ['required', 'regex:/^[a-zA-Z0-9._:-]{1,120}$/'], 'input_usd_per_million' => ['required', 'numeric', 'min:0.000001', 'max:10000'], 'output_usd_per_million' => ['required', 'numeric', 'min:0.000001', 'max:10000']],
        };
        $data = $request->validate($rules);
        $integration = Integration::firstOrNew(['provider' => $provider]);
        $secrets = $integration->credentials ?? [];
        foreach (['api_key', 'webhook_secret', 'verify_token'] as $key) {
            if (! empty($data[$key])) {
                $secrets[$key] = $data[$key];
            }
            unset($data[$key]);
        }
        if (empty($secrets['api_key'])) {
            return back()->withErrors(['api_key' => 'An API key is required for the first configuration.']);
        }
        $integration->fill(['public_id' => $integration->public_id ?? (string) Str::uuid(), 'credentials' => $secrets, 'config' => $data, 'status' => 'not_connected', 'last_error' => null])->save();
        Audit::record('integration.configured', $integration);

        return back()->with('status', 'Configuration saved encrypted. Test the connection before sending.');
    }

    public function test(Integration $integration)
    {
        Gate::authorize('manage-settings');
        try {
            $key = $integration->credentials['api_key'];
            [$url, $headers] = match ($integration->provider) {
                'openai' => ['https://api.openai.com/v1/models', ['Authorization' => 'Bearer '.$key]],
                'deepseek' => ['https://api.deepseek.com/models', ['Authorization' => 'Bearer '.$key]],
                'claude' => ['https://api.anthropic.com/v1/models', ['x-api-key' => $key, 'anthropic-version' => '2023-06-01']],
                'resend' => ['https://api.resend.com/domains', ['Authorization' => 'Bearer '.$key]],
                'whatsapp' => ['https://graph.facebook.com/'.config('growth.graph_version').'/'.$integration->config['phone_number_id'], ['Authorization' => 'Bearer '.$key]],
            };
            $result = app(ProviderHttp::class)->request('GET', $url, $headers);
            $config = $integration->config;
            if (in_array($integration->provider, ['openai', 'deepseek', 'claude'], true)) {
                $models = collect($result['data'] ?? [])->pluck('id')->all();
                if (! in_array($config['model'], $models, true)) {
                    throw new ProviderException('Configured model is not available to this API key.');
                }
                $config['available_models'] = array_slice($models, 0, 100);
            }
            if ($integration->provider === 'resend') {
                $domain = Str::after($config['from_email'], '@');
                if (! collect($result['data'] ?? [])->contains(fn ($d) => ($d['name'] ?? '') === $domain && ($d['status'] ?? '') === 'verified')) {
                    throw new ProviderException('Verify the sending domain in Resend first. The key needs domain-read permission for this test.');
                }
            }
            $integration->update(['status' => 'connected', 'last_error' => null, 'tested_at' => now(), 'config' => $config]);

            return back()->with('status', 'Provider connection verified.');
        } catch (ProviderException $e) {
            $integration->update(['status' => 'error', 'last_error' => $e->getMessage(), 'tested_at' => now()]);

            return back()->withErrors(['connection' => $e->getMessage()]);
        }
    }

    public function disconnect(Integration $integration)
    {
        Gate::authorize('manage-settings');
        $integration->update(['credentials' => [], 'status' => 'not_connected', 'last_error' => null]);

        return back()->with('status', 'Integration disconnected and credentials removed.');
    }

    public function syncTemplates()
    {
        Gate::authorize('manage-settings');
        $integration = Integration::where('provider', 'whatsapp')->where('status', 'connected')->firstOrFail();
        $data = app(ProviderHttp::class)->request('GET', 'https://graph.facebook.com/'.config('growth.graph_version').'/'.$integration->config['business_account_id'].'/message_templates', ['Authorization' => 'Bearer '.$integration->credentials['api_key']], ['limit' => 100]);
        foreach ($data['data'] ?? [] as $template) {
            $body = collect($template['components'] ?? [])->firstWhere('type', 'BODY')['text'] ?? '';
            // Parameterized templates require explicit parameter mapping and cannot be sent by this UI.
            MessageTemplate::updateOrCreate(['channel' => 'whatsapp', 'name' => $template['name']], ['language' => $template['language'], 'body' => $body, 'approval_status' => str_contains($body, '{{') ? 'PARAMETERS_REQUIRED' : $template['status']]);
        }

        return back()->with('status', 'Up to 100 Meta templates synchronized. Templates requiring parameters are blocked until mapped.');
    }
}
