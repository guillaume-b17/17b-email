<?php

declare(strict_types=1);

namespace App\Security\Service;

final class OtpCodeManager
{
    public function __construct(
        private readonly string $appSecret,
    ) {
    }

    public function generateCode(): string
    {
        return (string) random_int(100000, 999999);
    }

    public function hashCode(string $email, string $code): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)).'|'.$code, $this->appSecret);
    }

    public function verify(string $email, string $code, string $expectedHash): bool
    {
        $computedHash = $this->hashCode($email, $code);

        return hash_equals($expectedHash, $computedHash);
    }
}
