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
    name: 'app:domain-migration:export-mapping',
    description: 'Exporte les comptes @b17.fr vers un CSV de mapping (modifiable pour les changements de nom).',
)]
final class ExportDomainMigrationMappingCommand extends Command
{
    public function __construct(
        private readonly DomainMigrationProvisioner $domainMigrationProvisioner,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(string:APP_MIGRATION_SOURCE_DOMAIN)%')]
        private readonly string $sourceDomain,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source-domain', null, InputOption::VALUE_REQUIRED, 'Domaine source', $this->sourceDomain)
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Chemin du CSV de mapping',
                $this->projectDir.'/var/share/domain-migration-mapping.csv'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourceDomain = mb_strtolower(trim((string) $input->getOption('source-domain')));
        $outputPath = (string) $input->getOption('output');

        $count = $this->domainMigrationProvisioner->exportMapping($sourceDomain, $outputPath);

        $io->success(sprintf('%d comptes exportés vers %s', $count, $outputPath));
        $io->writeln('Éditez la colonne <info>target_local_part</info> pour les collaborateurs qui changent de nom, puis lancez:');
        $io->writeln('  php bin/console app:domain-migration:create-accounts --dry-run');

        return Command::SUCCESS;
    }
}
