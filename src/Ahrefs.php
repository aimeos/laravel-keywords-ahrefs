<?php

namespace Aimeos\KeywordsBridge\Drivers;

use Aimeos\KeywordsBridge\Contracts\Driver;
use Aimeos\KeywordsBridge\Keyword;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;


/**
 * Ahrefs Keywords Explorer driver using the API v3
 */
class Ahrefs implements Driver
{
    /** @var array<string, mixed> */
    private array $config;


    /**
     * @param array<string, mixed> $config Settings from ./config/keywords-bridge.php
     */
    public function __construct(array $config = [])
    {
        if (empty($config['key'])) {
            throw new \InvalidArgumentException('Ahrefs setting [key] is missing');
        }

        if (!preg_match('/^[a-z]{2}$/i', $config['country'] ??= 'us')) {
            throw new \InvalidArgumentException("Invalid Ahrefs country code [{$config['country']}]");
        }

        $this->config = $config;
    }


    public function volume(array $keywords, ?string $country = null): array
    {
        $keywords = array_values(array_unique(array_filter(array_map(fn($keyword) => $this->normalize($keyword), $keywords), fn($keyword) => $keyword !== '')));
        // keywords are passed as comma separated list
        $valid = array_filter($keywords, fn($keyword) => !str_contains($keyword, ','));
        $volumes = [];

        foreach (array_chunk($valid, 100) as $chunk) {
            $data = $this->request('overview', ['keywords' => implode(',', $chunk), 'limit' => count($chunk)], $country);

            foreach ($data['keywords'] ?? [] as $result) {
                $volumes[$this->normalize($result['keyword'] ?? '')] ??= $this->value($result, $country);
            }
        }

        return array_map(fn($keyword) => new Keyword($keyword, $volumes[$keyword] ?? null), $keywords);
    }


    public function related(string $keyword, ?string $country = null, int $limit = 50): array
    {
        if (($keyword = $this->normalize($keyword)) === '' || str_contains($keyword, ',')) {
            return [];
        }

        $data = $this->request('related-terms', [
            'keywords' => $keyword,
            'limit' => max(1, min($limit, 1000)),
            'order_by' => ($country === null ? 'global_volume' : 'volume') . ':desc',
        ], $country);

        return array_map(fn($result) => new Keyword($this->normalize($result['keyword'] ?? ''), $this->value($result, $country)), $data['keywords'] ?? []);
    }


    /**
     * Returns the HTTP client for Ahrefs API requests
     *
     * @return PendingRequest HTTP client
     */
    protected function client(): PendingRequest
    {
        return Http::withToken($this->config['key'])->acceptJson()->timeout($this->config['timeout'] ?? 30);
    }


    /**
     * Normalizes the keyword
     *
     * @param string $keyword Keyword
     * @return string Trimmed keyword in lower case with single spaces
     */
    protected function normalize(string $keyword): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $keyword)));
    }


    /**
     * Sends the request to the Ahrefs Keywords Explorer API
     *
     * @param string $endpoint Keywords Explorer endpoint
     * @param array<string, mixed> $params Query parameters
     * @param string|null $country Two letter ISO country code or NULL for worldwide volumes
     * @return array<string, mixed> Decoded response
     */
    protected function request(string $endpoint, array $params, ?string $country): array
    {
        if ($country !== null && !preg_match('/^[a-z]{2}$/i', $country)) {
            throw new \InvalidArgumentException("Invalid country code [$country]");
        }

        // country is required but global_volume is the same for all countries
        try {
            $response = $this->client()->get('https://api.ahrefs.com/v3/keywords-explorer/' . $endpoint, $params + [
                'select' => 'keyword,' . ($country === null ? 'global_volume' : 'volume'),
                'country' => strtolower($country ?? $this->config['country']),
                'output' => 'json',
            ]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Ahrefs API request failed: ' . $e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new \RuntimeException(sprintf('Ahrefs API request failed with HTTP status %d: %s',
                $response->status(), $response->json('error', 'Unknown error')));
        }

        return (array) $response->json();
    }


    /**
     * Returns the average monthly searches
     *
     * @param array<string, mixed> $result Keyword data
     * @param string|null $country Two letter ISO country code or NULL for worldwide volumes
     * @return int|null Average monthly searches or NULL if unknown
     */
    protected function value(array $result, ?string $country): ?int
    {
        $value = $result[$country === null ? 'global_volume' : 'volume'] ?? null;
        return $value !== null ? (int) $value : null;
    }
}
