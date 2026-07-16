<?php

namespace App\Services;

use App\Models\Integration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SourceIntegrationProvider
{
    /**
     * Verify that the source API is reachable and authenticated.
     */
    public function testConnection(Integration $integration): void
    {
        $this->request($integration)->get('/api/v3/system/status')->throw();
    }

    /**
     * @return array<int, mixed>
     */
    public function media(Integration $integration): array
    {
        return $this->jsonArray(
            $this->request($integration)->get('/api/v3/'.$this->mediaResource($integration)),
            'catalog',
        );
    }

    public function deleteMedia(Integration $integration, string $externalId): void
    {
        $this->request($integration)
            ->withQueryParameters([
                'deleteFiles' => 'true',
                'addImportExclusion' => 'false',
            ])
            ->delete('/api/v3/'.$this->mediaResource($integration).'/'.$externalId)
            ->throw();
    }

    /**
     * @return array<int, mixed>
     */
    public function lookup(Integration $integration, string $term): array
    {
        return $this->jsonArray(
            $this->request($integration)->get('/api/v3/'.$this->mediaResource($integration).'/lookup', ['term' => $term]),
            'lookup',
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function qualityProfiles(Integration $integration): array
    {
        return $this->jsonArray(
            $this->request($integration)->get('/api/v3/qualityprofile'),
            'quality profile',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function addMedia(Integration $integration, array $payload): array
    {
        return $this->jsonArray(
            $this->request($integration)->post('/api/v3/'.$this->mediaResource($integration), $payload),
            'added title',
        );
    }

    public function searchMedia(Integration $integration, int $sourceItemId): void
    {
        $command = $integration->source === Integration::RADARR
            ? ['name' => 'MoviesSearch', 'movieIds' => [$sourceItemId]]
            : ['name' => 'SeriesSearch', 'seriesId' => $sourceItemId];

        $this->request($integration)->post('/api/v3/command', $command)->throw();
    }

    /**
     * @return array<int, mixed>
     */
    public function rootFolders(Integration $integration): array
    {
        return $this->jsonArray(
            $this->request($integration)->get('/api/v3/rootfolder'),
            'root folder',
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function diskSpaces(Integration $integration): array
    {
        return $this->jsonArray(
            $this->request($integration)->get('/api/v3/diskspace'),
            'disk space',
        );
    }

    /**
     * @return array<mixed>
     */
    public function queue(Integration $integration, int $page = 1, int $pageSize = 1000): array
    {
        return $this->jsonArray(
            $this->request($integration)->get('/api/v3/queue', [
                'page' => $page,
                'pageSize' => $pageSize,
            ]),
            'queue',
        );
    }

    private function request(Integration $integration): PendingRequest
    {
        return Http::baseUrl(rtrim($integration->base_url, '/'))
            ->withHeader('X-Api-Key', $integration->api_key)
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->retry([100, 300], throw: false);
    }

    private function mediaResource(Integration $integration): string
    {
        return $integration->source === Integration::RADARR ? 'movie' : 'series';
    }

    /**
     * @return array<mixed>
     */
    private function jsonArray(Response $response, string $resource): array
    {
        $payload = $response->throw()->json();

        if (! is_array($payload)) {
            throw new \UnexpectedValueException("The integration returned an invalid {$resource} response.");
        }

        return $payload;
    }
}
