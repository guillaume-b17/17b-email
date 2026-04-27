<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Entity\EmailAccount;
use App\Entity\Redirection;
use App\Entity\Responder;
use App\Sync\Service\AdminMailboxSynchronizer;
use App\Sync\Service\OvhResponderManager;
use App\Sync\Service\UserRedirectionSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/comptes')]
final class AdminAccountsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminMailboxSynchronizer $adminMailboxSynchronizer,
        private readonly OvhResponderManager $ovhResponderManager,
        private readonly UserRedirectionSynchronizer $userRedirectionSynchronizer,
    ) {
    }

    #[Route('', name: 'app_admin_accounts', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (
            '1' === $request->query->get('sync')
            || '1' === $request->query->get('syncResponders')
            || '1' === $request->query->get('syncRedirections')
        ) {
            if (\function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
        }

        if ('1' === $request->query->get('sync')) {
            try {
                $result = $this->adminMailboxSynchronizer->synchronizeAll();
                $this->addFlash(
                    'success',
                    sprintf(
                        'Synchronisation globale terminée (%d créés, %d mis à jour, %d ignorés).',
                        $result['created'],
                        $result['updated'],
                        $result['skipped']
                    )
                );

                foreach ($result['errors'] as $error) {
                    $this->addFlash('error', $error);
                }
            } catch (\Throwable $exception) {
                $this->addFlash('error', sprintf('Erreur synchro globale: %s', $exception->getMessage()));
            }
        }
        if ('1' === $request->query->get('syncResponders')) {
            $syncedResponders = 0;
            $responderErrors = 0;

            /** @var list<EmailAccount> $accountsForResponderSync */
            $accountsForResponderSync = $this->entityManager->getRepository(EmailAccount::class)->findBy([], ['email' => 'ASC']);
            foreach ($accountsForResponderSync as $account) {
                try {
                    $report = $this->ovhResponderManager->fetchWithDiagnostics($account);
                    if ($report['found'] && null !== $report['data']) {
                        $this->upsertLocalResponderFromSnapshot($account, $report['data']);
                        ++$syncedResponders;
                    }
                } catch (\Throwable $exception) {
                    ++$responderErrors;
                    $this->addFlash('error', sprintf('Erreur synchro répondeur %s: %s', $account->getEmail(), $exception->getMessage()));
                }
            }

            $this->addFlash('success', sprintf('Synchronisation répondeurs terminée (%d trouvés, %d erreurs).', $syncedResponders, $responderErrors));
        }
        if ('1' === $request->query->get('syncRedirections')) {
            $created = 0;
            $updated = 0;
            $removed = 0;
            $errors = 0;

            /** @var list<EmailAccount> $accountsForRedirectionSync */
            $accountsForRedirectionSync = $this->entityManager->getRepository(EmailAccount::class)->findBy([], ['email' => 'ASC']);
            foreach ($accountsForRedirectionSync as $account) {
                try {
                    $result = $this->userRedirectionSynchronizer->synchronize($account->getOwner(), $account);
                    $created += $result['created'];
                    $updated += $result['updated'];
                    $removed += $result['removed'];
                } catch (\Throwable $exception) {
                    ++$errors;
                    $this->addFlash('error', sprintf('Erreur synchro redirections %s: %s', $account->getEmail(), $exception->getMessage()));
                }
            }

            $this->addFlash(
                'success',
                sprintf(
                    'Synchronisation redirections terminée (%d créées, %d mises à jour, %d supprimées, %d erreurs).',
                    $created,
                    $updated,
                    $removed,
                    $errors
                )
            );
        }

        /** @var list<EmailAccount> $accounts */
        $accounts = $this->entityManager->getRepository(EmailAccount::class)->findBy([], ['email' => 'ASC']);
        /** @var list<Responder> $responders */
        $responders = $this->entityManager->getRepository(Responder::class)->findBy([]);
        /** @var list<Redirection> $redirections */
        $redirections = $this->entityManager->getRepository(Redirection::class)->findBy([]);

        /** @var array<int, Responder> $respondersByAccountId */
        $respondersByAccountId = [];
        foreach ($responders as $responder) {
            $accountId = $responder->getEmailAccount()->getId();
            if (null === $accountId) {
                continue;
            }

            $respondersByAccountId[$accountId] = $responder;
        }

        /** @var array<int, array{outgoing: int, incoming: int, total: int, selfCopy: int}> $redirectionSummaryByAccountId */
        $redirectionSummaryByAccountId = [];
        foreach ($accounts as $account) {
            $accountId = $account->getId();
            if (null === $accountId) {
                continue;
            }

            $redirectionSummaryByAccountId[$accountId] = [
                'outgoing' => 0,
                'incoming' => 0,
                'total' => 0,
                'selfCopy' => 0,
            ];
        }

        foreach ($redirections as $redirection) {
            $accountId = $redirection->getEmailAccount()->getId();
            if (null === $accountId || !isset($redirectionSummaryByAccountId[$accountId])) {
                continue;
            }

            ++$redirectionSummaryByAccountId[$accountId]['total'];
            if ($redirection->getSourceEmail() === $redirection->getEmailAccount()->getEmail()) {
                ++$redirectionSummaryByAccountId[$accountId]['outgoing'];
            } else {
                ++$redirectionSummaryByAccountId[$accountId]['incoming'];
            }

            if ($redirection->getSourceEmail() === $redirection->getDestinationEmail()) {
                ++$redirectionSummaryByAccountId[$accountId]['selfCopy'];
            }
        }

        return $this->render('admin/accounts/index.html.twig', [
            'accounts' => $accounts,
            'respondersByAccountId' => $respondersByAccountId,
            'redirectionSummaryByAccountId' => $redirectionSummaryByAccountId,
        ]);
    }

    /**
     * @param array{
     *     enabled: bool,
     *     subject: ?string,
     *     message: ?string,
     *     startsAt: ?\DateTimeImmutable,
     *     endsAt: ?\DateTimeImmutable
     * } $snapshot
     */
    private function upsertLocalResponderFromSnapshot(EmailAccount $emailAccount, array $snapshot): void
    {
        /** @var Responder|null $responder */
        $responder = $this->entityManager->getRepository(Responder::class)->findOneBy([
            'owner' => $emailAccount->getOwner(),
            'emailAccount' => $emailAccount,
        ]);

        if (!$responder instanceof Responder) {
            $responder = new Responder($emailAccount->getOwner(), $emailAccount);
            $this->entityManager->persist($responder);
        }

        $responder
            ->setEnabled($snapshot['enabled'])
            ->setSubject($snapshot['subject'])
            ->setMessage($snapshot['message'])
            ->setStartsAt($snapshot['startsAt'])
            ->setEndsAt($snapshot['endsAt']);

        $this->entityManager->flush();
    }
}
