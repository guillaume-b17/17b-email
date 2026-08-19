<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_mailbox_migration_target', columns: ['target_email'])]
#[ORM\Index(name: 'idx_mailbox_migration_source', columns: ['source_email'])]
class MailboxMigration
{
    public const STATUS_CREATED = 'created';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $sourceEmail;

    #[ORM\Column(length: 180)]
    private string $targetEmail;

    #[ORM\Column(length: 120)]
    private string $targetDomain;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $passwordEncrypted = null;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $provisionedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EmailAccount $targetEmailAccount = null;

    public function __construct(User $owner, string $sourceEmail, string $targetEmail, string $targetDomain)
    {
        $this->owner = $owner;
        $this->sourceEmail = mb_strtolower($sourceEmail);
        $this->targetEmail = mb_strtolower($targetEmail);
        $this->targetDomain = mb_strtolower($targetDomain);
        $this->status = self::STATUS_CREATED;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceEmail(): string
    {
        return $this->sourceEmail;
    }

    public function getTargetEmail(): string
    {
        return $this->targetEmail;
    }

    public function getTargetDomain(): string
    {
        return $this->targetDomain;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->touch();

        return $this;
    }

    public function getPasswordEncrypted(): ?string
    {
        return $this->passwordEncrypted;
    }

    public function setPasswordEncrypted(?string $passwordEncrypted): self
    {
        $this->passwordEncrypted = $passwordEncrypted;
        $this->touch();

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function isReadyForClientSetup(): bool
    {
        return self::STATUS_CREATED === $this->status && null !== $this->passwordEncrypted;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): self
    {
        $this->lastError = $lastError;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getProvisionedAt(): ?\DateTimeImmutable
    {
        return $this->provisionedAt;
    }

    public function markProvisioned(): self
    {
        $this->provisionedAt = new \DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getTargetEmailAccount(): ?EmailAccount
    {
        return $this->targetEmailAccount;
    }

    public function setTargetEmailAccount(?EmailAccount $targetEmailAccount): self
    {
        $this->targetEmailAccount = $targetEmailAccount;
        $this->touch();

        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
