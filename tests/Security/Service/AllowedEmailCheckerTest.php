<?php

declare(strict_types=1);

namespace App\Tests\Security\Service;

use App\Security\Service\AllowedEmailChecker;
use PHPUnit\Framework\TestCase;

final class AllowedEmailCheckerTest extends TestCase
{
    public function testAcceptsConfiguredDomain(): void
    {
        $checker = new AllowedEmailChecker(['b17.fr', 'izardcom.fr', '17b.fr'], []);

        self::assertTrue($checker->isAllowed('user@b17.fr'));
        self::assertTrue($checker->isAllowed('user@17b.fr'));
    }

    public function testAcceptsAdminEmailOutsideDomains(): void
    {
        $checker = new AllowedEmailChecker(['b17.fr'], ['admin@other.fr']);

        self::assertTrue($checker->isAllowed('admin@other.fr'));
    }

    public function testRejectsUnknownDomain(): void
    {
        $checker = new AllowedEmailChecker(['b17.fr'], []);

        self::assertFalse($checker->isAllowed('user@example.com'));
    }
}
