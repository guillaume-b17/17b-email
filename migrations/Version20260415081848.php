<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260415081848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE email_account (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, domain VARCHAR(120) NOT NULL, label VARCHAR(120) DEFAULT NULL, quota_mb INTEGER DEFAULT NULL, usage_mb INTEGER DEFAULT NULL, ovh_identifier VARCHAR(120) DEFAULT NULL, synced_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id INTEGER NOT NULL, CONSTRAINT FK_C0F63E6B7E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_C0F63E6B7E3C61F9 ON email_account (owner_id)');
        $this->addSql('CREATE INDEX idx_email_account_domain ON email_account (domain)');
        $this->addSql('CREATE UNIQUE INDEX uniq_email_account_email ON email_account (email)');
        $this->addSql('CREATE TABLE email_login_challenge (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, code_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, attempt_count INTEGER NOT NULL, request_ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_email_login_challenge_email ON email_login_challenge (email)');
        $this->addSql('CREATE INDEX idx_email_login_challenge_expires_at ON email_login_challenge (expires_at)');
        $this->addSql('CREATE TABLE organization_setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, setting_key VARCHAR(120) NOT NULL, setting_value CLOB DEFAULT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_organization_setting_key ON organization_setting (setting_key)');
        $this->addSql('CREATE TABLE redirection (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, source_email VARCHAR(180) NOT NULL, destination_email VARCHAR(180) NOT NULL, enabled BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, email_account_id INTEGER NOT NULL, owner_id INTEGER NOT NULL, CONSTRAINT FK_224450F737D8AD65 FOREIGN KEY (email_account_id) REFERENCES email_account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_224450F77E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_224450F737D8AD65 ON redirection (email_account_id)');
        $this->addSql('CREATE INDEX idx_redirection_owner ON redirection (owner_id)');
        $this->addSql('CREATE TABLE responder (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, enabled BOOLEAN NOT NULL, subject VARCHAR(180) DEFAULT NULL, message CLOB DEFAULT NULL, starts_at DATETIME DEFAULT NULL, ends_at DATETIME DEFAULT NULL, updated_at DATETIME NOT NULL, email_account_id INTEGER NOT NULL, owner_id INTEGER NOT NULL, template_id INTEGER DEFAULT NULL, CONSTRAINT FK_5F311AF737D8AD65 FOREIGN KEY (email_account_id) REFERENCES email_account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5F311AF77E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5F311AF75DA0FB8 FOREIGN KEY (template_id) REFERENCES responder_message_template (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5F311AF737D8AD65 ON responder (email_account_id)');
        $this->addSql('CREATE INDEX IDX_5F311AF75DA0FB8 ON responder (template_id)');
        $this->addSql('CREATE INDEX idx_responder_owner ON responder (owner_id)');
        $this->addSql('CREATE TABLE responder_message_template (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(120) NOT NULL, content CLOB NOT NULL, active BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_responder_template_name ON responder_message_template (name)');
        $this->addSql('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, last_login_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email ON "user" (email)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE email_account');
        $this->addSql('DROP TABLE email_login_challenge');
        $this->addSql('DROP TABLE organization_setting');
        $this->addSql('DROP TABLE redirection');
        $this->addSql('DROP TABLE responder');
        $this->addSql('DROP TABLE responder_message_template');
        $this->addSql('DROP TABLE "user"');
    }
}
