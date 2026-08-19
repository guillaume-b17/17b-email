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
        private readonly string $migrationSourceDomain,
        private readonly string $migrationTargetDomain,
    ) {
    }

    public function synchronize(User $user): ?EmailAccount
    {
        $primary = $this->synchronizeEmail($user, $user->getEmail());

        foreach ($user->getEmailAccounts() as $emailAccount) {
            if ($emailAccount->getEmail() === mb_strtolower($user->getEmail())) {
                continue;
            }

            $this->synchronizeEmail($user, $emailAccount->getEmail());
        }

        $this->attachMatchingTargetAccount($user);

        return $primary;
    }

    private function attachMatchingTargetAccount(User $user): void
    {
        $sourceDomain = mb_strtolower(trim($this->migrationSourceDomain));
        $targetDomain = mb_strtolower(trim($this->migrationTargetDomain));
        $userEmail = mb_strtolower($user->getEmail());
        if ('' === $targetDomain || !str_ends_with($userEmail, '@'.$sourceDomain)) {
            return;
        }

        [$localPart] = explode('@', $userEmail, 2);
        $targetEmail = sprintf('%s@%s', $localPart, $targetDomain);
        $this->synchronizeEmail($user, $targetEmail);
    }

    private function synchronizeEmail(User $user, string $email): ?EmailAccount
    {
        $email = mb_strtolower(trim($email));
        if (!str_contains($email, '@')) {
            return null;
        }

        [$localPart, $domain] = explode('@', $email, 2);
        $remoteAccount = $this->ovhApiClient->fetchEmailAccount($domain, $localPart);

        if (null === $remoteAccount) {
            $this->logger->info('Compte e-mail introuvable sur OVH', [
                'email' => $email,
                'domain' => $domain,
            ]);

            return null;
        }

        /** @var EmailAccount|null $emailAccount */
        $emailAccount = $this->entityManager->getRepository(EmailAccount::class)->findOneBy([
            'email' => $email,
        ]);

        if (!$emailAccount instanceof EmailAccount) {
            $emailAccount = new EmailAccount($user, $email, $domain);
            $this->entityManager->persist($emailAccount);
        }

        $emailAccount
            ->setOwner($user)
            ->setLabel($this->extractString($remoteAccount, ['description', 'displayName', 'accountName']))
            ->setQuotaMb($this->extractMegaBytes($remoteAccount, ['quota', 'maxSize', 'size']))
            ->setUsageMb($this->extractMegaBytes($remoteAccount, ['used', 'currentUsage']))
            ->setOvhIdentifier($this->extractString($remoteAccount, ['accountName', 'login']) ?? $localPart)
            ->setSyncedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        $this->logger->info('Synchronisation utilisateur réussie', [
            'email' => $email,
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
