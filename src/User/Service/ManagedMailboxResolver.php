<?php

declare(strict_types=1);

namespace App\User\Service;

use App\Entity\EmailAccount;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final class ManagedMailboxResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly string $targetDomain,
    ) {
    }

    public function resolve(?User $user = null, mixed $rawAccountId = null): ?EmailAccount
    {
        $user = $user instanceof User ? $user : $this->currentUser();
        if (!$user instanceof User) {
            return null;
        }

        $rawAccountId ??= $this->requestedAccountId();
        if (is_scalar($rawAccountId) && ctype_digit((string) $rawAccountId)) {
            /** @var EmailAccount|null $requested */
            $requested = $this->entityManager->getRepository(EmailAccount::class)->find((int) $rawAccountId);
            if ($requested instanceof EmailAccount && $this->canManage($user, $requested)) {
                return $requested;
            }
        }

        /** @var EmailAccount|null $loginAccount */
        $loginAccount = $this->entityManager->getRepository(EmailAccount::class)->findOneBy([
            'owner' => $user,
            'email' => $user->getEmail(),
        ]);
        if ($loginAccount instanceof EmailAccount) {
            return $loginAccount;
        }

        $owned = $this->listOwned($user);

        return $owned[0] ?? null;
    }

    /**
     * @return list<EmailAccount>
     */
    public function listOwned(User $user): array
    {
        /** @var list<EmailAccount> $accounts */
        $accounts = $this->entityManager->getRepository(EmailAccount::class)->findBy(
            ['owner' => $user],
            ['email' => 'ASC']
        );

        return $accounts;
    }

    /**
     * @return list<EmailAccount>
     */
    public function listForCurrentContext(): array
    {
        $current = $this->resolve();
        if ($current instanceof EmailAccount) {
            return $this->listOwned($current->getOwner());
        }

        $user = $this->currentUser();
        if (!$user instanceof User) {
            return [];
        }

        return $this->listOwned($user);
    }

    public function findOwnedTargetAccount(User $user, ?EmailAccount $current = null): ?EmailAccount
    {
        $targetDomain = mb_strtolower(trim($this->targetDomain));
        if ($current instanceof EmailAccount && $current->getDomain() === $targetDomain) {
            return $current;
        }

        foreach ($this->listOwned($user) as $account) {
            if ($account->getDomain() === $targetDomain) {
                return $account;
            }
        }

        return null;
    }

    public function findCurrentTargetAccount(): ?EmailAccount
    {
        $current = $this->resolve();
        $user = $current instanceof EmailAccount ? $current->getOwner() : $this->currentUser();
        if (!$user instanceof User) {
            return null;
        }

        return $this->findOwnedTargetAccount($user, $current);
    }

    public function canManage(User $user, EmailAccount $emailAccount): bool
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return $emailAccount->getOwner()->getId() === $user->getId();
    }

    /**
     * @return array<string, int>
     */
    public function routeParams(?EmailAccount $emailAccount): array
    {
        if (!$emailAccount instanceof EmailAccount || null === $emailAccount->getId()) {
            return [];
        }

        return ['accountId' => $emailAccount->getId()];
    }

    /**
     * @return array<string, int>
     */
    public function currentRouteParams(): array
    {
        return $this->routeParams($this->resolve());
    }

    public function isTargetAccount(EmailAccount $emailAccount): bool
    {
        return $emailAccount->getDomain() === mb_strtolower(trim($this->targetDomain));
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    private function requestedAccountId(): mixed
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return null;
        }

        $fromQuery = $request->query->get('accountId');
        if (null !== $fromQuery && '' !== (string) $fromQuery) {
            return $fromQuery;
        }

        return $request->request->get('accountId');
    }
}
