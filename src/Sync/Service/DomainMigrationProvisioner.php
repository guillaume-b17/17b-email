<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Entity\EmailAccount;
use App\Entity\MailboxMigration;
use App\Entity\User;
use App\Security\Service\AdminRoleResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class DomainMigrationProvisioner
{
    /**
     * @var array<string, User>
     */
    private array $ownersByEmail = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OvhMailboxManager $ovhMailboxManager,
        private readonly DomainMigrationMappingLoader $mappingLoader,
        private readonly MailboxPasswordGenerator $passwordGenerator,
        private readonly MailboxPasswordCipher $passwordCipher,
        private readonly AdminRoleResolver $adminRoleResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{sourceEmail: string, targetLocalPart: string, description: ?string}>
     */
    public function buildMappingRows(string $sourceDomain): array
    {
        $localParts = $this->ovhMailboxManager->listLocalParts($sourceDomain);
        $rows = [];

        foreach ($localParts as $localPart) {
            $localPart = mb_strtolower(trim($localPart));
            if ('' === $localPart) {
                continue;
            }

            $sourceEmail = sprintf('%s@%s', $localPart, mb_strtolower($sourceDomain));
            $remoteAccount = $this->ovhMailboxManager->find($sourceDomain, $localPart);
            $description = null;
            if (is_array($remoteAccount)) {
                foreach (['description', 'displayName'] as $key) {
                    if (!isset($remoteAccount[$key])) {
                        continue;
                    }

                    $value = trim((string) $remoteAccount[$key]);
                    if ('' !== $value) {
                        $description = $value;
                        break;
                    }
                }
            }

            $rows[] = [
                'sourceEmail' => $sourceEmail,
                'targetLocalPart' => $localPart,
                'description' => $description,
            ];
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => $left['sourceEmail'] <=> $right['sourceEmail']
        );

        return $rows;
    }

    public function exportMapping(string $sourceDomain, string $outputPath): int
    {
        $rows = $this->buildMappingRows($sourceDomain);
        $this->ensureDirectory($outputPath);

        $handle = fopen($outputPath, 'wb');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Impossible d’écrire le mapping: %s', $outputPath));
        }

        try {
            fwrite($handle, "# Laissez target_local_part identique, ou changez-le si le collaborateur change de nom.\n");
            fputcsv($handle, ['source_email', 'target_local_part', 'description'], ',', '"', '\\');
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['sourceEmail'],
                    $row['targetLocalPart'],
                    $row['description'] ?? '',
                ], ',', '"', '\\');
            }
        } finally {
            fclose($handle);
        }

        return count($rows);
    }

    /**
     * @return array{
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     errors: int,
     *     passwordsFile: ?string,
     *     details: list<array{sourceEmail: string, targetEmail: string, status: string, message: string}>
     * }
     */
    public function provision(
        string $mappingPath,
        string $sourceDomain,
        string $targetDomain,
        string $passwordsFile,
        bool $dryRun,
        bool $force,
        ?string $onlySourceEmail = null,
    ): array {
        $this->ownersByEmail = [];
        $sourceDomain = mb_strtolower(trim($sourceDomain));
        $targetDomain = mb_strtolower(trim($targetDomain));
        $rows = $this->mappingLoader->load($mappingPath, $sourceDomain);
        $onlySourceEmail = null !== $onlySourceEmail ? mb_strtolower(trim($onlySourceEmail)) : null;

        $result = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'passwordsFile' => $dryRun ? null : $passwordsFile,
            'details' => [],
        ];

        $passwordRows = [];

        foreach ($rows as $row) {
            if (null !== $onlySourceEmail && $row['sourceEmail'] !== $onlySourceEmail) {
                continue;
            }

            $targetEmail = sprintf('%s@%s', $row['targetLocalPart'], $targetDomain);

            try {
                $detail = $this->provisionOne(
                    $row,
                    $sourceDomain,
                    $targetDomain,
                    $targetEmail,
                    $dryRun,
                    $force
                );
                $result['details'][] = $detail;
                ++$result[$detail['counter']];

                if (isset($detail['password'])) {
                    $passwordRows[] = [
                        $row['sourceEmail'],
                        $targetEmail,
                        $detail['password'],
                        $row['description'] ?? '',
                        $detail['status'],
                    ];
                }
            } catch (\Throwable $exception) {
                ++$result['errors'];
                $result['details'][] = [
                    'sourceEmail' => $row['sourceEmail'],
                    'targetEmail' => $targetEmail,
                    'status' => MailboxMigration::STATUS_ERROR,
                    'message' => $exception->getMessage(),
                ];
                $this->persistError($row['sourceEmail'], $targetEmail, $targetDomain, $row['description'], $exception->getMessage(), $dryRun);
                $this->logger->error('Erreur création compte 17b.fr', [
                    'source' => $row['sourceEmail'],
                    'target' => $targetEmail,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
            if ([] !== $passwordRows) {
                $this->appendPasswordRows($passwordsFile, $passwordRows);
            } else {
                $result['passwordsFile'] = null;
            }
        }

        return $result;
    }

    /**
     * @return array{sourceEmail: string, targetEmail: string, status: string, message: string, counter: 'created'|'updated'|'skipped', password?: string}
     */
    public function provisionAccount(
        string $sourceEmail,
        string $targetLocalPart,
        string $sourceDomain,
        string $targetDomain,
        ?string $description,
        bool $force,
        ?string $passwordsFile = null,
    ): array {
        $this->ownersByEmail = [];
        $sourceDomain = mb_strtolower(trim($sourceDomain));
        $targetDomain = mb_strtolower(trim($targetDomain));
        $sourceEmail = mb_strtolower(trim($sourceEmail));
        $targetLocalPart = mb_strtolower(trim($targetLocalPart));
        $description = null !== $description ? trim($description) : null;
        $description = '' === $description ? null : $description;

        if (!filter_var($sourceEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Adresse source invalide.');
        }

        [, $domain] = explode('@', $sourceEmail, 2);
        if ($domain !== $sourceDomain) {
            throw new \InvalidArgumentException(sprintf('Le compte source doit être en @%s.', $sourceDomain));
        }

        [$sourceLocalPart] = explode('@', $sourceEmail, 2);
        if ('' === $targetLocalPart) {
            $targetLocalPart = $sourceLocalPart;
        }

        if (!preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/', $targetLocalPart)) {
            throw new \InvalidArgumentException('Identifiant du nouveau compte invalide.');
        }

        $targetEmail = sprintf('%s@%s', $targetLocalPart, $targetDomain);

        /** @var MailboxMigration|null $existingTarget */
        $existingTarget = $this->entityManager->getRepository(MailboxMigration::class)->findOneBy([
            'targetEmail' => $targetEmail,
        ]);
        if ($existingTarget instanceof MailboxMigration && $existingTarget->getSourceEmail() !== $sourceEmail) {
            throw new \InvalidArgumentException(sprintf(
                '%s est déjà lié au compte source %s.',
                $targetEmail,
                $existingTarget->getSourceEmail()
            ));
        }

        try {
            $detail = $this->provisionOne(
                [
                    'sourceEmail' => $sourceEmail,
                    'targetLocalPart' => $targetLocalPart,
                    'description' => $description,
                ],
                $sourceDomain,
                $targetDomain,
                $targetEmail,
                false,
                $force
            );
        } catch (\Throwable $exception) {
            $this->persistError($sourceEmail, $targetEmail, $targetDomain, $description, $exception->getMessage(), false);
            $this->entityManager->flush();
            throw $exception;
        }
        $this->entityManager->flush();

        if (null !== $passwordsFile && isset($detail['password'])) {
            $this->appendPasswordRows($passwordsFile, [[
                $sourceEmail,
                $targetEmail,
                $detail['password'],
                $description ?? '',
                $detail['status'],
            ]]);
        }

        return $detail;
    }

    /**
     * @return list<array{
     *     sourceEmail: string,
     *     suggestedLocalPart: string,
     *     description: ?string,
     *     migration: ?MailboxMigration,
     *     password: ?string
     * }>
     */
    public function listBoardRows(string $sourceDomain): array
    {
        $sourceDomain = mb_strtolower(trim($sourceDomain));
        $emails = [];

        /** @var list<EmailAccount> $localAccounts */
        $localAccounts = $this->entityManager->getRepository(EmailAccount::class)->findBy(
            ['domain' => $sourceDomain],
            ['email' => 'ASC']
        );
        foreach ($localAccounts as $account) {
            $emails[$account->getEmail()] = $account->getLabel();
        }

        try {
            foreach ($this->ovhMailboxManager->listLocalParts($sourceDomain) as $localPart) {
                $localPart = mb_strtolower(trim($localPart));
                if ('' === $localPart) {
                    continue;
                }

                $email = sprintf('%s@%s', $localPart, $sourceDomain);
                if (!isset($emails[$email])) {
                    $emails[$email] = null;
                }
            }
        } catch (\Throwable) {
            // La liste locale suffit si OVH est indisponible.
        }

        ksort($emails);
        $rows = [];
        foreach ($emails as $sourceEmail => $description) {
            $migration = $this->findForEmail($sourceEmail);
            $suggested = explode('@', $sourceEmail, 2)[0];
            $password = null;
            if ($migration instanceof MailboxMigration) {
                $suggested = explode('@', $migration->getTargetEmail(), 2)[0];
                if (null !== $migration->getDescription() && '' !== $migration->getDescription()) {
                    $description = $migration->getDescription();
                }

                try {
                    $password = $this->decryptPassword($migration);
                } catch (\Throwable) {
                    $password = null;
                }
            }

            $rows[] = [
                'sourceEmail' => $sourceEmail,
                'suggestedLocalPart' => $suggested,
                'description' => $description,
                'migration' => $migration,
                'password' => $password,
            ];
        }

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    public function exportPasswordsRows(): array
    {
        /** @var list<MailboxMigration> $migrations */
        $migrations = $this->entityManager->getRepository(MailboxMigration::class)->findBy([], ['targetEmail' => 'ASC']);
        $rows = [];
        foreach ($migrations as $migration) {
            $password = '';
            try {
                $password = $this->decryptPassword($migration) ?? '';
            } catch (\Throwable) {
                $password = '';
            }

            $rows[] = [
                $migration->getSourceEmail(),
                $migration->getTargetEmail(),
                $password,
                $migration->getDescription() ?? '',
                $migration->getStatus(),
            ];
        }

        return $rows;
    }

    /**
     * @param array{sourceEmail: string, targetLocalPart: string, description: ?string} $row
     * @return array{sourceEmail: string, targetEmail: string, status: string, message: string, counter: 'created'|'updated'|'skipped', password?: string}
     */
    private function provisionOne(
        array $row,
        string $sourceDomain,
        string $targetDomain,
        string $targetEmail,
        bool $dryRun,
        bool $force,
    ): array {
        [$sourceLocalPart] = explode('@', $row['sourceEmail'], 2);
        $sourceAccount = $this->ovhMailboxManager->find($sourceDomain, $sourceLocalPart);
        if (null === $sourceAccount) {
            throw new \RuntimeException('Compte source introuvable chez OVH.');
        }

        $alreadyExists = $this->ovhMailboxManager->exists($targetDomain, $row['targetLocalPart']);
        $password = $this->passwordGenerator->generate($row['targetLocalPart']);

        if ($dryRun) {
            $status = $alreadyExists ? ($force ? 'updated' : 'skipped') : 'created';

            return [
                'sourceEmail' => $row['sourceEmail'],
                'targetEmail' => $targetEmail,
                'status' => $status,
                'message' => $alreadyExists
                    ? ($force ? 'Le compte existe déjà, le mot de passe serait réinitialisé.' : 'Le compte existe déjà, ignoré sans --force.')
                    : 'Le compte serait créé.',
                'counter' => $status,
            ];
        }

        if ($alreadyExists && !$force) {
            $this->upsertMigration(
                $row['sourceEmail'],
                $targetEmail,
                $targetDomain,
                $row['description'],
                null,
                MailboxMigration::STATUS_SKIPPED,
                'Compte déjà présent chez OVH (relancez avec --force pour définir un mot de passe connu).'
            );

            return [
                'sourceEmail' => $row['sourceEmail'],
                'targetEmail' => $targetEmail,
                'status' => MailboxMigration::STATUS_SKIPPED,
                'message' => 'Compte déjà présent chez OVH, ignoré.',
                'counter' => 'skipped',
            ];
        }

        if ($alreadyExists) {
            $this->ovhMailboxManager->changePassword($targetDomain, $row['targetLocalPart'], $password);
            $counter = 'updated';
            $message = 'Mot de passe réinitialisé sur le compte existant.';
        } else {
            $this->ovhMailboxManager->create(
                $targetDomain,
                $row['targetLocalPart'],
                $password,
                $row['description'],
                $sourceAccount
            );
            $counter = 'created';
            $message = 'Compte créé chez OVH.';
        }

        $owner = $this->resolveOwner($row['sourceEmail']);
        $emailAccount = $this->upsertTargetEmailAccount($owner, $targetEmail, $targetDomain, $row['targetLocalPart'], $sourceAccount, $row['description']);
        $this->upsertMigration(
            $row['sourceEmail'],
            $targetEmail,
            $targetDomain,
            $row['description'],
            $this->passwordCipher->encrypt($password),
            MailboxMigration::STATUS_CREATED,
            null,
            $emailAccount
        );

        return [
            'sourceEmail' => $row['sourceEmail'],
            'targetEmail' => $targetEmail,
            'status' => MailboxMigration::STATUS_CREATED,
            'message' => $message,
            'counter' => $counter,
            'password' => $password,
        ];
    }

    public function changeClientPassword(MailboxMigration $migration, string $plainPassword): void
    {
        $this->passwordGenerator->assertValid($plainPassword);
        [$localPart, $domain] = explode('@', $migration->getTargetEmail(), 2);
        $this->ovhMailboxManager->changePassword($domain, $localPart, $plainPassword);
        $migration
            ->setPasswordEncrypted($this->passwordCipher->encrypt($plainPassword))
            ->setStatus(MailboxMigration::STATUS_CREATED)
            ->setLastError(null);
        $this->entityManager->flush();
    }

    public function decryptPassword(MailboxMigration $migration): ?string
    {
        $payload = $migration->getPasswordEncrypted();
        if (null === $payload || '' === $payload) {
            return null;
        }

        return $this->passwordCipher->decrypt($payload);
    }

    public function findForEmail(string $email): ?MailboxMigration
    {
        $email = mb_strtolower(trim($email));

        /** @var MailboxMigration|null $bySource */
        $bySource = $this->entityManager->getRepository(MailboxMigration::class)->findOneBy(
            ['sourceEmail' => $email],
            ['id' => 'DESC']
        );
        if ($bySource instanceof MailboxMigration) {
            return $bySource;
        }

        /** @var MailboxMigration|null $byTarget */
        $byTarget = $this->entityManager->getRepository(MailboxMigration::class)->findOneBy(
            ['targetEmail' => $email],
            ['id' => 'DESC']
        );

        return $byTarget;
    }

    private function resolveOwner(string $sourceEmail): User
    {
        if (isset($this->ownersByEmail[$sourceEmail])) {
            return $this->ownersByEmail[$sourceEmail];
        }

        /** @var User|null $owner */
        $owner = $this->entityManager->getRepository(User::class)->findOneBy([
            'email' => $sourceEmail,
        ]);

        if ($owner instanceof User) {
            $this->ownersByEmail[$sourceEmail] = $owner;

            return $owner;
        }

        /** @var EmailAccount|null $sourceAccount */
        $sourceAccount = $this->entityManager->getRepository(EmailAccount::class)->findOneBy([
            'email' => $sourceEmail,
        ]);
        if ($sourceAccount instanceof EmailAccount) {
            $this->ownersByEmail[$sourceEmail] = $sourceAccount->getOwner();

            return $sourceAccount->getOwner();
        }

        $owner = new User($sourceEmail);
        $owner->setRoles($this->adminRoleResolver->resolveRoles($sourceEmail));
        $this->entityManager->persist($owner);
        $this->ownersByEmail[$sourceEmail] = $owner;

        return $owner;
    }

    /**
     * @param array<string, mixed> $sourceAccount
     */
    private function upsertTargetEmailAccount(
        User $owner,
        string $targetEmail,
        string $targetDomain,
        string $localPart,
        array $sourceAccount,
        ?string $description,
    ): EmailAccount {
        /** @var EmailAccount|null $emailAccount */
        $emailAccount = $this->entityManager->getRepository(EmailAccount::class)->findOneBy([
            'email' => $targetEmail,
        ]);

        if (!$emailAccount instanceof EmailAccount) {
            $emailAccount = new EmailAccount($owner, $targetEmail, $targetDomain);
            $this->entityManager->persist($emailAccount);
        }

        $label = $description;
        if (null === $label || '' === $label) {
            $label = isset($sourceAccount['description']) ? trim((string) $sourceAccount['description']) : $localPart;
        }

        $emailAccount
            ->setOwner($owner)
            ->setLabel('' !== $label ? $label : $localPart)
            ->setOvhIdentifier($localPart)
            ->setSyncedAt(new \DateTimeImmutable());

        return $emailAccount;
    }

    private function upsertMigration(
        string $sourceEmail,
        string $targetEmail,
        string $targetDomain,
        ?string $description,
        ?string $passwordEncrypted,
        string $status,
        ?string $lastError,
        ?EmailAccount $targetEmailAccount = null,
    ): MailboxMigration {
        /** @var MailboxMigration|null $migration */
        $migration = $this->entityManager->getRepository(MailboxMigration::class)->findOneBy([
            'targetEmail' => $targetEmail,
        ]);

        if (!$migration instanceof MailboxMigration) {
            $migration = new MailboxMigration($this->resolveOwner($sourceEmail), $sourceEmail, $targetEmail, $targetDomain);
            $this->entityManager->persist($migration);
        }

        $migration
            ->setDescription($description)
            ->setStatus($status)
            ->setLastError($lastError);

        if (null !== $passwordEncrypted) {
            $migration->setPasswordEncrypted($passwordEncrypted);
        }

        if ($targetEmailAccount instanceof EmailAccount) {
            $migration->setTargetEmailAccount($targetEmailAccount);
        }

        if (MailboxMigration::STATUS_CREATED === $status) {
            $migration->markProvisioned();
        }

        return $migration;
    }

    private function persistError(
        string $sourceEmail,
        string $targetEmail,
        string $targetDomain,
        ?string $description,
        string $message,
        bool $dryRun,
    ): void {
        if ($dryRun) {
            return;
        }

        try {
            $this->upsertMigration(
                $sourceEmail,
                $targetEmail,
                $targetDomain,
                $description,
                null,
                MailboxMigration::STATUS_ERROR,
                $message
            );
        } catch (\Throwable) {
            // L'erreur métier est déjà remontée à l'appelant.
        }
    }

    /**
     * @param list<list<string>> $rows
     */
    private function appendPasswordRows(string $path, array $rows): void
    {
        $this->ensureDirectory($path);
        $isNew = !is_file($path) || 0 === filesize($path);
        $handle = fopen($path, 'ab');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Impossible d’écrire le fichier des mots de passe: %s', $path));
        }

        try {
            if ($isNew) {
                fputcsv($handle, ['source_email', 'target_email', 'password', 'description', 'status'], ',', '"', '\\');
            }
            foreach ($rows as $row) {
                fputcsv($handle, $row, ',', '"', '\\');
            }
        } finally {
            fclose($handle);
        }

        @chmod($path, 0600);
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Impossible de créer le dossier: %s', $directory));
        }
    }
}
