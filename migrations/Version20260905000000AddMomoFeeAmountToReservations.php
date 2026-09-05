<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute reservations.momo_fee_amount : le frais momo d'ENCAISSEMENT
 * (commission MTN/Airtel), facturé au client et figé au moment de la
 * réservation, inclus dans total_amount.
 *
 * Nécessaire pour que WalletService::computeReservationNetAmount() puisse
 * déduire ce montant du net crédité à l'agence, sans avoir à recalculer un
 * taux qui peut avoir changé depuis (voir Reservation::getMomoFeeAmount()).
 *
 * NB : aucune migration n'est nécessaire pour les nouvelles clés/constantes
 * ajoutées ailleurs (SystemSetting.data['momoOperators'] est un simple JSON
 * déjà flexible ; WalletTransaction::SOURCE_MOMO_* sont des constantes PHP,
 * pas des colonnes).
 */
final class Version20260905000000AddMomoFeeAmountToReservations extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute reservations.momo_fee_amount (frais momo d'encaissement facturé au client, figé à la réservation)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE reservations
            ADD momo_fee_amount NUMERIC(10, 2) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE reservations
            DROP momo_fee_amount
        SQL);
    }
}
