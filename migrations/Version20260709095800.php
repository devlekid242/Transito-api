<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709095800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE `agencies` (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, logo_url VARCHAR(255) DEFAULT NULL, registration_number VARCHAR(100) DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, banner_url VARCHAR(500) DEFAULT NULL, website_url VARCHAR(255) DEFAULT NULL, map_url VARCHAR(500) DEFAULT NULL, description LONGTEXT DEFAULT NULL, phone VARCHAR(20) NOT NULL, email VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, rating_cache NUMERIC(3, 2) DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, UNIQUE INDEX UNIQ_F65A4DC4E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `agency_documents` (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, file_url VARCHAR(500) NOT NULL, type VARCHAR(50) DEFAULT NULL, status VARCHAR(50) DEFAULT \'pending\' NOT NULL, expiry_date DATETIME DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, agency_id INT NOT NULL, INDEX IDX_88C3437DCDEADB2A (agency_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `agency_points` (id INT AUTO_INCREMENT NOT NULL, city VARCHAR(100) NOT NULL, name VARCHAR(150) NOT NULL, address VARCHAR(255) DEFAULT NULL, quartier VARCHAR(100) DEFAULT NULL, phone VARCHAR(20) DEFAULT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, point_type VARCHAR(40) DEFAULT \'principal\' NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, is_active SMALLINT DEFAULT 1 NOT NULL, has_vip_lounge SMALLINT DEFAULT 0 NOT NULL, has_wifi SMALLINT DEFAULT 0 NOT NULL, has_ac SMALLINT DEFAULT 0 NOT NULL, has_parking SMALLINT DEFAULT 0 NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, agency_id INT NOT NULL, INDEX IDX_FB624F69CDEADB2A (agency_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `agents` (id INT AUTO_INCREMENT NOT NULL, agent_role VARCHAR(50) DEFAULT \'agent_quai\' NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, user_id INT NOT NULL, agency_id INT NOT NULL, UNIQUE INDEX UNIQ_9596AB6EA76ED395 (user_id), INDEX IDX_9596AB6ECDEADB2A (agency_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `baggages` (id INT AUTO_INCREMENT NOT NULL, weight DOUBLE PRECISION NOT NULL, description VARCHAR(255) NOT NULL, baggage_type VARCHAR(50) NOT NULL, reservation_id INT NOT NULL, INDEX IDX_FB4A59E6B83297E7 (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `buses` (id INT AUTO_INCREMENT NOT NULL, registration_number VARCHAR(30) NOT NULL, capacity INT NOT NULL, category VARCHAR(30) DEFAULT \'Classique\' NOT NULL, status VARCHAR(30) DEFAULT \'disponible\' NOT NULL, brand VARCHAR(100) DEFAULT NULL, model VARCHAR(100) DEFAULT NULL, color VARCHAR(50) DEFAULT NULL, acquisition_date DATE DEFAULT NULL, mileage INT DEFAULT NULL, last_maintenance_date DATE DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, agency_id INT NOT NULL, UNIQUE INDEX UNIQ_FE00EAF338CEDFBE (registration_number), INDEX IDX_FE00EAF3CDEADB2A (agency_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `cities` (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, code VARCHAR(10) DEFAULT NULL, country VARCHAR(100) DEFAULT NULL, is_active SMALLINT DEFAULT 1 NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, UNIQUE INDEX UNIQ_D95DB16B5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `notifications` (id INT AUTO_INCREMENT NOT NULL, recipient_type VARCHAR(50) NOT NULL, recipient_id INT DEFAULT NULL, title VARCHAR(150) NOT NULL, content LONGTEXT NOT NULL, category VARCHAR(50) DEFAULT \'INFO\' NOT NULL, payload JSON DEFAULT NULL, is_read SMALLINT DEFAULT 0 NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `payment_logs` (id INT AUTO_INCREMENT NOT NULL, operator VARCHAR(50) NOT NULL, reference VARCHAR(100) NOT NULL, amount NUMERIC(10, 2) NOT NULL, status VARCHAR(30) NOT NULL, raw_response LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, reservation_id INT NOT NULL, INDEX IDX_D10C5128B83297E7 (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `promo_codes` (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(50) NOT NULL, discount_type VARCHAR(20) NOT NULL, discount_value NUMERIC(10, 2) NOT NULL, valid_from DATETIME DEFAULT NULL, valid_until DATETIME DEFAULT NULL, max_uses INT DEFAULT NULL, current_uses INT DEFAULT 0 NOT NULL, is_active SMALLINT DEFAULT 1 NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, UNIQUE INDEX UNIQ_C84FDDB77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE refresh_tokens (id INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_9BACE7E1A76ED395 (user_id), UNIQUE INDEX UNIQ_REFRESH_TOKEN_HASH (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `reservations` (id INT AUTO_INCREMENT NOT NULL, total_amount NUMERIC(10, 2) NOT NULL, payment_phone VARCHAR(20) NOT NULL, payment_method VARCHAR(50) NOT NULL, payment_status VARCHAR(30) DEFAULT \'en_attente\' NOT NULL, transaction_reference VARCHAR(100) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, user_id INT NOT NULL, trip_id INT NOT NULL, UNIQUE INDEX UNIQ_4DA239ED84D250 (transaction_reference), INDEX IDX_4DA239A76ED395 (user_id), INDEX IDX_4DA239A5BC2E0E (trip_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `reviews` (id INT AUTO_INCREMENT NOT NULL, rating INT NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, user_id INT NOT NULL, agency_id INT NOT NULL, INDEX IDX_6970EB0FA76ED395 (user_id), INDEX IDX_6970EB0FCDEADB2A (agency_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `support_responses` (id INT AUTO_INCREMENT NOT NULL, message LONGTEXT NOT NULL, created_at VARCHAR(255) NOT NULL, ticket_id INT NOT NULL, agent_id INT DEFAULT NULL, INDEX IDX_878D0422700047D2 (ticket_id), INDEX IDX_878D04223414710B (agent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `support_tickets` (id INT AUTO_INCREMENT NOT NULL, subject VARCHAR(255) NOT NULL, message LONGTEXT NOT NULL, category VARCHAR(50) NOT NULL, status VARCHAR(30) DEFAULT \'open\' NOT NULL, priority VARCHAR(20) DEFAULT \'medium\' NOT NULL, created_at VARCHAR(255) NOT NULL, user_id INT NOT NULL, INDEX IDX_E9739508A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `tickets` (id INT AUTO_INCREMENT NOT NULL, passenger_name VARCHAR(100) NOT NULL, passenger_phone VARCHAR(20) NOT NULL, passenger_cni VARCHAR(50) NOT NULL, seat_number INT NOT NULL, qr_code_token VARCHAR(255) NOT NULL, status VARCHAR(30) DEFAULT \'en_attente\' NOT NULL, validated_at DATETIME DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, reservation_id INT NOT NULL, validated_by_agent_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_54469DF41BC9050B (qr_code_token), INDEX IDX_54469DF4B83297E7 (reservation_id), INDEX IDX_54469DF42C1CCDB2 (validated_by_agent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `trips` (id INT AUTO_INCREMENT NOT NULL, departure_city VARCHAR(100) DEFAULT NULL, arrival_city VARCHAR(100) DEFAULT NULL, boarding_points JSON NOT NULL, deboarding_points JSON NOT NULL, departure_time DATETIME NOT NULL, estimated_arrival_time DATETIME DEFAULT NULL, trip_date DATE DEFAULT NULL, departure_time_of_day TIME DEFAULT NULL, arrival_time_of_day TIME DEFAULT NULL, price NUMERIC(10, 2) NOT NULL, driver_name VARCHAR(100) DEFAULT NULL, status VARCHAR(30) DEFAULT \'planifie\' NOT NULL, seats_reserved INT DEFAULT 0 NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, agency_id INT NOT NULL, bus_id INT NOT NULL, departure_point_id INT NOT NULL, arrival_point_id INT NOT NULL, INDEX IDX_AA7370DACDEADB2A (agency_id), INDEX IDX_AA7370DA2546731D (bus_id), INDEX IDX_AA7370DA7C546AFF (departure_point_id), INDEX IDX_AA7370DACE388D5E (arrival_point_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `users` (id INT AUTO_INCREMENT NOT NULL, full_name VARCHAR(100) NOT NULL, email VARCHAR(100) DEFAULT NULL, phone VARCHAR(20) NOT NULL, ville_residence VARCHAR(100) NOT NULL, quartier VARCHAR(100) NOT NULL, emergency_contact_name VARCHAR(100) DEFAULT NULL, emergency_contact_phone VARCHAR(20) DEFAULT NULL, roles JSON NOT NULL, password_hash VARCHAR(255) NOT NULL, pref_notifications SMALLINT DEFAULT 1 NOT NULL, pref_language VARCHAR(10) DEFAULT \'fr\' NOT NULL, pref_dark_mode SMALLINT DEFAULT 0 NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, password_reset_code VARCHAR(10) DEFAULT NULL, password_reset_expires_at DATETIME DEFAULT NULL, profile_photo_url VARCHAR(500) DEFAULT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_PHONE_NUMBER (phone), UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `withdrawal_requests` (id INT AUTO_INCREMENT NOT NULL, amount NUMERIC(10, 2) NOT NULL, method VARCHAR(50) NOT NULL, status VARCHAR(50) NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, user_id INT NOT NULL, INDEX IDX_3E7DE8AA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE `agency_documents` ADD CONSTRAINT FK_88C3437DCDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `agency_points` ADD CONSTRAINT FK_FB624F69CDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `agents` ADD CONSTRAINT FK_9596AB6EA76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `agents` ADD CONSTRAINT FK_9596AB6ECDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `baggages` ADD CONSTRAINT FK_FB4A59E6B83297E7 FOREIGN KEY (reservation_id) REFERENCES `reservations` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `buses` ADD CONSTRAINT FK_FE00EAF3CDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `payment_logs` ADD CONSTRAINT FK_D10C5128B83297E7 FOREIGN KEY (reservation_id) REFERENCES `reservations` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE refresh_tokens ADD CONSTRAINT FK_9BACE7E1A76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `reservations` ADD CONSTRAINT FK_4DA239A76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `reservations` ADD CONSTRAINT FK_4DA239A5BC2E0E FOREIGN KEY (trip_id) REFERENCES `trips` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE `reviews` ADD CONSTRAINT FK_6970EB0FA76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `reviews` ADD CONSTRAINT FK_6970EB0FCDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `support_responses` ADD CONSTRAINT FK_878D0422700047D2 FOREIGN KEY (ticket_id) REFERENCES `support_tickets` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `support_responses` ADD CONSTRAINT FK_878D04223414710B FOREIGN KEY (agent_id) REFERENCES `agents` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `support_tickets` ADD CONSTRAINT FK_E9739508A76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `tickets` ADD CONSTRAINT FK_54469DF4B83297E7 FOREIGN KEY (reservation_id) REFERENCES `reservations` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `tickets` ADD CONSTRAINT FK_54469DF42C1CCDB2 FOREIGN KEY (validated_by_agent_id) REFERENCES `agents` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `trips` ADD CONSTRAINT FK_AA7370DACDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `trips` ADD CONSTRAINT FK_AA7370DA2546731D FOREIGN KEY (bus_id) REFERENCES `buses` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE `trips` ADD CONSTRAINT FK_AA7370DA7C546AFF FOREIGN KEY (departure_point_id) REFERENCES `agency_points` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE `trips` ADD CONSTRAINT FK_AA7370DACE388D5E FOREIGN KEY (arrival_point_id) REFERENCES `agency_points` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE `withdrawal_requests` ADD CONSTRAINT FK_3E7DE8AA76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `agency_documents` DROP FOREIGN KEY FK_88C3437DCDEADB2A');
        $this->addSql('ALTER TABLE `agency_points` DROP FOREIGN KEY FK_FB624F69CDEADB2A');
        $this->addSql('ALTER TABLE `agents` DROP FOREIGN KEY FK_9596AB6EA76ED395');
        $this->addSql('ALTER TABLE `agents` DROP FOREIGN KEY FK_9596AB6ECDEADB2A');
        $this->addSql('ALTER TABLE `baggages` DROP FOREIGN KEY FK_FB4A59E6B83297E7');
        $this->addSql('ALTER TABLE `buses` DROP FOREIGN KEY FK_FE00EAF3CDEADB2A');
        $this->addSql('ALTER TABLE `payment_logs` DROP FOREIGN KEY FK_D10C5128B83297E7');
        $this->addSql('ALTER TABLE refresh_tokens DROP FOREIGN KEY FK_9BACE7E1A76ED395');
        $this->addSql('ALTER TABLE `reservations` DROP FOREIGN KEY FK_4DA239A76ED395');
        $this->addSql('ALTER TABLE `reservations` DROP FOREIGN KEY FK_4DA239A5BC2E0E');
        $this->addSql('ALTER TABLE `reviews` DROP FOREIGN KEY FK_6970EB0FA76ED395');
        $this->addSql('ALTER TABLE `reviews` DROP FOREIGN KEY FK_6970EB0FCDEADB2A');
        $this->addSql('ALTER TABLE `support_responses` DROP FOREIGN KEY FK_878D0422700047D2');
        $this->addSql('ALTER TABLE `support_responses` DROP FOREIGN KEY FK_878D04223414710B');
        $this->addSql('ALTER TABLE `support_tickets` DROP FOREIGN KEY FK_E9739508A76ED395');
        $this->addSql('ALTER TABLE `tickets` DROP FOREIGN KEY FK_54469DF4B83297E7');
        $this->addSql('ALTER TABLE `tickets` DROP FOREIGN KEY FK_54469DF42C1CCDB2');
        $this->addSql('ALTER TABLE `trips` DROP FOREIGN KEY FK_AA7370DACDEADB2A');
        $this->addSql('ALTER TABLE `trips` DROP FOREIGN KEY FK_AA7370DA2546731D');
        $this->addSql('ALTER TABLE `trips` DROP FOREIGN KEY FK_AA7370DA7C546AFF');
        $this->addSql('ALTER TABLE `trips` DROP FOREIGN KEY FK_AA7370DACE388D5E');
        $this->addSql('ALTER TABLE `withdrawal_requests` DROP FOREIGN KEY FK_3E7DE8AA76ED395');
        $this->addSql('DROP TABLE `agencies`');
        $this->addSql('DROP TABLE `agency_documents`');
        $this->addSql('DROP TABLE `agency_points`');
        $this->addSql('DROP TABLE `agents`');
        $this->addSql('DROP TABLE `baggages`');
        $this->addSql('DROP TABLE `buses`');
        $this->addSql('DROP TABLE `cities`');
        $this->addSql('DROP TABLE `notifications`');
        $this->addSql('DROP TABLE `payment_logs`');
        $this->addSql('DROP TABLE `promo_codes`');
        $this->addSql('DROP TABLE refresh_tokens');
        $this->addSql('DROP TABLE `reservations`');
        $this->addSql('DROP TABLE `reviews`');
        $this->addSql('DROP TABLE `support_responses`');
        $this->addSql('DROP TABLE `support_tickets`');
        $this->addSql('DROP TABLE `tickets`');
        $this->addSql('DROP TABLE `trips`');
        $this->addSql('DROP TABLE `users`');
        $this->addSql('DROP TABLE `withdrawal_requests`');
    }
}
