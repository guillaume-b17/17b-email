<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Entity\EmailAccount;
use App\Entity\Redirection;

final class OvhRedirectionManager
{
    public function __construct(
        private readonly OvhApiClient $ovhApiClient,
    ) {
    }

    public function create(EmailAccount $emailAccount, string $destinationEmail, bool $localCopy): string
    {
        [$localPart, $domain] = $this->extractLocalPartAndDomain($emailAccount);
        $sourceEmail = mb_strtolower($emailAccount->getEmail());
        $destinationEmail = mb_strtolower(trim($destinationEmail));

        $this->ovhApiClient->post(
            sprintf('/email/domain/%s/redirection', rawurlencode($domain)),
            [
                // OVH attend une adresse e-mail complète ici (pas uniquement le local-part).
                'from' => $sourceEmail,
                'to' => $destinationEmail,
                'localCopy' => $localCopy,
            ]
        );

        $id = $this->waitForRedirectionId($domain, $sourceEmail, $destinationEmail);
        if (null === $id) {
            throw new \RuntimeException("La redirection a été demandée, mais l'identifiant OVH n'a pas pu être récupéré.");
        }

        return $id;
    }

    public function update(Redirection $redirection, EmailAccount $emailAccount, string $destinationEmail): void
    {
        $ovhId = $redirection->getOvhId();
        if (null === $ovhId || '' === trim($ovhId)) {
            throw new \RuntimeException('Cette redirection ne possède pas d’identifiant OVH.');
        }

        [, $domain] = $this->extractLocalPartAndDomain($emailAccount);
        $destinationEmail = mb_strtolower(trim($destinationEmail));

        $this->ovhApiClient->post(
            sprintf('/email/domain/%s/redirection/%s/changeRedirection', rawurlencode($domain), rawurlencode($ovhId)),
            [
                'to' => $destinationEmail,
            ]
        );
    }

    public function delete(Redirection $redirection, EmailAccount $emailAccount): void
    {
        $ovhId = $redirection->getOvhId();
        if (null === $ovhId || '' === trim($ovhId)) {
            throw new \RuntimeException('Cette redirection ne possède pas d’identifiant OVH.');
        }

        [, $domain] = $this->extractLocalPartAndDomain($emailAccount);

        $this->ovhApiClient->delete(
            sprintf('/email/domain/%s/redirection/%s', rawurlencode($domain), rawurlencode($ovhId))
        );
    }

    private function waitForRedirectionId(string $domain, string $sourceEmail, string $destinationEmail): ?string
    {
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            usleep(800000);

            try {
                /** @var list<string|int> $ids */
                $ids = $this->ovhApiClient->get(
                    sprintf('/email/domain/%s/redirection', rawurlencode($domain)),
                    ['from' => $sourceEmail, 'to' => $destinationEmail]
                );
            } catch (\Throwable) {
                continue;
            }

            foreach ($ids as $id) {
                $id = (string) $id;
                try {
                    $detail = $this->ovhApiClient->get(
                        sprintf('/email/domain/%s/redirection/%s', rawurlencode($domain), rawurlencode($id))
                    );
                } catch (\Throwable) {
                    continue;
                }

                $from = $this->normalizeEmail((string) ($detail['from'] ?? ''), $domain);
                $to = $this->normalizeEmail((string) ($detail['to'] ?? ''), $domain);
                if ($from === mb_strtolower($sourceEmail) && $to === mb_strtolower($destinationEmail)) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * @return array{string, string}
     */
    private function extractLocalPartAndDomain(EmailAccount $emailAccount): array
    {
        $email = mb_strtolower($emailAccount->getEmail());
        if (!str_contains($email, '@')) {
            throw new \RuntimeException('Adresse e-mail invalide.');
        }

        [$localPart, $domain] = explode('@', $email, 2);

        return [$localPart, $domain];
    }

    private function normalizeEmail(string $rawValue, string $domain): string
    {
        $rawValue = mb_strtolower(trim($rawValue));
        if ('' === $rawValue) {
            return '';
        }

        if (str_contains($rawValue, '@')) {
            return $rawValue;
        }

        return $rawValue.'@'.mb_strtolower($domain);
    }
}
