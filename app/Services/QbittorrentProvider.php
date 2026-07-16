<?php

namespace App\Services;

use App\Models\DownloadClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class QbittorrentProvider
{
    /**
     * Return the Web API version reported by qBittorrent.
     */
    public function webApiVersion(DownloadClient $client): string
    {
        return trim($this->request($client)
            ->get('/api/v2/app/webapiVersion')
            ->throw()
            ->body());
    }

    /**
     * @return array<int, mixed>
     */
    public function torrents(DownloadClient $client): array
    {
        $torrents = $this->request($client)
            ->get('/api/v2/torrents/info')
            ->throw()
            ->json();

        if (! is_array($torrents)) {
            throw new \UnexpectedValueException('qBittorrent returned an invalid torrent response.');
        }

        return $torrents;
    }

    /**
     * Create an authenticated qBittorrent request, preferring an API key.
     */
    private function request(DownloadClient $client): PendingRequest
    {
        if (filled($client->api_key)) {
            return $this->baseRequest($client)->withToken($client->api_key);
        }

        $response = $this->baseRequest($client)
            ->asForm()
            ->post('/api/v2/auth/login', [
                'username' => $client->username,
                'password' => $client->password,
            ])
            ->throw();

        if (trim($response->body()) !== 'Ok.') {
            throw new \RuntimeException('qBittorrent authentication failed: '.$response->body());
        }

        return $this->baseRequest($client)->withHeader('Cookie', 'SID='.$this->sessionId($response));
    }

    private function sessionId(Response $response): string
    {
        $cookieHeader = $response->header('Set-Cookie');

        if (! preg_match('/(?:^|;\s*)SID=([^;]+)/', $cookieHeader, $matches)) {
            throw new \RuntimeException('qBittorrent authenticated without returning a SID cookie.');
        }

        return $matches[1];
    }

    private function baseRequest(DownloadClient $client): PendingRequest
    {
        $baseUrl = rtrim($client->base_url, '/');

        return Http::baseUrl($baseUrl)
            ->withHeader('Referer', $baseUrl.'/')
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->retry([100, 300], throw: false);
    }
}
