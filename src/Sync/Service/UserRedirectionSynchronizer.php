<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Entity\EmailAccount;
use App\Entity\Redirection;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class UserRedirectionSynchronizer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OvhApiClient $ovhApiClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{created: int, updated: int, removed: int}
     */
    public function synchronize(User $user, EmailAccount $emailAccount): array
    {
        $email = mb_strtolower($emailAccount->getEmail());
        if (!str_contains($email, '@')) {
            return ['created' => 0, 'updated' => 0, 'removed' => 0];
        }

        [$localPart, $domain] = explode('@', $email, 2);
        $domain = mb_strtolower($domain);

        $remoteIds = array_values(array_unique(array_merge(
            $this->fetchRedirectionIds($domain, ['from' => $localPart]),
            $this->fetchRedirectionIds($domain, ['from' => $email]),
            $this->fetchRedirectionIds($domain, ['to' => $localPart]),
            $this->fetchRedirectionIds($domain, ['to' => $email]),
        )));

        /** @var list<Redirection> $currentRedirections */
        $currentRedirections = $this->entityManager->getRepository(Redirection::class)->findBy([
            'owner' => $user,
            'emailAccount' => $emailAccount,
        ]);

        $touchedKeys = [];
        $touchedOvhIds = [];
        $created = 0;
        $updated = 0;

        foreach ($remoteIds as $remoteId) {
            $payload = $this->fetchRedirectionDetail($domain, (string) $remoteId);
            if (null === $payload) {
                continue;
            }

            $source = $this->normalizeEmailValue($payload['from'] ?? null, $domain);
            $destination = $this->normalizeEmailValue($payload['to'] ?? null, $domain);

            if (null === $source || null === $destination) {
                continue;
            }

            if ($source !== $email && $destination !== $email) {
                continue;
            }

            $touchedKeys[] = $this->makeKey($source, $destination);
            $touchedOvhIds[] = (string) $remoteId;

            /** @var Redirection|null $redirection */
            $redirection = $this->entityManager->getRepository(Redirection::class)->findOneBy([
                'ovhId' => (string) $remoteId,
                'owner' => $user,
                'emailAccount' => $emailAccount,
            ]);

            if (!$redirection instanceof Redirection) {
                /** @var Redirection|null $redirection */
                $redirection = $this->entityManager->getRepository(Redirection::class)->findOneBy([
                    'owner' => $user,
                    'emailAccount' => $emailAccount,
                    'sourceEmail' => $source,
                    'destinationEmail' => $destination,
                ]);
            }

            if (!$redirection instanceof Redirection) {
                $redirection = new Redirection($user, $emailAccount, $source, $destination);
                $this->entityManager->persist($redirection);
                ++$created;
            } else {
                ++$updated;
            }

            $redirection
                ->setEnabled(true)
                ->setOvhId((string) $remoteId)
                ->setSourceEmail($source)
                ->setDestinationEmail($destination)
                ->setLocalCopy((bool) ($payload['localCopy'] ?? false));
        }

        $removed = 0;
        foreach ($currentRedirections as $currentRedirection) {
            $currentOvhId = $currentRedirection->getOvhId();
            if (null !== $currentOvhId && \in_array($currentOvhId, $touchedOvhIds, true)) {
                continue;
            }

            $key = $this->makeKey($currentRedirection->getSourceEmail(), $currentRedirection->getDestinationEmail());
            if (\in_array($key, $touchedKeys, true)) {
                continue;
            }

            $this->entityManager->remove($currentRedirection);
            ++$removed;
        }

        $this->entityManager->flush();

        $this->logger->info('Synchronisation redirections utilisateur', [
            'email' => $email,
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
        ]);

        return [
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
        ];
    }

    /**
     * @param array<string, string> $filters
     * @return list<string>
     */
    private function fetchRedirectionIds(string $domain, array $filters): array
    {
        try {
            /** @var list<string|int> $ids */
            $ids = $this->ovhApiClient->get(sprintf('/email/domain/%s/redirection', rawurlencode($domain)), $filters);

            return array_values(array_map(static fn (string|int $id): string => (string) $id, $ids));
        } catch (\Throwable $exception) {
            $this->logger->warning('Impossible de lister les redirections OVH', [
                'domain' => $domain,
                'filters' => $filters,
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRedirectionDetail(string $domain, string $id): ?array
    {
        try {
            return $this->ovhApiClient->get(sprintf('/email/domain/%s/redirection/%s', rawurlencode($domain), rawurlencode($id)));
        } catch (\Throwable $exception) {
            $this->logger->warning('Impossible de récupérer le détail de redirection OVH', [
                'domain' => $domain,
                'id' => $id,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function normalizeEmailValue(mixed $value, string $domain): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $raw = mb_strtolower(trim((string) $value));
        if ('' === $raw) {
            return null;
        }

        if (str_contains($raw, '@')) {
            return $raw;
        }

        return $raw.'@'.$domain;
    }

    private function makeKey(string $source, string $destination): string
    {
        return $source.'>'.$destination;
    }
}
