<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Entity\EmailAccount;
use App\Sync\Service\AdminMailboxSynchronizer;
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
    ) {
    }

    #[Route('', name: 'app_admin_accounts', methods: ['GET'])]
    public function index(Request $request): Response
    {
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

        /** @var list<EmailAccount> $accounts */
        $accounts = $this->entityManager->getRepository(EmailAccount::class)->findBy([], ['email' => 'ASC']);

        return $this->render('admin/accounts/index.html.twig', [
            'accounts' => $accounts,
        ]);
    }
}
