<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Throwable;

class ProviderHttp
{
    public function request(string $method, string $url, array $headers, array $data = [], ?array $basic = null): array
    {
        try {
            $request = Http::acceptJson()->withHeaders($headers)->connectTimeout(5)->timeout(30)->withOptions(['allow_redirects' => false]);
            if ($basic) {
                $request = $request->withBasicAuth(...$basic);
            }
            $response = $method === 'GET' ? $request->get($url, $data) : $request->send($method, $url, ['json' => $data]);
            if (! $response->successful()) {
                throw new ProviderException('Provider rejected the request (HTTP '.$response->status().'). Check configuration and limits.');
            }
            $json = $response->json();
            if (! is_array($json)) {
                throw new ProviderException('Provider returned an invalid response.');
            }

            return $json;
        } catch (ProviderException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ProviderException('Provider unavailable or timed out. Delivery may be uncertain; review before retrying.');
        }
    }
}
