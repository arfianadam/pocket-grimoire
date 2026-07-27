<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create distributed character draw sessions and numbered slots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE draw_sessions (id INT AUTO_INCREMENT NOT NULL, public_id VARCHAR(64) NOT NULL, host_secret_hash VARCHAR(64) NOT NULL, role_snapshots JSON NOT NULL, next_draw_index INT DEFAULT 0 NOT NULL, status VARCHAR(16) DEFAULT \'active\' NOT NULL, version INT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX draw_session_public_id_unique (public_id), INDEX draw_session_expiry_idx (expires_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE draw_slots (id INT AUTO_INCREMENT NOT NULL, session_id INT NOT NULL, number INT NOT NULL, state VARCHAR(16) DEFAULT \'available\' NOT NULL, role_index INT DEFAULT NULL, claim_secret_hash VARCHAR(64) DEFAULT NULL, player_name VARCHAR(80) DEFAULT NULL, claimed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_23D31140613FECDF (session_id), UNIQUE INDEX draw_slot_session_number_unique (session_id, number), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE draw_slots ADD CONSTRAINT FK_DRAW_SLOT_SESSION FOREIGN KEY (session_id) REFERENCES draw_sessions (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE draw_slots DROP FOREIGN KEY FK_DRAW_SLOT_SESSION');
        $this->addSql('DROP TABLE draw_slots');
        $this->addSql('DROP TABLE draw_sessions');
    }
}
