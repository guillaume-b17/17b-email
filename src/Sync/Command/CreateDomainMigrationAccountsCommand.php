<?php

declare(strict_types=1);

namespace App\Sync\Command;

use App\Sync\Service\DomainMigrationProvisioner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:domain-migration:create-accounts',
    description: 'Crée les comptes @17b.fr à partir du mapping et enregistre les mots de passe dans un CSV.',
)]
final class CreateDomainMigrationAccountsCommand extends Command
{
    public function __construct(
        private readonly DomainMigrationProvisioner $domainMigrationProvisioner,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(string:APP_MIGRATION_SOURCE_DOMAIN)%')]
        private readonly string $sourceDomain,
        #[Autowire('%env(string:APP_MIGRATION_TARGET_DOMAIN)%')]
        private readonly string $targetDomain,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'mapping',
                'm',
                InputOption::VALUE_REQUIRED,
                'CSV de mapping source_email,target_local_part,description',
                $this->projectDir.'/var/share/domain-migration-mapping.csv'
            )
            ->addOption('source-domain', null, InputOption::VALUE_REQUIRED, 'Domaine source', $this->sourceDomain)
            ->addOption('target-domain', null, InputOption::VALUE_REQUIRED, 'Domaine cible', $this->targetDomain)
            ->addOption(
                'passwords-file',
                'p',
                InputOption::VALUE_REQUIRED,
                'CSV des mots de passe générés',
                $this->projectDir.'/var/share/17b-passwords.csv'
            )
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Limiter à une adresse source (ex: jean.dupont@b17.fr)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule sans créer les comptes ni écrire les mots de passe')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Réinitialise le mot de passe si le compte cible existe déjà');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->warning('Mode simulation: aucun compte ne sera créé chez OVH.');
        }

        $result = $this->domainMigrationProvisioner->provision(
            (string) $input->getOption('mapping'),
            (string) $input->getOption('source-domain'),
            (string) $input->getOption('target-domain'),
            (string) $input->getOption('passwords-file'),
            $dryRun,
            (bool) $input->getOption('force'),
            $this->normalizeOnly($input->getOption('only'))
        );

        if ([] !== $result['details']) {
            $io->table(
                ['Source', 'Cible', 'Statut', 'Détail'],
                array_map(
                    static fn (array $detail): array => [
                        $detail['sourceEmail'],
                        $detail['targetEmail'],
                        $detail['status'],
                        $detail['message'],
                    ],
                    $result['details']
                )
            );
        }

        $io->writeln(sprintf(
            'Créés: %d | Mots de passe réinitialisés: %d | Ignorés: %d | Erreurs: %d',
            $result['created'],
            $result['updated'],
            $result['skipped'],
            $result['errors']
        ));

        if (null !== $result['passwordsFile']) {
            $io->success(sprintf('Mots de passe enregistrés dans %s (droits 0600).', $result['passwordsFile']));
            $io->writeln('Les collaborateurs peuvent ensuite configurer le compte depuis /compte/messagerie.');
        }

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function normalizeOnly(mixed $only): ?string
    {
        if (!is_string($only)) {
            return null;
        }

        $only = mb_strtolower(trim($only));

        return '' === $only ? null : $only;
    }
}
