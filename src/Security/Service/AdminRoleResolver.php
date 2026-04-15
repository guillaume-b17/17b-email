<?php

declare(strict_types=1);

namespace App\Security\Service;

final class AdminRoleResolver
{
    /**
     * @param list<string> $adminEmails
     */
    public function __construct(
        private readonly array $adminEmails,
    ) {
    }

    /**
     * @return list<string>
     */
    public function resolveRoles(string $email): array
    {
        $roles = ['ROLE_USER'];
        $normalizedEmail = mb_strtolower(trim($email));

        if (\in_array($normalizedEmail, $this->normalizedAdminEmails(), true)) {
            $roles[] = 'ROLE_ADMIN';
        }

        return $roles;
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
