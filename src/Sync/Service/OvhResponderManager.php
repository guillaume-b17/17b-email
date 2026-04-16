<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Entity\EmailAccount;

final class OvhResponderManager
{
    private const APP_TIMEZONE = 'Europe/Paris';
    private OvhApiClient $ovhApiClient;

    /**
     * @param array{
     *     enabled: bool,
     *     subject: string,
     *     message: string,
     *     startsAt: ?\DateTimeImmutable,
     *     endsAt: ?\DateTimeImmutable
     * } $data
     */
    public function __construct(OvhApiClient $ovhApiClient)
    {
        $this->ovhApiClient = $ovhApiClient;
    }

    /**
     * @param array{
     *     enabled: bool,
     *     subject: string,
     *     message: string,
     *     startsAt: ?\DateTimeImmutable,
     *     endsAt: ?\DateTimeImmutable
     * } $data
     */
    public function upsert(EmailAccount $emailAccount, array $data): void
    {
        $accountPathCandidates = $this->responderAccountPaths($emailAccount);
        $collectionPathCandidates = $this->responderCollectionPaths($emailAccount);
        $putPayload = $this->buildPutPayload($data);
        $postPayload = $this->buildPostPayload($emailAccount, $data);
        $errors = [];
        $putNotFound = false;

        foreach ($accountPathCandidates as $path) {
            try {
                $this->ovhApiClient->put($path, $putPayload);

                return;
            } catch (\Throwable $putException) {
                $errors[] = sprintf('PUT %s: %s', $path, $putException->getMessage());
                if ($this->looksLikeNotFound($putException)) {
                    $putNotFound = true;
                    continue;
                }

                if ($this->looksLikeProcessingConflict($putException)) {
                    throw new \RuntimeException(
                        "Impossible d'enregistrer le répondeur OVH : OVH est déjà en train de traiter ce répondeur. Réessayez dans quelques instants.\n".implode("\n", $errors),
                        0,
                        $putException
                    );
                }

                throw new \RuntimeException("Impossible d'enregistrer le répondeur OVH.\n".implode("\n", $errors), 0, $putException);
            }
        }

        if (!$putNotFound) {
            throw new \RuntimeException("Impossible d'enregistrer le répondeur OVH.\n".implode("\n", $errors));
        }

        foreach ($collectionPathCandidates as $path) {
            try {
                $this->ovhApiClient->post($path, $postPayload);

                return;
            } catch (\Throwable $postException) {
                $errors[] = sprintf('POST %s: %s', $path, $postException->getMessage());
            }
        }

        throw new \RuntimeException("Impossible d'enregistrer le répondeur OVH.\n".implode("\n", $errors));
    }

    public function delete(EmailAccount $emailAccount): void
    {
        $pathCandidates = $this->responderAccountPaths($emailAccount);
        $errors = [];

        foreach ($pathCandidates as $path) {
            try {
                $this->ovhApiClient->delete($path);

                return;
            } catch (\Throwable $exception) {
                $message = $exception->getMessage();
                if (str_contains(mb_strtolower($message), '404') || str_contains(mb_strtolower($message), 'not found')) {
                    return;
                }

                $errors[] = sprintf('DELETE %s: %s', $path, $message);
            }
        }

        throw new \RuntimeException("Impossible de supprimer le répondeur OVH.\n".implode("\n", $errors));
    }

    /**
     * @return array{
     *     enabled: bool,
     *     subject: ?string,
     *     message: ?string,
     *     startsAt: ?\DateTimeImmutable,
     *     endsAt: ?\DateTimeImmutable
     * }|null
     */
    public function fetch(EmailAccount $emailAccount): ?array
    {
        $report = $this->fetchWithDiagnostics($emailAccount);

        return $report['data'];
    }

    /**
     * @return array{
     *     found: bool,
     *     path: ?string,
     *     data: array{
     *         enabled: bool,
     *         subject: ?string,
     *         message: ?string,
     *         startsAt: ?\DateTimeImmutable,
     *         endsAt: ?\DateTimeImmutable
     *     }|null,
     *     raw: array<string, mixed>|null,
     *     errors: list<string>
     * }
     */
    public function fetchWithDiagnostics(EmailAccount $emailAccount): array
    {
        $pathCandidates = $this->responderAccountPaths($emailAccount);
        $errors = [];

        foreach ($pathCandidates as $path) {
            try {
                $payload = $this->ovhApiClient->get($path);

                return [
                    'found' => true,
                    'path' => $path,
                    'data' => $this->normalizePayload($payload),
                    'raw' => $payload,
                    'errors' => $errors,
                ];
            } catch (\Throwable $exception) {
                $errors[] = sprintf('GET %s: %s', $path, $exception->getMessage());
            }
        }

        return [
            'found' => false,
            'path' => null,
            'data' => null,
            'raw' => null,
            'errors' => $errors,
        ];
    }

