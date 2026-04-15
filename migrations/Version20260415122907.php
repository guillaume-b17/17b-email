<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260415122907 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute local_copy sur redirection pour option conserver mails OVH.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE redirection ADD COLUMN local_copy BOOLEAN NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__redirection AS SELECT id, source_email, destination_email, enabled, ovh_id, created_at, updated_at, email_account_id, owner_id FROM redirection');
        $this->addSql('DROP TABLE redirection');
        $this->addSql('CREATE TABLE redirection (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, source_email VARCHAR(180) NOT NULL, destination_email VARCHAR(180) NOT NULL, enabled BOOLEAN NOT NULL, ovh_id VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, email_account_id INTEGER NOT NULL, owner_id INTEGER NOT NULL, CONSTRAINT FK_224450F737D8AD65 FOREIGN KEY (email_account_id) REFERENCES email_account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_224450F77E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO redirection (id, source_email, destination_email, enabled, ovh_id, created_at, updated_at, email_account_id, owner_id) SELECT id, source_email, destination_email, enabled, ovh_id, created_at, updated_at, email_account_id, owner_id FROM __temp__redirection');
        $this->addSql('DROP TABLE __temp__redirection');
        $this->addSql('CREATE INDEX IDX_224450F737D8AD65 ON redirection (email_account_id)');
        $this->addSql('CREATE INDEX idx_redirection_owner ON redirection (owner_id)');
    }
}
