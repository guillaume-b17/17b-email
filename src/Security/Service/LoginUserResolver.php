<?php

declare(strict_types=1);

namespace App\Security\Service;

use App\Entity\EmailAccount;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class LoginUserResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminRoleResolver $adminRoleResolver,
        private readonly string $migrationSourceDomain,
        private readonly string $migrationTargetDomain,
    ) {
    }

    public function resolveOrCreate(string $email): User
    {
        $email = mb_strtolower(trim($email));

        $linked = $this->findLinkedUser($email);
        if ($linked instanceof User) {
            $this->refreshRoles($linked);

            return $linked;
        }

        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($user instanceof User) {
            $this->refreshRoles($user);

            return $user;
        }

        $user = new User($email);
        $this->entityManager->persist($user);
        $this->refreshRoles($user);

        return $user;
    }

    private function findLinkedUser(string $email): ?User
    {
        if (!str_contains($email, '@')) {
            return null;
        }

        [$localPart, $domain] = explode('@', $email, 2);
        $targetDomain = mb_strtolower(trim($this->migrationTargetDomain));
        $sourceDomain = mb_strtolower(trim($this->migrationSourceDomain));

        if ($domain === $targetDomain && '' !== $sourceDomain) {
            $sourceEmail = sprintf('%s@%s', $localPart, $sourceDomain);

            /** @var User|null $sourceUser */
            $sourceUser = $this->entityManager->getRepository(User::class)->findOneBy([
                'email' => $sourceEmail,
            ]);
            if ($sourceUser instanceof User) {
                return $sourceUser;
            }

            /** @var EmailAccount|null $sourceAccount */
            $sourceAccount = $this->entityManager->getRepository(EmailAccount::class)->findOneBy([
                'email' => $sourceEmail,
            ]);
            if ($sourceAccount instanceof EmailAccount) {
                return $sourceAccount->getOwner();
            }
        }

        /** @var EmailAccount|null $emailAccount */
        $emailAccount = $this->entityManager->getRepository(EmailAccount::class)->findOneBy([
            'email' => $email,
        ]);
        if ($emailAccount instanceof EmailAccount) {
            return $emailAccount->getOwner();
        }

        return null;
    }

    private function refreshRoles(User $user): void
    {
        $user->setRoles($this->adminRoleResolver->resolveRoles($user->getEmail()));
    }
}
