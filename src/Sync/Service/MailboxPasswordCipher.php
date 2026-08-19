<?php

declare(strict_types=1);

namespace App\Sync\Service;

final class MailboxPasswordCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    public function __construct(
        private readonly string $appSecret,
    ) {
    }

    public function encrypt(string $plainPassword): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $encrypted = openssl_encrypt(
            $plainPassword,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (false === $encrypted || strlen($tag) !== self::TAG_LENGTH) {
            throw new \RuntimeException('Impossible de chiffrer le mot de passe.');
        }

        return base64_encode($iv.$tag.$encrypted);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if (false === $raw || strlen($raw) <= self::IV_LENGTH + self::TAG_LENGTH) {
            throw new \RuntimeException('Mot de passe chiffré invalide.');
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $encrypted = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);
        $plain = openssl_decrypt(
            $encrypted,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (false === $plain) {
            throw new \RuntimeException('Impossible de déchiffrer le mot de passe.');
        }

        return $plain;
    }

    private function key(): string
    {
        return hash('sha256', $this->appSecret, true);
    }
}
