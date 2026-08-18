<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260817185654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE `admin_activity_logs` (id INT AUTO_INCREMENT NOT NULL, action_type VARCHAR(50) NOT NULL, action VARCHAR(100) NOT NULL, target_entity VARCHAR(255) DEFAULT NULL, target_id VARCHAR(255) DEFAULT NULL, details VARCHAR(255) DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, admin_id INT NOT NULL, INDEX idx_admin_id (admin_id), INDEX idx_action_type (action_type), INDEX idx_created_at (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `admins` (id INT AUTO_INCREMENT NOT NULL, admin_role VARCHAR(50) DEFAULT \'SUPPORT_ADMIN\' NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, permissions JSON DEFAULT NULL, department VARCHAR(100) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, last_login_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_A2E0150FA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `agencies` (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, logo_url VARCHAR(255) DEFAULT NULL, registration_number VARCHAR(100) DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, city VARCHAR(50) DEFAULT NULL, legal_representative VARCHAR(100) DEFAULT NULL, banner_url VARCHAR(500) DEFAULT NULL, website_url VARCHAR(255) DEFAULT NULL, map_url VARCHAR(500) DEFAULT NULL, description LONGTEXT DEFAULT NULL, phone VARCHAR(20) NOT NULL, email VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, rating_cache NUMERIC(3, 2) DEFAULT 0, is_verified TINYINT DEFAULT 0 NOT NULL, commission_rate NUMERIC(5, 2) DEFAULT \'10.00\' NOT NULL, payout_msisdn VARCHAR(20) DEFAULT NULL, pending_payout_msisdn VARCHAR(20) DEFAULT NULL, pending_payout_msisdn_requested_at DATETIME DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, UNIQUE INDEX UNIQ_F65A4DC4E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `agency_documents` (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, file_url VARCHAR(500) NOT NULL, type VARCHAR(50) DEFAULT NULL, status VARCHAR(50) DEFAULT \'pending\' NOT NULL, expiry_date DATETIME DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, agency_id INT NOT NULL, INDEX IDX_88C3437DCDEADB2A (agency_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `agency_points` (id INT AUTO_INCREMENT NOT NULL, city VARCHAR(100) NOT NULL, name VARCHAR(150) NOT NULL, address VARCHAR(255) DEFAULT NULL, quartier VARCHAR(100) DEFAULT NULL, phone VARCHAR(20) DEFAULT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, point_type VARCHAR(40) DEFAULT \'principal\' NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, is_active SMALLINT DEFAULT 1 NOT NULL, has_vip_lounge SMALLINT DEFAULT 0 NOT NULL, has_wifi SMALLINT DEFAULT 0 NOT NULL, has_ac SMALLINT DEFAULT 0 NOT NULL, has_parking SMALLINT DEFAULT 0 NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, agency_id INT NOT NULL, INDEX IDX_FB624F69CDEADB2A (agency_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `agents` (id INT AUTO_INCREMENT NOT NULL, agent_role VARCHAR(50) DEFAULT \'agent_quai\' NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, commission_rate NUMERIC(5, 2) DEFAULT \'0.00\' NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, user_id INT NOT NULL, agency_id INT NOT NULL, UNIQUE INDEX UNIQ_9596AB6EA76ED395 (user_id), INDEX IDX_9596AB6ECDEADB2A (agency_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `application_documents` (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, size VARCHAR(50) NOT NULL, uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, url VARCHAR(500) NOT NULL, mime_type VARCHAR(100) DEFAULT NULL, original_filename VARCHAR(255) DEFAULT NULL, file_path VARCHAR(500) DEFAULT NULL, application_id INT NOT NULL, INDEX IDX_26B108893E030ACD (application_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `audit_logs` (id INT AUTO_INCREMENT NOT NULL, actor_type VARCHAR(32) NOT NULL, actor_id INT DEFAULT NULL, action VARCHAR(80) NOT NULL, target_type VARCHAR(120) DEFAULT NULL, target_id VARCHAR(80) DEFAULT NULL, before_state JSON DEFAULT NULL, after_state JSON DEFAULT NULL, metadata JSON DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_audit_created_at (created_at), INDEX idx_audit_action (action), INDEX idx_audit_target (target_type, target_id), INDEX idx_audit_actor (actor_type, actor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `baggages` (id INT AUTO_INCREMENT NOT NULL, weight DOUBLE PRECISION NOT NULL, description VARCHAR(255) NOT NULL, baggage_type VARCHAR(50) NOT NULL, reservation_id INT NOT NULL, INDEX IDX_FB4A59E6B83297E7 (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `buses` (id INT AUTO_INCREMENT NOT NULL, registration_number VARCHAR(30) NOT NULL, capacity INT NOT NULL, category VARCHAR(30) DEFAULT \'Classique\' NOT NULL, status VARCHAR(30) DEFAULT \'disponible\' NOT NULL, brand VARCHAR(100) DEFAULT NULL, model VARCHAR(100) DEFAULT NULL, color VARCHAR(50) DEFAULT NULL, acquisition_date DATE DEFAULT NULL, mileage INT DEFAULT NULL, last_maintenance_date DATE DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, agency_id INT NOT NULL, UNIQUE INDEX UNIQ_FE00EAF338CEDFBE (registration_number), INDEX IDX_FE00EAF3CDEADB2A (agency_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `cities` (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, code VARCHAR(10) DEFAULT NULL, country VARCHAR(100) DEFAULT NULL, is_active SMALLINT DEFAULT 1 NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, UNIQUE INDEX UNIQ_D95DB16B5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `device_tokens` (id INT AUTO_INCREMENT NOT NULL, token VARCHAR(255) NOT NULL, platform VARCHAR(20) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at DATETIME DEFAULT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_794A60955F37A13B (token), INDEX IDX_794A6095A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `faqs` (id INT AUTO_INCREMENT NOT NULL, question VARCHAR(255) NOT NULL, answer LONGTEXT NOT NULL, category VARCHAR(100) DEFAULT \'general\' NOT NULL, order_priority INT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at VARCHAR(255) NOT NULL, updated_at VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification_user_states (id INT AUTO_INCREMENT NOT NULL, is_read TINYINT DEFAULT 0 NOT NULL, deleted_at DATETIME DEFAULT NULL, read_at DATETIME DEFAULT NULL, notification_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_810B5F31EF1A9D84 (notification_id), INDEX IDX_810B5F31A76ED395 (user_id), UNIQUE INDEX uniq_notification_user_state (notification_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `notifications` (id INT AUTO_INCREMENT NOT NULL, recipient_type VARCHAR(50) NOT NULL, recipient_id INT DEFAULT NULL, title VARCHAR(150) NOT NULL, content LONGTEXT NOT NULL, category VARCHAR(50) DEFAULT \'INFO\' NOT NULL, payload JSON DEFAULT NULL, is_read SMALLINT DEFAULT 0 NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE otp_challenges (id INT AUTO_INCREMENT NOT NULL, phone_number VARCHAR(20) NOT NULL, code_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, requested_at DATETIME NOT NULL, attempts SMALLINT DEFAULT 0 NOT NULL, consumed_at DATETIME DEFAULT NULL, INDEX IDX_OTP_PHONE_CREATED (phone_number, requested_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `partnership_applications` (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(50) NOT NULL, agency_name VARCHAR(100) NOT NULL, legal_representative VARCHAR(100) NOT NULL, email VARCHAR(255) NOT NULL, phone VARCHAR(20) NOT NULL, city VARCHAR(50) NOT NULL, address LONGTEXT DEFAULT NULL, fleet_size SMALLINT NOT NULL, routes_planned JSON NOT NULL, description LONGTEXT NOT NULL, status VARCHAR(20) DEFAULT \'PENDING\' NOT NULL, submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, reviewed_at DATETIME DEFAULT NULL, reviewer VARCHAR(100) DEFAULT NULL, rejection_reason LONGTEXT DEFAULT NULL, reviewer_notes LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, agency_id INT DEFAULT NULL, admin_user_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_C941750FAEA34913 (reference), UNIQUE INDEX UNIQ_C941750FCDEADB2A (agency_id), UNIQUE INDEX UNIQ_C941750F6352511C (admin_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payment_intents (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(100) NOT NULL, purpose VARCHAR(40) NOT NULL, amount NUMERIC(10, 2) NOT NULL, status VARCHAR(30) NOT NULL, operator VARCHAR(50) NOT NULL, provider_reference VARCHAR(150) DEFAULT NULL, raw_response LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, processed_at DATETIME DEFAULT NULL, user_id INT NOT NULL, reservation_id INT NOT NULL, reschedule_id INT NOT NULL, UNIQUE INDEX UNIQ_68498DE8AEA34913 (reference), UNIQUE INDEX UNIQ_68498DE8773D51A1 (provider_reference), INDEX IDX_68498DE8A76ED395 (user_id), INDEX IDX_68498DE8B83297E7 (reservation_id), INDEX IDX_68498DE8AAA1E76C (reschedule_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `payment_logs` (id INT AUTO_INCREMENT NOT NULL, operator VARCHAR(50) NOT NULL, reference VARCHAR(100) NOT NULL, provider_reference VARCHAR(150) DEFAULT NULL, idempotency_key VARCHAR(100) DEFAULT NULL, amount NUMERIC(10, 2) NOT NULL, status VARCHAR(30) NOT NULL, raw_response LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, processed_at DATETIME DEFAULT NULL, reservation_id INT DEFAULT NULL, user_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_D10C5128AEA34913 (reference), UNIQUE INDEX UNIQ_D10C5128773D51A1 (provider_reference), UNIQUE INDEX UNIQ_D10C51287FD1C147 (idempotency_key), INDEX IDX_D10C5128B83297E7 (reservation_id), INDEX IDX_D10C5128A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payout_transactions (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(100) NOT NULL, purpose VARCHAR(20) NOT NULL, operator VARCHAR(50) NOT NULL, recipient_msisdn VARCHAR(30) NOT NULL, amount NUMERIC(10, 2) NOT NULL, status VARCHAR(30) NOT NULL, provider_reference VARCHAR(150) DEFAULT NULL, failure_reason VARCHAR(255) DEFAULT NULL, attempts INT DEFAULT 0 NOT NULL, raw_response LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, processed_at DATETIME DEFAULT NULL, refund_request_id INT DEFAULT NULL, withdrawal_request_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_6169D0E5AEA34913 (reference), UNIQUE INDEX UNIQ_6169D0E5773D51A1 (provider_reference), INDEX IDX_6169D0E5A184CB09 (refund_request_id), INDEX IDX_6169D0E52E695421 (withdrawal_request_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `promo_codes` (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(50) NOT NULL, discount_type VARCHAR(20) NOT NULL, discount_value NUMERIC(10, 2) NOT NULL, valid_from DATETIME DEFAULT NULL, valid_until DATETIME DEFAULT NULL, max_uses INT DEFAULT NULL, current_uses INT DEFAULT 0 NOT NULL, is_active SMALLINT DEFAULT 1 NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, UNIQUE INDEX UNIQ_C84FDDB77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE refresh_tokens (id INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_9BACE7E1A76ED395 (user_id), UNIQUE INDEX UNIQ_REFRESH_TOKEN_HASH (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `refund_requests` (id INT AUTO_INCREMENT NOT NULL, requested_amount NUMERIC(10, 2) NOT NULL, refunded_amount NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, reason LONGTEXT NOT NULL, status VARCHAR(50) DEFAULT \'pending\' NOT NULL, admin_note LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, processed_at DATETIME DEFAULT NULL, agency_id INT NOT NULL, reservation_id INT NOT NULL, reschedule_id INT DEFAULT NULL, requested_by_user_id INT NOT NULL, processed_by_admin_id INT DEFAULT NULL, INDEX IDX_A6AE452CDEADB2A (agency_id), INDEX IDX_A6AE452B83297E7 (reservation_id), INDEX IDX_A6AE452AAA1E76C (reschedule_id), INDEX IDX_A6AE452A2DD2669 (requested_by_user_id), INDEX IDX_A6AE4529824102 (processed_by_admin_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE registration_tokens (id INT AUTO_INCREMENT NOT NULL, phone_number VARCHAR(20) NOT NULL, token_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_404D164B3BC57DA (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation_reschedules (id INT AUTO_INCREMENT NOT NULL, old_total NUMERIC(10, 2) NOT NULL, new_total NUMERIC(10, 2) NOT NULL, difference NUMERIC(10, 2) NOT NULL, direction VARCHAR(20) NOT NULL, status VARCHAR(30) NOT NULL, requested_seats JSON NOT NULL, quote_expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, reservation_id INT NOT NULL, from_trip_id INT NOT NULL, to_trip_id INT NOT NULL, INDEX IDX_E1598096B83297E7 (reservation_id), INDEX IDX_E159809623E2CDA1 (from_trip_id), INDEX IDX_E15980962B2413FB (to_trip_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `reservations` (id INT AUTO_INCREMENT NOT NULL, total_amount NUMERIC(10, 2) NOT NULL, payment_phone VARCHAR(20) NOT NULL, payment_method VARCHAR(50) NOT NULL, payment_status VARCHAR(30) DEFAULT \'en_attente\' NOT NULL, transaction_reference VARCHAR(100) DEFAULT NULL, boarding_point VARCHAR(255) DEFAULT NULL, deboarding_point VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, payment_expires_at DATETIME DEFAULT NULL, reschedule_count INT DEFAULT 0 NOT NULL, last_rescheduled_at DATETIME DEFAULT NULL, user_id INT NOT NULL, trip_id INT NOT NULL, UNIQUE INDEX UNIQ_4DA239ED84D250 (transaction_reference), INDEX IDX_4DA239A76ED395 (user_id), INDEX IDX_4DA239A5BC2E0E (trip_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `reviews` (id INT AUTO_INCREMENT NOT NULL, rating INT NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, user_id INT NOT NULL, agency_id INT NOT NULL, INDEX IDX_6970EB0FA76ED395 (user_id), INDEX IDX_6970EB0FCDEADB2A (agency_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `support_responses` (id INT AUTO_INCREMENT NOT NULL, message LONGTEXT NOT NULL, created_at DATETIME NOT NULL, ticket_id INT NOT NULL, agent_id INT DEFAULT NULL, author_id INT DEFAULT NULL, INDEX IDX_878D0422700047D2 (ticket_id), INDEX IDX_878D04223414710B (agent_id), INDEX IDX_878D0422F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `support_tickets` (id INT AUTO_INCREMENT NOT NULL, subject VARCHAR(255) NOT NULL, message LONGTEXT NOT NULL, category VARCHAR(50) NOT NULL, status VARCHAR(30) DEFAULT \'open\' NOT NULL, priority VARCHAR(20) DEFAULT \'medium\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, first_response_at DATETIME DEFAULT NULL, sla_due_at DATETIME DEFAULT NULL, closed_reason LONGTEXT DEFAULT NULL, user_id INT NOT NULL, assigned_to_id INT DEFAULT NULL, reservation_id INT DEFAULT NULL, trip_id INT DEFAULT NULL, agency_id INT DEFAULT NULL, INDEX IDX_E9739508A76ED395 (user_id), INDEX IDX_E9739508F4BD7827 (assigned_to_id), INDEX IDX_E9739508B83297E7 (reservation_id), INDEX IDX_E9739508A5BC2E0E (trip_id), INDEX IDX_E9739508CDEADB2A (agency_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE system_settings (id INT AUTO_INCREMENT NOT NULL, setting_key VARCHAR(100) NOT NULL, data JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8CAF11475FA1E697 (setting_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `tickets` (id INT AUTO_INCREMENT NOT NULL, passenger_name VARCHAR(100) NOT NULL, passenger_phone VARCHAR(20) NOT NULL, passenger_cni VARCHAR(50) NOT NULL, seat_number INT NOT NULL, qr_code_token VARCHAR(255) DEFAULT NULL, status VARCHAR(30) DEFAULT \'en_attente\' NOT NULL, settlement_amount NUMERIC(12, 2) DEFAULT NULL, validated_at DATETIME DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, reservation_id INT NOT NULL, validated_by_agent_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_54469DF41BC9050B (qr_code_token), INDEX IDX_54469DF4B83297E7 (reservation_id), INDEX IDX_54469DF42C1CCDB2 (validated_by_agent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `trips` (id INT AUTO_INCREMENT NOT NULL, departure_city VARCHAR(100) DEFAULT NULL, arrival_city VARCHAR(100) DEFAULT NULL, boarding_points JSON NOT NULL, deboarding_points JSON NOT NULL, departure_time DATETIME NOT NULL, estimated_arrival_time DATETIME DEFAULT NULL, trip_date DATE DEFAULT NULL, departure_time_of_day TIME DEFAULT NULL, arrival_time_of_day TIME DEFAULT NULL, price NUMERIC(10, 2) NOT NULL, driver_name VARCHAR(100) DEFAULT NULL, status VARCHAR(30) DEFAULT \'planifie\' NOT NULL, seats_reserved INT DEFAULT 0 NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, agency_id INT NOT NULL, bus_id INT NOT NULL, departure_point_id INT NOT NULL, arrival_point_id INT NOT NULL, INDEX IDX_AA7370DACDEADB2A (agency_id), INDEX IDX_AA7370DA2546731D (bus_id), INDEX IDX_AA7370DA7C546AFF (departure_point_id), INDEX IDX_AA7370DACE388D5E (arrival_point_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `users` (id INT AUTO_INCREMENT NOT NULL, full_name VARCHAR(100) NOT NULL, email VARCHAR(100) DEFAULT NULL, phone VARCHAR(20) NOT NULL, ville_residence VARCHAR(100) DEFAULT NULL, quartier VARCHAR(100) DEFAULT NULL, emergency_contact_name VARCHAR(100) DEFAULT NULL, emergency_contact_phone VARCHAR(20) DEFAULT NULL, roles JSON NOT NULL, password_hash VARCHAR(255) NOT NULL, pref_notifications SMALLINT DEFAULT 1 NOT NULL, pref_language VARCHAR(10) DEFAULT \'fr\' NOT NULL, pref_dark_mode SMALLINT DEFAULT 0 NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, email_verified TINYINT DEFAULT 0 NOT NULL, phone_verified TINYINT DEFAULT 0 NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, password_reset_code VARCHAR(10) DEFAULT NULL, password_reset_expires_at DATETIME DEFAULT NULL, otp_attempts SMALLINT DEFAULT 0 NOT NULL, otp_requested_at DATETIME DEFAULT NULL, last_login_at DATETIME DEFAULT NULL, profile_photo_url VARCHAR(500) DEFAULT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_PHONE_NUMBER (phone), UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `wallet_transactions` (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(10) NOT NULL, source VARCHAR(40) NOT NULL, amount NUMERIC(12, 2) NOT NULL, fee_amount NUMERIC(12, 2) DEFAULT \'0.00\' NOT NULL, balance_after NUMERIC(12, 2) NOT NULL, available_after NUMERIC(12, 2) DEFAULT NULL, blocked_after NUMERIC(12, 2) DEFAULT NULL, reserved_after NUMERIC(12, 2) DEFAULT NULL, description LONGTEXT DEFAULT NULL, admin_reason LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, wallet_id INT NOT NULL, reservation_id INT DEFAULT NULL, withdrawal_request_id INT DEFAULT NULL, admin_id INT DEFAULT NULL, INDEX IDX_A50205E2712520F3 (wallet_id), INDEX IDX_A50205E2B83297E7 (reservation_id), INDEX IDX_A50205E22E695421 (withdrawal_request_id), INDEX IDX_A50205E2642B8210 (admin_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `wallets` (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) DEFAULT \'agency\' NOT NULL, available_balance NUMERIC(12, 2) DEFAULT \'0.00\' NOT NULL, blocked_balance NUMERIC(12, 2) DEFAULT \'0.00\' NOT NULL, reserved_balance NUMERIC(12, 2) DEFAULT \'0.00\' NOT NULL, total_earned NUMERIC(12, 2) DEFAULT \'0.00\' NOT NULL, total_withdrawn NUMERIC(12, 2) DEFAULT \'0.00\' NOT NULL, is_frozen TINYINT DEFAULT 0 NOT NULL, frozen_at DATETIME DEFAULT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, agency_id INT DEFAULT NULL, frozen_by_admin_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_967AAA6CCDEADB2A (agency_id), INDEX IDX_967AAA6CF45E0889 (frozen_by_admin_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `withdrawal_requests` (id INT AUTO_INCREMENT NOT NULL, amount NUMERIC(10, 2) NOT NULL, method VARCHAR(50) NOT NULL, idempotency_key VARCHAR(100) DEFAULT NULL, status VARCHAR(50) NOT NULL, notes LONGTEXT DEFAULT NULL, admin_note LONGTEXT DEFAULT NULL, force_paid TINYINT DEFAULT 0 NOT NULL, processed_at DATETIME DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, agency_id INT NOT NULL, requested_by_user_id INT DEFAULT NULL, processed_by_admin_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_3E7DE8A7FD1C147 (idempotency_key), INDEX IDX_3E7DE8ACDEADB2A (agency_id), INDEX IDX_3E7DE8AA2DD2669 (requested_by_user_id), INDEX IDX_3E7DE8A9824102 (processed_by_admin_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE `admin_activity_logs` ADD CONSTRAINT FK_2D89C2A9642B8210 FOREIGN KEY (admin_id) REFERENCES `admins` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `admins` ADD CONSTRAINT FK_A2E0150FA76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `agency_documents` ADD CONSTRAINT FK_88C3437DCDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `agency_points` ADD CONSTRAINT FK_FB624F69CDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `agents` ADD CONSTRAINT FK_9596AB6EA76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `agents` ADD CONSTRAINT FK_9596AB6ECDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `application_documents` ADD CONSTRAINT FK_26B108893E030ACD FOREIGN KEY (application_id) REFERENCES `partnership_applications` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `baggages` ADD CONSTRAINT FK_FB4A59E6B83297E7 FOREIGN KEY (reservation_id) REFERENCES `reservations` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `buses` ADD CONSTRAINT FK_FE00EAF3CDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `device_tokens` ADD CONSTRAINT FK_794A6095A76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification_user_states ADD CONSTRAINT FK_810B5F31EF1A9D84 FOREIGN KEY (notification_id) REFERENCES `notifications` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification_user_states ADD CONSTRAINT FK_810B5F31A76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `partnership_applications` ADD CONSTRAINT FK_C941750FCDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id)');
        $this->addSql('ALTER TABLE `partnership_applications` ADD CONSTRAINT FK_C941750F6352511C FOREIGN KEY (admin_user_id) REFERENCES `users` (id)');
        $this->addSql('ALTER TABLE payment_intents ADD CONSTRAINT FK_68498DE8A76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payment_intents ADD CONSTRAINT FK_68498DE8B83297E7 FOREIGN KEY (reservation_id) REFERENCES `reservations` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payment_intents ADD CONSTRAINT FK_68498DE8AAA1E76C FOREIGN KEY (reschedule_id) REFERENCES reservation_reschedules (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `payment_logs` ADD CONSTRAINT FK_D10C5128B83297E7 FOREIGN KEY (reservation_id) REFERENCES `reservations` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `payment_logs` ADD CONSTRAINT FK_D10C5128A76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payout_transactions ADD CONSTRAINT FK_6169D0E5A184CB09 FOREIGN KEY (refund_request_id) REFERENCES `refund_requests` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE payout_transactions ADD CONSTRAINT FK_6169D0E52E695421 FOREIGN KEY (withdrawal_request_id) REFERENCES `withdrawal_requests` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE refresh_tokens ADD CONSTRAINT FK_9BACE7E1A76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `refund_requests` ADD CONSTRAINT FK_A6AE452CDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `refund_requests` ADD CONSTRAINT FK_A6AE452B83297E7 FOREIGN KEY (reservation_id) REFERENCES `reservations` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `refund_requests` ADD CONSTRAINT FK_A6AE452AAA1E76C FOREIGN KEY (reschedule_id) REFERENCES reservation_reschedules (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `refund_requests` ADD CONSTRAINT FK_A6AE452A2DD2669 FOREIGN KEY (requested_by_user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `refund_requests` ADD CONSTRAINT FK_A6AE4529824102 FOREIGN KEY (processed_by_admin_id) REFERENCES `users` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reservation_reschedules ADD CONSTRAINT FK_E1598096B83297E7 FOREIGN KEY (reservation_id) REFERENCES `reservations` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservation_reschedules ADD CONSTRAINT FK_E159809623E2CDA1 FOREIGN KEY (from_trip_id) REFERENCES `trips` (id)');
        $this->addSql('ALTER TABLE reservation_reschedules ADD CONSTRAINT FK_E15980962B2413FB FOREIGN KEY (to_trip_id) REFERENCES `trips` (id)');
        $this->addSql('ALTER TABLE `reservations` ADD CONSTRAINT FK_4DA239A76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `reservations` ADD CONSTRAINT FK_4DA239A5BC2E0E FOREIGN KEY (trip_id) REFERENCES `trips` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE `reviews` ADD CONSTRAINT FK_6970EB0FA76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `reviews` ADD CONSTRAINT FK_6970EB0FCDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `support_responses` ADD CONSTRAINT FK_878D0422700047D2 FOREIGN KEY (ticket_id) REFERENCES `support_tickets` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `support_responses` ADD CONSTRAINT FK_878D04223414710B FOREIGN KEY (agent_id) REFERENCES `agents` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `support_responses` ADD CONSTRAINT FK_878D0422F675F31B FOREIGN KEY (author_id) REFERENCES `users` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `support_tickets` ADD CONSTRAINT FK_E9739508A76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `support_tickets` ADD CONSTRAINT FK_E9739508F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES `users` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `support_tickets` ADD CONSTRAINT FK_E9739508B83297E7 FOREIGN KEY (reservation_id) REFERENCES `reservations` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `support_tickets` ADD CONSTRAINT FK_E9739508A5BC2E0E FOREIGN KEY (trip_id) REFERENCES `trips` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `support_tickets` ADD CONSTRAINT FK_E9739508CDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `tickets` ADD CONSTRAINT FK_54469DF4B83297E7 FOREIGN KEY (reservation_id) REFERENCES `reservations` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `tickets` ADD CONSTRAINT FK_54469DF42C1CCDB2 FOREIGN KEY (validated_by_agent_id) REFERENCES `agents` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `trips` ADD CONSTRAINT FK_AA7370DACDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `trips` ADD CONSTRAINT FK_AA7370DA2546731D FOREIGN KEY (bus_id) REFERENCES `buses` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE `trips` ADD CONSTRAINT FK_AA7370DA7C546AFF FOREIGN KEY (departure_point_id) REFERENCES `agency_points` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE `trips` ADD CONSTRAINT FK_AA7370DACE388D5E FOREIGN KEY (arrival_point_id) REFERENCES `agency_points` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE `wallet_transactions` ADD CONSTRAINT FK_A50205E2712520F3 FOREIGN KEY (wallet_id) REFERENCES `wallets` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `wallet_transactions` ADD CONSTRAINT FK_A50205E2B83297E7 FOREIGN KEY (reservation_id) REFERENCES `reservations` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `wallet_transactions` ADD CONSTRAINT FK_A50205E22E695421 FOREIGN KEY (withdrawal_request_id) REFERENCES `withdrawal_requests` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `wallet_transactions` ADD CONSTRAINT FK_A50205E2642B8210 FOREIGN KEY (admin_id) REFERENCES `users` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `wallets` ADD CONSTRAINT FK_967AAA6CCDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `wallets` ADD CONSTRAINT FK_967AAA6CF45E0889 FOREIGN KEY (frozen_by_admin_id) REFERENCES `users` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `withdrawal_requests` ADD CONSTRAINT FK_3E7DE8ACDEADB2A FOREIGN KEY (agency_id) REFERENCES `agencies` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `withdrawal_requests` ADD CONSTRAINT FK_3E7DE8AA2DD2669 FOREIGN KEY (requested_by_user_id) REFERENCES `users` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `withdrawal_requests` ADD CONSTRAINT FK_3E7DE8A9824102 FOREIGN KEY (processed_by_admin_id) REFERENCES `users` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `admin_activity_logs` DROP FOREIGN KEY FK_2D89C2A9642B8210');
        $this->addSql('ALTER TABLE `admins` DROP FOREIGN KEY FK_A2E0150FA76ED395');
        $this->addSql('ALTER TABLE `agency_documents` DROP FOREIGN KEY FK_88C3437DCDEADB2A');
        $this->addSql('ALTER TABLE `agency_points` DROP FOREIGN KEY FK_FB624F69CDEADB2A');
        $this->addSql('ALTER TABLE `agents` DROP FOREIGN KEY FK_9596AB6EA76ED395');
        $this->addSql('ALTER TABLE `agents` DROP FOREIGN KEY FK_9596AB6ECDEADB2A');
        $this->addSql('ALTER TABLE `application_documents` DROP FOREIGN KEY FK_26B108893E030ACD');
        $this->addSql('ALTER TABLE `baggages` DROP FOREIGN KEY FK_FB4A59E6B83297E7');
        $this->addSql('ALTER TABLE `buses` DROP FOREIGN KEY FK_FE00EAF3CDEADB2A');
        $this->addSql('ALTER TABLE `device_tokens` DROP FOREIGN KEY FK_794A6095A76ED395');
        $this->addSql('ALTER TABLE notification_user_states DROP FOREIGN KEY FK_810B5F31EF1A9D84');
        $this->addSql('ALTER TABLE notification_user_states DROP FOREIGN KEY FK_810B5F31A76ED395');
        $this->addSql('ALTER TABLE `partnership_applications` DROP FOREIGN KEY FK_C941750FCDEADB2A');
        $this->addSql('ALTER TABLE `partnership_applications` DROP FOREIGN KEY FK_C941750F6352511C');
        $this->addSql('ALTER TABLE payment_intents DROP FOREIGN KEY FK_68498DE8A76ED395');
        $this->addSql('ALTER TABLE payment_intents DROP FOREIGN KEY FK_68498DE8B83297E7');
        $this->addSql('ALTER TABLE payment_intents DROP FOREIGN KEY FK_68498DE8AAA1E76C');
        $this->addSql('ALTER TABLE `payment_logs` DROP FOREIGN KEY FK_D10C5128B83297E7');
        $this->addSql('ALTER TABLE `payment_logs` DROP FOREIGN KEY FK_D10C5128A76ED395');
        $this->addSql('ALTER TABLE payout_transactions DROP FOREIGN KEY FK_6169D0E5A184CB09');
        $this->addSql('ALTER TABLE payout_transactions DROP FOREIGN KEY FK_6169D0E52E695421');
        $this->addSql('ALTER TABLE refresh_tokens DROP FOREIGN KEY FK_9BACE7E1A76ED395');
        $this->addSql('ALTER TABLE `refund_requests` DROP FOREIGN KEY FK_A6AE452CDEADB2A');
        $this->addSql('ALTER TABLE `refund_requests` DROP FOREIGN KEY FK_A6AE452B83297E7');
        $this->addSql('ALTER TABLE `refund_requests` DROP FOREIGN KEY FK_A6AE452AAA1E76C');
        $this->addSql('ALTER TABLE `refund_requests` DROP FOREIGN KEY FK_A6AE452A2DD2669');
        $this->addSql('ALTER TABLE `refund_requests` DROP FOREIGN KEY FK_A6AE4529824102');
        $this->addSql('ALTER TABLE reservation_reschedules DROP FOREIGN KEY FK_E1598096B83297E7');
        $this->addSql('ALTER TABLE reservation_reschedules DROP FOREIGN KEY FK_E159809623E2CDA1');
        $this->addSql('ALTER TABLE reservation_reschedules DROP FOREIGN KEY FK_E15980962B2413FB');
        $this->addSql('ALTER TABLE `reservations` DROP FOREIGN KEY FK_4DA239A76ED395');
        $this->addSql('ALTER TABLE `reservations` DROP FOREIGN KEY FK_4DA239A5BC2E0E');
        $this->addSql('ALTER TABLE `reviews` DROP FOREIGN KEY FK_6970EB0FA76ED395');
        $this->addSql('ALTER TABLE `reviews` DROP FOREIGN KEY FK_6970EB0FCDEADB2A');
        $this->addSql('ALTER TABLE `support_responses` DROP FOREIGN KEY FK_878D0422700047D2');
        $this->addSql('ALTER TABLE `support_responses` DROP FOREIGN KEY FK_878D04223414710B');
        $this->addSql('ALTER TABLE `support_responses` DROP FOREIGN KEY FK_878D0422F675F31B');
        $this->addSql('ALTER TABLE `support_tickets` DROP FOREIGN KEY FK_E9739508A76ED395');
        $this->addSql('ALTER TABLE `support_tickets` DROP FOREIGN KEY FK_E9739508F4BD7827');
        $this->addSql('ALTER TABLE `support_tickets` DROP FOREIGN KEY FK_E9739508B83297E7');
        $this->addSql('ALTER TABLE `support_tickets` DROP FOREIGN KEY FK_E9739508A5BC2E0E');
        $this->addSql('ALTER TABLE `support_tickets` DROP FOREIGN KEY FK_E9739508CDEADB2A');
        $this->addSql('ALTER TABLE `tickets` DROP FOREIGN KEY FK_54469DF4B83297E7');
        $this->addSql('ALTER TABLE `tickets` DROP FOREIGN KEY FK_54469DF42C1CCDB2');
        $this->addSql('ALTER TABLE `trips` DROP FOREIGN KEY FK_AA7370DACDEADB2A');
        $this->addSql('ALTER TABLE `trips` DROP FOREIGN KEY FK_AA7370DA2546731D');
        $this->addSql('ALTER TABLE `trips` DROP FOREIGN KEY FK_AA7370DA7C546AFF');
        $this->addSql('ALTER TABLE `trips` DROP FOREIGN KEY FK_AA7370DACE388D5E');
        $this->addSql('ALTER TABLE `wallet_transactions` DROP FOREIGN KEY FK_A50205E2712520F3');
        $this->addSql('ALTER TABLE `wallet_transactions` DROP FOREIGN KEY FK_A50205E2B83297E7');
        $this->addSql('ALTER TABLE `wallet_transactions` DROP FOREIGN KEY FK_A50205E22E695421');
        $this->addSql('ALTER TABLE `wallet_transactions` DROP FOREIGN KEY FK_A50205E2642B8210');
        $this->addSql('ALTER TABLE `wallets` DROP FOREIGN KEY FK_967AAA6CCDEADB2A');
        $this->addSql('ALTER TABLE `wallets` DROP FOREIGN KEY FK_967AAA6CF45E0889');
        $this->addSql('ALTER TABLE `withdrawal_requests` DROP FOREIGN KEY FK_3E7DE8ACDEADB2A');
        $this->addSql('ALTER TABLE `withdrawal_requests` DROP FOREIGN KEY FK_3E7DE8AA2DD2669');
        $this->addSql('ALTER TABLE `withdrawal_requests` DROP FOREIGN KEY FK_3E7DE8A9824102');
        $this->addSql('DROP TABLE `admin_activity_logs`');
        $this->addSql('DROP TABLE `admins`');
        $this->addSql('DROP TABLE `agencies`');
        $this->addSql('DROP TABLE `agency_documents`');
        $this->addSql('DROP TABLE `agency_points`');
        $this->addSql('DROP TABLE `agents`');
        $this->addSql('DROP TABLE `application_documents`');
        $this->addSql('DROP TABLE `audit_logs`');
        $this->addSql('DROP TABLE `baggages`');
        $this->addSql('DROP TABLE `buses`');
        $this->addSql('DROP TABLE `cities`');
        $this->addSql('DROP TABLE `device_tokens`');
        $this->addSql('DROP TABLE `faqs`');
        $this->addSql('DROP TABLE notification_user_states');
        $this->addSql('DROP TABLE `notifications`');
        $this->addSql('DROP TABLE otp_challenges');
        $this->addSql('DROP TABLE `partnership_applications`');
        $this->addSql('DROP TABLE payment_intents');
        $this->addSql('DROP TABLE `payment_logs`');
        $this->addSql('DROP TABLE payout_transactions');
        $this->addSql('DROP TABLE `promo_codes`');
        $this->addSql('DROP TABLE refresh_tokens');
        $this->addSql('DROP TABLE `refund_requests`');
        $this->addSql('DROP TABLE registration_tokens');
        $this->addSql('DROP TABLE reservation_reschedules');
        $this->addSql('DROP TABLE `reservations`');
        $this->addSql('DROP TABLE `reviews`');
        $this->addSql('DROP TABLE `support_responses`');
        $this->addSql('DROP TABLE `support_tickets`');
        $this->addSql('DROP TABLE system_settings');
        $this->addSql('DROP TABLE `tickets`');
        $this->addSql('DROP TABLE `trips`');
        $this->addSql('DROP TABLE `users`');
        $this->addSql('DROP TABLE `wallet_transactions`');
        $this->addSql('DROP TABLE `wallets`');
        $this->addSql('DROP TABLE `withdrawal_requests`');
    }
}
