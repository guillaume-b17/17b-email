<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Entity\EmailAccount;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class UserMailboxSynchronizer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OvhApiClient $ovhApiClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function synchronize(User $user): ?EmailAccount
    {
        $userEmail = mb_strtolower($user->getEmail());
        if (!str_contains($userEmail, '@')) {
            return null;
        }

        [$localPart, $domain] = explode('@', $userEmail, 2);
        $remoteAccount = $this->ovhApiClient->fetchEmailAccount($domain, $localPart);

        if (null === $remoteAccount) {
            $this->logger->warning('Compte e-mail introuvable sur OVH', [
                'email' => $userEmail,
                'domain' => $domain,
            ]);

            return null;
        }

        /** @var EmailAccount|null $emailAccount */
        $emailAccount = $this->entityManager->getRepository(EmailAccount::class)->findOneBy([
            'email' => $userEmail,
        ]);

        if (!$emailAccount instanceof EmailAccount) {
            $emailAccount = new EmailAccount($user, $userEmail, $domain);
            $this->entityManager->persist($emailAccount);
        }

        $emailAccount
            ->setOwner($user)
            ->setLabel($this->extractString($remoteAccount, ['description', 'displayName', 'accountName']))
            ->setQuotaMb($this->extractMegaBytes($remoteAccount, ['quota', 'maxSize']))
            ->setUsageMb($this->extractMegaBytes($remoteAccount, ['used', 'currentUsage']))
            ->setOvhIdentifier($this->extractString($remoteAccount, ['accountName', 'login']))
            ->setSyncedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        $this->logger->info('Synchronisation utilisateur réussie', [
            'email' => $userEmail,
            'quotaMb' => $emailAccount->getQuotaMb(),
            'usageMb' => $emailAccount->getUsageMb(),
        ]);

        return $emailAccount;
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

            // L'API OVH renvoie souvent des octets, mais certains endpoints peuvent déjà être en Mo.
            if ($value > 1024 * 1024) {
                return (int) round($value / 1024 / 1024);
            }

            return (int) round($value);
        }

        return null;
    }
}
