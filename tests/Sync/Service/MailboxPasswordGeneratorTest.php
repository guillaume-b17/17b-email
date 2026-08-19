<?php

declare(strict_types=1);

namespace App\Tests\Sync\Service;

use App\Sync\Service\MailboxPasswordCipher;
use App\Sync\Service\MailboxPasswordGenerator;
use PHPUnit\Framework\TestCase;

final class MailboxPasswordGeneratorTest extends TestCase
{
    public function testGeneratedPasswordMatchesOvhPolicy(): void
    {
        $generator = new MailboxPasswordGenerator();

        for ($i = 0; $i < 20; ++$i) {
            $password = $generator->generate('jean.dupont');
            self::assertGreaterThanOrEqual(9, strlen($password));
            self::assertLessThanOrEqual(30, strlen($password));
            $generator->assertValid($password);
            self::assertStringNotContainsStringIgnoringCase('jean.dupont', $password);
        }
    }

    public function testCipherRoundtrip(): void
    {
        $cipher = new MailboxPasswordCipher('test-secret');
        $password = 'Abcd1234!xyz';

        self::assertSame($password, $cipher->decrypt($cipher->encrypt($password)));
    }
}
