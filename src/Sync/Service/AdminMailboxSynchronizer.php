<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Entity\EmailAccount;
use App\Entity\User;
use App\Security\Service\AdminRoleResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class AdminMailboxSynchronizer
{
    /**
     * @param list<string> $allowedDomains
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OvhApiClient $ovhApiClient,
        private readonly AdminRoleResolver $adminRoleResolver,
        private readonly LoggerInterface $logger,
        private readonly array $allowedDomains,
    ) {
    }

    /**
     * @return array{
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     errors: list<string>
     * }
     */
    public function synchronizeAll(): array
    {
        $result = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($this->normalizedAllowedDomains() as $domain) {
            try {
                $accounts = $this->ovhApiClient->fetchDomainAccounts($domain);
            } catch (\Throwable $exception) {
                $result['errors'][] = sprintf(
                    'Domaine %s: impossible de récupérer la liste des comptes (%s).',
                    $domain,
                    $exception->getMessage()
                );
                continue;
            }

            foreach ($accounts as $localPart) {
                $localPart = trim($localPart);
                if ('' === $localPart) {
                    ++$result['skipped'];

                    continue;
                }

                $email = mb_strtolower(sprintf('%s@%s', $localPart, $domain));

                try {
                    $remoteAccount = $this->ovhApiClient->fetchEmailAccount($domain, $localPart);
                    if (null === $remoteAccount) {
                        ++$result['skipped'];

                        continue;
                    }

                    $owner = $this->upsertOwnerUser($email);
                    $wasCreated = $this->upsertEmailAccount($owner, $email, $domain, $remoteAccount);

                    if ($wasCreated) {
                        ++$result['created'];
                    } else {
                        ++$result['updated'];
                    }
                } catch (\Throwable $exception) {
                    $result['errors'][] = sprintf('%s: %s', $email, $exception->getMessage());
                }
            }
        }

        $this->entityManager->flush();

        $this->logger->info('Synchronisation globale admin terminée', $result);

        return $result;
    }

    private function upsertOwnerUser(string $email): User
    {
        /** @var User|null $owner */
        $owner = $this->entityManager->getRepository(User::class)->findOneBy([
            'email' => $email,
        ]);

        if (!$owner instanceof User) {
            $owner = new User($email);
            $this->entityManager->persist($owner);
        }

        $owner->setRoles($this->adminRoleResolver->resolveRoles($email));

        return $owner;
    }

    /**
     * @param array<string, mixed> $remoteAccount
     */
    private function upsertEmailAccount(User $owner, string $email, string $domain, array $remoteAccount): bool
    {
        /** @var EmailAccount|null $emailAccount */
        $emailAccount = $this->entityManager->getRepository(EmailAccount::class)->findOneBy([
            'email' => $email,
        ]);

        $wasCreated = false;
        if (!$emailAccount instanceof EmailAccount) {
            $emailAccount = new EmailAccount($owner, $email, $domain);
            $this->entityManager->persist($emailAccount);
            $wasCreated = true;
        }

        $emailAccount
            ->setOwner($owner)
            ->setLabel($this->extractString($remoteAccount, ['description', 'displayName', 'accountName']))
            ->setQuotaMb($this->extractMegaBytes($remoteAccount, ['quota', 'maxSize']))
            ->setUsageMb($this->extractMegaBytes($remoteAccount, ['used', 'currentUsage']))
            ->setOvhIdentifier($this->extractString($remoteAccount, ['accountName', 'login']))
            ->setSyncedAt(new \DateTimeImmutable());

        return $wasCreated;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private function extractString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!isset($payload[$key])) {
                continue;
            }

            $value = trim((string) $payload[$key]);
            if ('' !== $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private function extractMegaBytes(array $payload, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (!isset($payload[$key]) || !is_numeric($payload[$key])) {
                continue;
            }

            $value = (float) $payload[$key];
            if ($value <= 0) {
                return null;
            }

            if ($value > 1024 * 1024) {
                return (int) round($value / 1024 / 1024);
            }

            return (int) round($value);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function normalizedAllowedDomains(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $domain): string => mb_strtolower(trim($domain)),
            $this->allowedDomains
        )));
    }
}
