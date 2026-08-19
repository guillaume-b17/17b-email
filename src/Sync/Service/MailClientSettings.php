<?php

declare(strict_types=1);

namespace App\Sync\Service;

final readonly class MailClientSettings
{
    public function __construct(
        public string $imapHost,
        public int $imapPort,
        public string $smtpHost,
        public int $smtpPort,
    ) {
    }

    /**
     * @return array{
     *     imapHost: string,
     *     imapPort: int,
     *     imapEncryption: string,
     *     smtpHost: string,
     *     smtpPort: int,
     *     smtpEncryption: string
     * }
     */
    public function toArray(): array
    {
        return [
            'imapHost' => $this->imapHost,
            'imapPort' => $this->imapPort,
            'imapEncryption' => 'SSL/TLS',
            'smtpHost' => $this->smtpHost,
            'smtpPort' => $this->smtpPort,
            'smtpEncryption' => 'SSL/TLS',
        ];
    }
}
