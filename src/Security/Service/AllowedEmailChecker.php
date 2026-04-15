<?php

declare(strict_types=1);

namespace App\Security\Service;

final class AllowedEmailChecker
{
    /**
     * @param list<string> $allowedDomains
     * @param list<string> $adminEmails
     */
    public function __construct(
        private readonly array $allowedDomains,
        private readonly array $adminEmails,
    ) {
    }

    public function isAllowed(string $email): bool
    {
        $normalizedEmail = mb_strtolower(trim($email));
        if ('' === $normalizedEmail || !str_contains($normalizedEmail, '@')) {
            return false;
        }

        if (\in_array($normalizedEmail, $this->normalizedAdminEmails(), true)) {
            return true;
        }

        [, $domain] = explode('@', $normalizedEmail, 2);

        return \in_array($domain, $this->normalizedAllowedDomains(), true);
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

    /**
     * @return list<string>
     */
    private function normalizedAdminEmails(): array
    {
        $mergedAdminEmails = array_merge($this->adminEmails, AdminAccounts::EMAILS);

        return array_values(array_filter(array_map(
            static fn (string $email): string => mb_strtolower(trim($email)),
            $mergedAdminEmails
        )));
    }
}
