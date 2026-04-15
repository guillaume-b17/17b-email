<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_email_account_email', columns: ['email'])]
#[ORM\Index(name: 'idx_email_account_domain', columns: ['domain'])]
class EmailAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 120)]
    private string $domain;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(nullable: true)]
    private ?int $quotaMb = null;

    #[ORM\Column(nullable: true)]
    private ?int $usageMb = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $ovhIdentifier = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(inversedBy: 'emailAccounts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /**
     * @var Collection<int, Redirection>
     */
    #[ORM\OneToMany(mappedBy: 'emailAccount', targetEntity: Redirection::class, orphanRemoval: true)]
    private Collection $redirections;

    #[ORM\OneToOne(mappedBy: 'emailAccount', targetEntity: Responder::class, orphanRemoval: true)]
    private ?Responder $responder = null;

    public function __construct(User $owner, string $email, string $domain)
    {
        $this->owner = $owner;
        $this->email = mb_strtolower($email);
        $this->domain = mb_strtolower($domain);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->redirections = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = mb_strtolower($email);
        $this->touch();

        return $this;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): self
    {
        $this->domain = mb_strtolower($domain);
        $this->touch();

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $label;
        $this->touch();

        return $this;
    }

    public function getQuotaMb(): ?int
    {
        return $this->quotaMb;
    }

    public function setQuotaMb(?int $quotaMb): self
    {
        $this->quotaMb = $quotaMb;
        $this->touch();

        return $this;
    }

    public function getUsageMb(): ?int
    {
        return $this->usageMb;
    }

    public function setUsageMb(?int $usageMb): self
    {
        $this->usageMb = $usageMb;
        $this->touch();

        return $this;
    }

    public function getOvhIdentifier(): ?string
    {
        return $this->ovhIdentifier;
    }

    public function setOvhIdentifier(?string $ovhIdentifier): self
    {
        $this->ovhIdentifier = $ovhIdentifier;
        $this->touch();

        return $this;
    }

    public function getSyncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(?\DateTimeImmutable $syncedAt): self
    {
        $this->syncedAt = $syncedAt;
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

    /**
     * @return Collection<int, Redirection>
     */
    public function getRedirections(): Collection
    {
        return $this->redirections;
    }

    public function getResponder(): ?Responder
    {
        return $this->responder;
    }

    public function setResponder(?Responder $responder): self
    {
        if ($responder !== null && $responder->getEmailAccount() !== $this) {
            $responder->setEmailAccount($this);
        }

        $this->responder = $responder;
        $this->touch();

        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
