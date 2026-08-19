<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Entity\EmailAccount;
use App\Entity\Redirection;
use App\Entity\Responder;
use Doctrine\ORM\EntityManagerInterface;

final class RedirectionScheduleHelper
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Les redirections d'absence (wizard) peuvent perdre leurs dates après une synchro OVH.
     * On les récupère depuis le répondeur du compte quand c'est pertinent.
     */
    public function restoreAbsenceDatesFromResponder(Redirection $redirection, EmailAccount $emailAccount): void
    {
        if ($redirection->isScheduled()) {
            return;
        }

        $accountEmail = mb_strtolower($emailAccount->getEmail());
        if ($redirection->getSourceEmail() !== $accountEmail) {
            return;
        }

        if ($redirection->getDestinationEmail() === $accountEmail) {
            return;
        }

        if (!$redirection->isLocalCopy() && !$this->hasSelfCopyRedirection($emailAccount)) {
            return;
        }

        $responder = $this->entityManager->getRepository(Responder::class)->findOneBy([
            'emailAccount' => $emailAccount,
        ]);
        if (!$responder instanceof Responder) {
            return;
        }

        if (!$responder->getEndsAt() instanceof \DateTimeImmutable) {
            return;
        }

        $redirection
            ->setStartsAt($responder->getStartsAt())
            ->setEndsAt($responder->getEndsAt());
    }

    private function hasSelfCopyRedirection(EmailAccount $emailAccount): bool
    {
        $accountEmail = mb_strtolower($emailAccount->getEmail());

        return null !== $this->entityManager->getRepository(Redirection::class)->findOneBy([
            'emailAccount' => $emailAccount,
            'sourceEmail' => $accountEmail,
            'destinationEmail' => $accountEmail,
        ]);
    }

    public function shouldBeActive(Redirection $redirection, \DateTimeImmutable $now): bool
    {
        $startsAt = $redirection->getStartsAt();
        $endsAt = $redirection->getEndsAt();

        return (null === $startsAt || $startsAt <= $now) && (null === $endsAt || $endsAt > $now);
    }

    public function isExpired(Redirection $redirection, \DateTimeImmutable $now): bool
    {
        if (!$redirection->isScheduled()) {
            return false;
        }

        $startsAt = $redirection->getStartsAt();
        if ($startsAt instanceof \DateTimeImmutable && $startsAt > $now) {
            return false;
        }

        $endsAt = $redirection->getEndsAt();
        if ($endsAt instanceof \DateTimeImmutable && $endsAt <= $now) {
            return true;
        }

        return false;
    }

    public function isAwaitingStart(Redirection $redirection, \DateTimeImmutable $now): bool
    {
        $startsAt = $redirection->getStartsAt();

        return $startsAt instanceof \DateTimeImmutable && $startsAt > $now;
    }
}
