<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_organization_setting_key', columns: ['setting_key'])]
class OrganizationSetting
{
    public const AGENCY_PHONE_KEY = 'agency_phone';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'setting_key', length: 120)]
    private string $settingKey;

    #[ORM\Column(name: 'setting_value', type: 'text', nullable: true)]
    private ?string $settingValue = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $settingKey, ?string $settingValue)
    {
        $this->settingKey = $settingKey;
        $this->settingValue = $settingValue;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSettingKey(): string
    {
        return $this->settingKey;
    }

    public function getSettingValue(): ?string
    {
        return $this->settingValue;
    }

    public function setSettingValue(?string $settingValue): self
    {
        $this->settingValue = $settingValue;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
