<?php

declare(strict_types=1);

namespace App\Sync\Service;

use Ovh\Api;

final class OvhApiClient
{
    private ?Api $api = null;

    public function __construct(
        private readonly string $appKey,
        private readonly string $appSecret,
        private readonly string $consumerKey,
        private readonly string $endpoint,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->appKey)
            && '' !== trim($this->appSecret)
            && '' !== trim($this->consumerKey)
            && '' !== trim($this->endpoint);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchEmailAccount(string $domain, string $localPart): ?array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Configuration OVH incomplète.');
        }

        try {
            /** @var array<string, mixed> $response */
            $response = $this->get(sprintf('/email/domain/%s/account/%s', rawurlencode($domain), rawurlencode($localPart)));

            return $response;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    public function fetchDomainAccounts(string $domain): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Configuration OVH incomplète.');
        }

        /** @var list<string> $response */
        $response = $this->get(sprintf('/email/domain/%s/account', rawurlencode($domain)));

        return $response;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function get(string $path, array $params = []): array
    {
        /** @var array<string, mixed> $response */
        $response = [] !== $params ? $this->api()->get($path, $params) : $this->api()->get($path);

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function post(string $path, array $payload): ?array
    {
        /** @var array<string, mixed>|null $response */
        $response = $this->api()->post($path, $payload);

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function put(string $path, array $payload): ?array
    {
        /** @var array<string, mixed>|null $response */
        $response = $this->api()->put($path, $payload);

        return $response;
    }

    public function delete(string $path): void
    {
        $this->api()->delete($path);
    }

    private function api(): Api
    {
        if ($this->api instanceof Api) {
            return $this->api;
        }

        if (!$this->isConfigured()) {
            throw new \RuntimeException('Configuration OVH incomplète.');
        }

        $this->api = new Api(
            trim($this->appKey),
            trim($this->appSecret),
            trim($this->endpoint),
            trim($this->consumerKey),
        );

        return $this->api;
    }
}
