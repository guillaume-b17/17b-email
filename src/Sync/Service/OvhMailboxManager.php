<?php

declare(strict_types=1);

namespace App\Sync\Service;

final class OvhMailboxManager
{
    public function __construct(
        private readonly OvhApiClient $ovhApiClient,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $domain, string $localPart): ?array
    {
        return $this->ovhApiClient->fetchEmailAccount($domain, $localPart);
    }

    public function exists(string $domain, string $localPart): bool
    {
        return null !== $this->find($domain, $localPart);
    }

    /**
     * @param array<string, mixed> $sourceAccount
     */
    public function create(
        string $domain,
        string $localPart,
        string $password,
        ?string $description,
        array $sourceAccount = [],
    ): void {
        $this->ovhApiClient->post(
            sprintf('/email/domain/%s/account', rawurlencode($domain)),
            [
                'accountName' => $localPart,
                'password' => $password,
                'description' => $this->resolveDescription($description, $localPart, $sourceAccount),
                'size' => $this->resolveSizeBytes($sourceAccount),
            ]
        );
    }

    public function changePassword(string $domain, string $localPart, string $password): void
    {
        $this->ovhApiClient->post(
            sprintf(
                '/email/domain/%s/account/%s/changePassword',
                rawurlencode($domain),
                rawurlencode($localPart)
            ),
            [
                'password' => $password,
            ]
        );
    }

    /**
     * @return list<string>
     */
    public function listLocalParts(string $domain): array
    {
        return $this->ovhApiClient->fetchDomainAccounts($domain);
    }

    /**
     * @param array<string, mixed> $sourceAccount
     */
    private function resolveDescription(?string $description, string $localPart, array $sourceAccount): string
    {
        if (null !== $description && '' !== trim($description)) {
            return trim($description);
        }

        foreach (['description', 'displayName'] as $key) {
            if (!isset($sourceAccount[$key])) {
                continue;
            }

            $value = trim((string) $sourceAccount[$key]);
            if ('' !== $value) {
                return mb_substr($value, 0, 180);
            }
        }

        return $localPart;
    }

    /**
     * @param array<string, mixed> $sourceAccount
     */
    private function resolveSizeBytes(array $sourceAccount): int
    {
        $defaultBytes = 5 * 1024 * 1024 * 1024;
        $raw = $sourceAccount['size'] ?? $sourceAccount['quota'] ?? null;
        if (!is_numeric($raw)) {
            return $defaultBytes;
        }

        $value = (float) $raw;
        if ($value <= 0) {
            return $defaultBytes;
        }

        if ($value <= 1024 * 1024) {
            return (int) round($value * 1024 * 1024);
        }

        return (int) round($value);
    }
}
