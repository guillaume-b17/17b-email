<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Entity\EmailAccount;
use App\Entity\Redirection;
use Doctrine\ORM\EntityManagerInterface;

final class RedirectionSelfCopyCleaner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OvhRedirectionManager $ovhRedirectionManager,
    ) {
    }

    public function cleanupIfUnused(EmailAccount $emailAccount): void
    {
        /** @var list<Redirection> $activeOutgoingLocalCopies */
        $activeOutgoingLocalCopies = $this->entityManager->getRepository(Redirection::class)->findBy([
            'owner' => $emailAccount->getOwner(),
            'emailAccount' => $emailAccount,
            'sourceEmail' => $emailAccount->getEmail(),
            'localCopy' => true,
        ]);

        foreach ($activeOutgoingLocalCopies as $candidate) {
            if (
                null !== $candidate->getOvhId()
                && $candidate->getDestinationEmail() !== $emailAccount->getEmail()
            ) {
                return;
            }
        }

        $this->ovhRedirectionManager->deleteSelfCopyRedirections($emailAccount);

        /** @var list<Redirection> $selfCopies */
        $selfCopies = $this->entityManager->getRepository(Redirection::class)->findBy([
            'owner' => $emailAccount->getOwner(),
            'emailAccount' => $emailAccount,
            'sourceEmail' => $emailAccount->getEmail(),
            'destinationEmail' => $emailAccount->getEmail(),
        ]);
        foreach ($selfCopies as $selfCopy) {
            $this->entityManager->remove($selfCopy);
        }
    }
}
