<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Crée la table job_run (rapports d'exécution des commandes transito:*).
 *
 * ⚠️ Générée manuellement pour la plateforme MySQL/MariaDB (la plus courante pour cette stack).
 * Si votre projet est en PostgreSQL, ou si le nom de fichier entre en conflit avec une migration
 * existante, préférez régénérer via :
 *   php bin/console make:migration
 * après avoir ajouté src/Entity/JobRun.php — Doctrine calculera le diff exact pour votre DBAL.
 */
final class Version20260908120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table job_run pour tracer les exécutions des commandes transito:*';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE job_run (
                id INT AUTO_INCREMENT NOT NULL,
                command_name VARCHAR(191) NOT NULL,
                status VARCHAR(20) NOT NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME DEFAULT NULL,
                duration_ms INT DEFAULT NULL,
                exit_code SMALLINT DEFAULT NULL,
                summary JSON DEFAULT NULL,
                issues JSON DEFAULT NULL,
                error_message LONGTEXT DEFAULT NULL,
                error_trace LONGTEXT DEFAULT NULL,
                host VARCHAR(191) DEFAULT NULL,
                triggered_by VARCHAR(20) DEFAULT NULL,
                INDEX idx_job_run_command_started (command_name, started_at),
                INDEX idx_job_run_status (status),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE job_run');
    }
}
