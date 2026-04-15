<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(name: 'idx_redirection_owner', columns: ['owner_id'])]
class Redirection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $sourceEmail;

    #[ORM\Column(length: 180)]
    private string $destinationEmail;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $localCopy = false;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ovhId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(inversedBy: 'redirections')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EmailAccount $emailAccount;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    public function __construct(User $owner, EmailAccount $emailAccount, string $sourceEmail, string $destinationEmail)
    {
        $this->owner = $owner;
        $this->emailAccount = $emailAccount;
        $this->sourceEmail = mb_strtolower($sourceEmail);
        $this->destinationEmail = mb_strtolower($destinationEmail);
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

    public function setSourceEmail(string $sourceEmail): self
    {
        $this->sourceEmail = mb_strtolower($sourceEmail);
        $this->touch();

        return $this;
    }

    public function getDestinationEmail(): string
    {
        return $this->destinationEmail;
    }

    public function setDestinationEmail(string $destinationEmail): self
    {
        $this->destinationEmail = mb_strtolower($destinationEmail);
        $this->touch();

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        $this->touch();

        return $this;
    }

    public function getOvhId(): ?string
    {
        return $this->ovhId;
    }

    public function setOvhId(?string $ovhId): self
    {
        $this->ovhId = $ovhId;
        $this->touch();

        return $this;
    }

    public function isLocalCopy(): bool
    {
        return $this->localCopy;
    }

    public function setLocalCopy(bool $localCopy): self
    {
        $this->localCopy = $localCopy;
        $this->touch();

        return $this;
    }

    public function getEmailAccount(): EmailAccount
    {
        return $this->emailAccount;
    }

    public function setEmailAccount(EmailAccount $emailAccount): self
    {
        $this->emailAccount = $emailAccount;
        $this->touch();

        return $this;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): self
    {
        $this->owner = $owner;
        $this->touch();

        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