    /**
     * @return list<string>
     */
    private function responderAccountPaths(EmailAccount $emailAccount): array
    {
        $localPart = $this->extractLocalPart($emailAccount);
        $domain = mb_strtolower($emailAccount->getDomain());
        $domain = rawurlencode($domain);
        $localPart = rawurlencode($localPart);

        return [
            // Endpoint confirmé fonctionnel du projet historique.
            sprintf('/email/domain/%s/responder/%s', $domain, $localPart),
        ];
    }

    /**
     * @return list<string>
     */
    private function responderCollectionPaths(EmailAccount $emailAccount): array
    {
        $domain = rawurlencode(mb_strtolower($emailAccount->getDomain()));

        return [
            // Endpoint confirmé fonctionnel du projet historique.
            sprintf('/email/domain/%s/responder', $domain),
        ];
    }

    /**
     * @param array{
     *     enabled: bool,
     *     subject: string,
     *     message: string,
     *     startsAt: ?\DateTimeImmutable,
     *     endsAt: ?\DateTimeImmutable
     * } $data
     * @return array<string, mixed>
     */
    private function buildPutPayload(array $data): array
    {
        $timeZone = new \DateTimeZone(self::APP_TIMEZONE);
        $payload = [
            'content' => $data['message'],
            'copy' => false,
            'copyTo' => '',
        ];

        if ($data['startsAt'] instanceof \DateTimeImmutable) {
            $payload['from'] = $data['startsAt']->setTimezone($timeZone)->format(\DateTimeInterface::ATOM);
        }

        if ($data['endsAt'] instanceof \DateTimeImmutable) {
            $payload['to'] = $data['endsAt']->setTimezone($timeZone)->format(\DateTimeInterface::ATOM);
        }

        return $payload;
    }

    /**
     * @param array{
     *     enabled: bool,
     *     subject: string,
     *     message: string,
     *     startsAt: ?\DateTimeImmutable,
     *     endsAt: ?\DateTimeImmutable
     * } $data
     * @return array<string, mixed>
     */
    private function buildPostPayload(EmailAccount $emailAccount, array $data): array
    {
        $payload = $this->buildPutPayload($data);
        $payload['account'] = $this->extractLocalPart($emailAccount);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     enabled: bool,
     *     subject: ?string,
     *     message: ?string,
     *     startsAt: ?\DateTimeImmutable,
     *     endsAt: ?\DateTimeImmutable
     * }
     */
    private function normalizePayload(array $payload): array
    {
        return [
            'enabled' => $this->extractEnabled($payload),
            'subject' => $this->extractString($payload, ['subject', 'title']),
            'message' => $this->extractString($payload, ['content', 'message', 'body', 'text']),
            'startsAt' => $this->extractDate($payload, ['startDate', 'from', 'beginDate']),
            'endsAt' => $this->extractDate($payload, ['endDate', 'to', 'finishDate']),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private function extractString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!isset($payload[$key])) {
                continue;
            }

            $value = trim((string) $payload[$key]);
            if ('' !== $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private function extractDate(array $payload, array $keys): ?\DateTimeImmutable
    {
        $timeZone = new \DateTimeZone(self::APP_TIMEZONE);

        foreach ($keys as $key) {
            if (!isset($payload[$key])) {
                continue;
            }

            $value = trim((string) $payload[$key]);
            if ('' === $value) {
                continue;
            }

            try {
                return (new \DateTimeImmutable($value))->setTimezone($timeZone);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractEnabled(array $payload): bool
    {
        if (isset($payload['enabled'])) {
            return (bool) $payload['enabled'];
        }

        if (isset($payload['isActivated'])) {
            return (bool) $payload['isActivated'];
        }

        if (isset($payload['status'])) {
            return \in_array(mb_strtolower((string) $payload['status']), ['enabled', 'active', 'on'], true);
        }

        return false;
    }

    private function extractLocalPart(EmailAccount $emailAccount): string
    {
        $email = mb_strtolower($emailAccount->getEmail());
        if (!str_contains($email, '@')) {
            throw new \RuntimeException('Adresse e-mail invalide pour le répondeur.');
        }

        [$localPart] = explode('@', $email, 2);

        return $localPart;
    }

    private function looksLikeNotFound(\Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, '404')
            || str_contains($message, 'not found')
            || str_contains($message, 'does not exist');
    }

    private function looksLikeProcessingConflict(\Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, '409')
            && (str_contains($message, 'already being processed') || str_contains($message, 'please try later'));
    }
}
