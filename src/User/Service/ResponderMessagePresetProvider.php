<?php

declare(strict_types=1);

namespace App\User\Service;

final class ResponderMessagePresetProvider
{
    public const AGENCY_PHONE = '02 40 89 78 74';

    /**
     * @return array<string, array{label: string, content: string}>
     */
    public function all(): array
    {
        return [
            'absence_femme' => [
                'label' => 'Absence (formulation femme)',
                'content' => <<<'TEXT'
Bonjour,

Actuellement absente, je prendrai connaissance de votre mail lors de mon retour le {date_fin}.

Pour toute demande urgente, merci de contacter vos interlocuteurs habituels ou l'agence au {telephone_agence}.

Cordialement,
{prenom_nom}
TEXT,
            ],
            'absence_homme' => [
                'label' => 'Absence (formulation homme)',
                'content' => <<<'TEXT'
Bonjour,

Actuellement absent, je prendrai connaissance de votre mail lors de mon retour le {date_fin}.

Pour toute demande urgente, merci de contacter vos interlocuteurs habituels ou l'agence au {telephone_agence}.

Cordialement,
{prenom_nom}
TEXT,
            ],
            'conges' => [
                'label' => 'Congés (neutre)',
                'content' => <<<'TEXT'
Bonjour,

Je suis en congés du {date_debut} au {date_fin}. Je prendrai connaissance de votre message à mon retour.

Pour toute demande urgente, merci de contacter vos interlocuteurs habituels ou l'agence au {telephone_agence}.

Cordialement,
{prenom_nom}
TEXT,
            ],
        ];
    }

    public function phoneNumber(): string
    {
        return self::AGENCY_PHONE;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function contentFor(string $key): ?string
    {
        $presets = $this->all();

        return $presets[$key]['content'] ?? null;
    }

    public function applyVariables(string $message, ?\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt, ?string $fullName = null): string
    {
        $fullName = null !== $fullName ? trim($fullName) : '';
        if ('' === $fullName) {
            $fullName = ' ';
        }

        return strtr($message, [
            '{date_debut}' => $this->formatDate($startsAt),
            '{date_fin}' => $this->formatDate($endsAt),
            '{telephone_agence}' => self::AGENCY_PHONE,
            '{prenom_nom}' => $fullName,
        ]);
    }

    private function formatDate(?\DateTimeImmutable $date): string
    {
        if (!$date instanceof \DateTimeImmutable) {
            return 'date non définie';
        }

        if (\class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter(
                'fr_FR',
                \IntlDateFormatter::FULL,
                \IntlDateFormatter::NONE,
                'Europe/Paris',
                \IntlDateFormatter::GREGORIAN,
                "EEEE d MMMM y"
            );

            $formatted = $formatter->format($date);
            if (false !== $formatted) {
                return mb_strtolower($formatted);
            }
        }

        return $date->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y');
    }
}
