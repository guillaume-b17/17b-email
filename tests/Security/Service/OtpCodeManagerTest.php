<?php

declare(strict_types=1);

namespace App\Tests\Security\Service;

use App\Security\Service\OtpCodeManager;
use PHPUnit\Framework\TestCase;

final class OtpCodeManagerTest extends TestCase
{
    public function testGeneratedCodeHasSixDigits(): void
    {
        $manager = new OtpCodeManager('secret');

        $code = $manager->generateCode();

        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testHashVerificationWorks(): void
    {
        $manager = new OtpCodeManager('secret');
        $hash = $manager->hashCode('user@b17.fr', '123456');

        self::assertTrue($manager->verify('user@b17.fr', '123456', $hash));
        self::assertFalse($manager->verify('user@b17.fr', '000000', $hash));
    }
}
