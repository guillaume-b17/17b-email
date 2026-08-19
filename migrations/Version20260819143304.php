<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260819143304 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le suivi de migration des boîtes b17.fr vers 17b.fr.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mailbox_migration (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, source_email VARCHAR(180) NOT NULL, target_email VARCHAR(180) NOT NULL, target_domain VARCHAR(120) NOT NULL, description VARCHAR(180) DEFAULT NULL, password_encrypted CLOB DEFAULT NULL, status VARCHAR(20) NOT NULL, last_error CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, provisioned_at DATETIME DEFAULT NULL, owner_id INTEGER NOT NULL, target_email_account_id INTEGER DEFAULT NULL, CONSTRAINT FK_B07B064C7E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_B07B064CE3219365 FOREIGN KEY (target_email_account_id) REFERENCES email_account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_B07B064C7E3C61F9 ON mailbox_migration (owner_id)');
        $this->addSql('CREATE INDEX IDX_B07B064CE3219365 ON mailbox_migration (target_email_account_id)');
        $this->addSql('CREATE INDEX idx_mailbox_migration_source ON mailbox_migration (source_email)');
        $this->addSql('CREATE UNIQUE INDEX uniq_mailbox_migration_target ON mailbox_migration (target_email)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE mailbox_migration');
    }
}
