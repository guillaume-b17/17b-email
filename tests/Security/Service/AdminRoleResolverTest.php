<?php

declare(strict_types=1);

namespace App\Tests\Security\Service;

use App\Security\Service\AdminRoleResolver;
use PHPUnit\Framework\TestCase;

final class AdminRoleResolverTest extends TestCase
{
    public function testReturnsAdminRoleForConfiguredEmail(): void
    {
        $resolver = new AdminRoleResolver(['admin@b17.fr']);

        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $resolver->resolveRoles('admin@b17.fr'));
    }

    public function testReturnsUserRoleForRegularEmail(): void
    {
        $resolver = new AdminRoleResolver(['admin@b17.fr']);

        self::assertSame(['ROLE_USER'], $resolver->resolveRoles('user@b17.fr'));
    }
}
