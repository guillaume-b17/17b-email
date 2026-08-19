<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Entity\MailboxMigration;
use App\Sync\Service\AdminMailboxSynchronizer;
use App\Sync\Service\DomainMigrationProvisioner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/migration-17b')]
final class AdminDomainMigrationController extends AbstractController
{
    public function __construct(
        private readonly DomainMigrationProvisioner $domainMigrationProvisioner,
        private readonly AdminMailboxSynchronizer $adminMailboxSynchronizer,
        #[Autowire('%env(string:APP_MIGRATION_SOURCE_DOMAIN)%')]
        private readonly string $sourceDomain,
        #[Autowire('%env(string:APP_MIGRATION_TARGET_DOMAIN)%')]
        private readonly string $targetDomain,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('', name: 'app_admin_domain_migration', methods: ['GET'])]
    public function index(): Response
    {
        $rows = $this->domainMigrationProvisioner->listBoardRows($this->sourceDomain);
        $targetAccounts = $this->domainMigrationProvisioner->listTargetAccounts($this->targetDomain);
        $existingTargetEmails = [];
        foreach ($targetAccounts['accounts'] as $account) {
            if ($account['onOvh']) {
                $existingTargetEmails[$account['email']] = true;
            }
        }

        return $this->render('admin/domain_migration/index.html.twig', [
            'rows' => $rows,
            'targetAccounts' => $targetAccounts['accounts'],
            'targetAccountsError' => $targetAccounts['error'],
            'existingTargetEmails' => $existingTargetEmails,
            'sourceDomain' => $this->sourceDomain,
            'targetDomain' => $this->targetDomain,
        ]);
    }

    #[Route('/creer', name: 'app_admin_domain_migration_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_create_17b_account', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_admin_domain_migration');
        }

        if (\function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $sourceEmail = mb_strtolower(trim((string) $request->request->get('sourceEmail', '')));
        $targetLocalPart = mb_strtolower(trim((string) $request->request->get('targetLocalPart', '')));
        $description = trim((string) $request->request->get('description', ''));
        $force = '1' === (string) $request->request->get('force', '0');

        try {
            $detail = $this->domainMigrationProvisioner->provisionAccount(
                $sourceEmail,
                $targetLocalPart,
                $this->sourceDomain,
                $this->targetDomain,
                '' === $description ? null : $description,
                $force,
                $this->projectDir.'/var/share/17b-passwords.csv'
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_admin_domain_migration');
        } catch (\Throwable $exception) {
            $this->addFlash('error', sprintf('Création OVH impossible: %s', $exception->getMessage()));

            return $this->redirectToRoute('app_admin_domain_migration');
        }

        if (MailboxMigration::STATUS_SKIPPED === $detail['status']) {
            $this->addFlash(
                'error',
                sprintf(
                    '%s existe déjà chez OVH. Cochez « Réinitialiser le mot de passe » pour imposer un mot de passe connu.',
                    $detail['targetEmail']
                )
            );

            return $this->redirectToRoute('app_admin_domain_migration');
        }

        $password = $detail['password'] ?? null;
        if (is_string($password) && '' !== $password) {
            $this->addFlash(
                'success',
                sprintf(
                    'Compte %s prêt. Mot de passe: %s — la personne peut aussi le retrouver après connexion sur Compte 17b.fr.',
                    $detail['targetEmail'],
                    $password
                )
            );
        } else {
            $this->addFlash('success', $detail['message']);
        }

        return $this->redirectToRoute('app_admin_domain_migration');
    }

    #[Route('/synchroniser', name: 'app_admin_domain_migration_sync', methods: ['POST'])]
    public function synchronize(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_sync_17b_accounts', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_admin_domain_migration');
        }

        if (\function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        try {
            $result = $this->adminMailboxSynchronizer->synchronizeDomain($this->targetDomain);
        } catch (\Throwable $exception) {
            $this->addFlash('error', sprintf('Synchronisation OVH impossible: %s', $exception->getMessage()));

            return $this->redirectToRoute('app_admin_domain_migration');
        }

        $this->addFlash(
            'success',
            sprintf(
                'Synchronisation @%s terminée (%d créés, %d mis à jour, %d ignorés).',
                $this->targetDomain,
                $result['created'],
                $result['updated'],
                $result['skipped']
            )
        );
        foreach ($result['errors'] as $error) {
            $this->addFlash('error', $error);
        }

        return $this->redirectToRoute('app_admin_domain_migration');
    }

    #[Route('/mots-de-passe.csv', name: 'app_admin_domain_migration_passwords', methods: ['GET'])]
    public function downloadPasswords(): Response
    {
        $handle = fopen('php://temp', 'w+');
        if (false === $handle) {
            throw new \RuntimeException('Impossible de préparer le CSV.');
        }

        fputcsv($handle, ['source_email', 'target_email', 'password', 'description', 'status'], ',', '"', '\\');
        foreach ($this->domainMigrationProvisioner->exportPasswordsRows() as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $response = new Response(false === $csv ? '' : $csv);
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            '17b-passwords.csv'
        );
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }
}
