<?php

declare(strict_types=1);

namespace App\Sync\Command;

use App\Entity\Redirection;
use App\Sync\Service\OvhRedirectionManager;
use App\Sync\Service\RedirectionScheduleHelper;
use App\Sync\Service\RedirectionSelfCopyCleaner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:redirections:apply-schedules',
    description: 'Applique les redirections programmées (création/suppression OVH) selon leurs dates.',
)]
final class ApplyRedirectionSchedulesCommand extends Command
{
    private const APP_TIMEZONE = 'Europe/Paris';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OvhRedirectionManager $ovhRedirectionManager,
        private readonly RedirectionScheduleHelper $redirectionScheduleHelper,
        private readonly RedirectionSelfCopyCleaner $redirectionSelfCopyCleaner,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::APP_TIMEZONE));

        $qb = $this->entityManager->getRepository(Redirection::class)->createQueryBuilder('r');
        /** @var list<Redirection> $redirections */
        $redirections = $qb
            ->andWhere('r.startsAt IS NOT NULL OR r.endsAt IS NOT NULL OR r.sourceEmail <> r.destinationEmail')
            ->getQuery()
            ->getResult();

        $created = 0;
        $deleted = 0;
        $updated = 0;
        $errors = 0;

        foreach ($redirections as $redirection) {
            $emailAccount = $redirection->getEmailAccount();

            // On ne programme que les redirections sortantes du compte.
            if ($redirection->getSourceEmail() !== $emailAccount->getEmail()) {
                continue;
            }

            $this->redirectionScheduleHelper->restoreAbsenceDatesFromResponder($redirection, $emailAccount);
            if (!$redirection->isScheduled() && null === $redirection->getOvhId()) {
                continue;
            }

            try {
                if ($this->redirectionScheduleHelper->shouldBeActive($redirection, $now)) {
                    if (null === $redirection->getOvhId()) {
                        $ovhId = $this->ovhRedirectionManager->create(
                            $emailAccount,
                            $redirection->getDestinationEmail(),
                            $redirection->isLocalCopy()
                        );
                        $redirection
                            ->setOvhId($ovhId)
                            ->setEnabled(true);
                        ++$created;
                    } elseif (!$redirection->isEnabled()) {
                        $redirection->setEnabled(true);
                        ++$updated;
                    }

                    continue;
                }

                if ($this->redirectionScheduleHelper->isAwaitingStart($redirection, $now)) {
                    if (null !== $redirection->getOvhId()) {
                        $this->ovhRedirectionManager->delete($redirection, $emailAccount);
                        $redirection
                            ->setOvhId(null)
                            ->setEnabled(false);
                        ++$updated;
                    } elseif ($redirection->isEnabled()) {
                        $redirection->setEnabled(false);
                        ++$updated;
                    }

                    continue;
                }

                $destinationEmail = $redirection->getDestinationEmail();
                $this->ovhRedirectionManager->delete($redirection, $emailAccount);

                $this->entityManager->remove($redirection);
                ++$deleted;

                // Toujours tenter le nettoyage de la copie locale (source==destination),
                // même si le flag localCopy a été perdu en base.
                if ($destinationEmail !== $emailAccount->getEmail()) {
                    $this->redirectionSelfCopyCleaner->cleanupIfUnused($emailAccount);
                }
            } catch (\Throwable $exception) {
                ++$errors;
                $this->logger->error('Erreur application redirection programmée', [
                    'id' => $redirection->getId(),
                    'email' => $emailAccount->getEmail(),
                    'destination' => $redirection->getDestinationEmail(),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $this->entityManager->flush();

        $this->logger->info('Application des redirections programmées terminée', [
            'created' => $created,
            'deleted' => $deleted,
            'updated' => $updated,
            'errors' => $errors,
            'now' => $now->format(\DateTimeInterface::ATOM),
        ]);

        $output->writeln(sprintf(
            'OK: %d créées, %d supprimées, %d mises à jour, %d erreurs.',
            $created,
            $deleted,
            $updated,
            $errors
        ));

        return Command::SUCCESS;
    }
}
