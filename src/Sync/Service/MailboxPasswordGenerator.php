<?php

declare(strict_types=1);

namespace App\Sync\Service;

final class MailboxPasswordGenerator
{
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const LOWER = 'abcdefghijkmnopqrstuvwxyz';
    private const DIGITS = '23456789';
    private const SPECIAL = '!#$%&()*+,-./:;<=>?@[]^_{|}~';
    private const LENGTH = 16;

    public function generate(string $forbiddenSubstring = ''): string
    {
        $forbidden = mb_strtolower(trim($forbiddenSubstring));

        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $password = $this->buildPassword();
            if ('' === $forbidden || !str_contains(mb_strtolower($password), $forbidden)) {
                return $password;
            }
        }

        throw new \RuntimeException('Impossible de générer un mot de passe compatible OVH.');
    }

    public function assertValid(string $password): void
    {
        $length = strlen($password);
        if ($length < 9 || $length > 30) {
            throw new \InvalidArgumentException('Le mot de passe doit contenir entre 9 et 30 caractères.');
        }

        if (!preg_match('/[A-Z]/', $password)) {
            throw new \InvalidArgumentException('Le mot de passe doit contenir au moins une majuscule.');
        }

        if (!preg_match('/[a-z]/', $password)) {
            throw new \InvalidArgumentException('Le mot de passe doit contenir au moins une minuscule.');
        }

        if (!preg_match('/[0-9]/', $password)) {
            throw new \InvalidArgumentException('Le mot de passe doit contenir au moins un chiffre.');
        }

        if (!preg_match('/[!#$%&()*+,\-.\/:;<=>?@\[\]^_{|}~]/', $password)) {
            throw new \InvalidArgumentException('Le mot de passe doit contenir au moins un caractère spécial.');
        }
    }

    private function buildPassword(): string
    {
        $characters = [
            $this->randomChar(self::UPPER),
            $this->randomChar(self::LOWER),
            $this->randomChar(self::DIGITS),
            $this->randomChar(self::SPECIAL),
        ];

        $pool = self::UPPER.self::LOWER.self::DIGITS.self::SPECIAL;
        for ($i = count($characters); $i < self::LENGTH; ++$i) {
            $characters[] = $this->randomChar($pool);
        }

        for ($i = count($characters) - 1; $i > 0; --$i) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    private function randomChar(string $pool): string
    {
        return $pool[random_int(0, strlen($pool) - 1)];
    }
}
