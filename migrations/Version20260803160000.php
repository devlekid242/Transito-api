<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create system_settings table for global platform settings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE `system_settings` (
            id INT AUTO_INCREMENT NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            data JSON NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_SYSTEM_SETTINGS_KEY (setting_key),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE `system_settings`');
    }
}
