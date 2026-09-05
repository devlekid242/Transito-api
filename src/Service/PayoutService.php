<?php

namespace App\Service;

use App\Entity\PayoutTransaction;
use App\Entity\RefundRequest;
use App\Entity\WithdrawalRequest;
use App\Service\MobileMoney\MobileMoneyException;
use App\Service\MobileMoney\MobileMoneyGatewayFactory;
use App\Service\MobileMoney\MobileMoneyStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use App\Service\MobileMoney\MobileMoneyTextSanitizer;

/**
 * Déclenche l'envoi réel d'argent (Disbursement MTN/Airtel) pour un
 * remboursement client ou un retrait partenaire.
 *
 * RÈGLE D'OR (cohérente avec WalletService) : le débit du wallet interne
 * (source de vérité comptable) doit TOUJOURS être commité AVANT l'appel
 * réseau à l'opérateur. Si l'appel réseau échoue ou reste PENDING, le
 * ledger reste correct et un PayoutTransaction FAILED/PENDING est visible
 * par l'admin pour rejeu manuel (voir PollMobileMoneyPayoutsCommand) — on
 * ne rembourse/annule jamais automatiquement le débit wallet déjà commité,
 * exactement comme votre `forcePaid` sur WithdrawalRequest le prévoit déjà
 * pour les paiements traités hors-bande.
 */
class PayoutService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MobileMoneyGatewayFactory $gatewayFactory,
        private readonly LoggerInterface $logger,
        private readonly WalletService $walletService,
        private readonly MomoFeeService $momoFeeService,
    ) {}

    /**
     * À appeler APRÈS le commit de la transaction qui a débité le wallet
     * (dans AdminRefundController::processRefund / AdminWithdrawalController::doApprove).
     */
    public function payoutForRefund(RefundRequest $refundRequest, string $operator, string $msisdn, string $amount): PayoutTransaction
    {
        $payout = new PayoutTransaction();
        $payout->setPurpose(PayoutTransaction::PURPOSE_REFUND)
            ->setRefundRequest($refundRequest)
            ->setOperator($operator)
            ->setRecipientMsisdn($msisdn)
            ->setAmount($amount);

        return $this->dispatch($payout);
    }

    public function payoutForWithdrawal(WithdrawalRequest $withdrawal, string $operator, string $msisdn, string $amount): PayoutTransaction
    {
        $payout = new PayoutTransaction();
        $payout->setPurpose(PayoutTransaction::PURPOSE_WITHDRAWAL)
            ->setWithdrawalRequest($withdrawal)
            ->setOperator($operator)
            ->setRecipientMsisdn($msisdn)
            ->setAmount($amount);

        return $this->dispatch($payout);
    }

    /**
     * Envoie réellement la demande de transfert à l'opérateur. Ne lève
     * jamais d'exception vers l'appelant : un échec réseau ou un rejet
     * opérateur se traduit par un statut FAILED/PENDING sur le
     * PayoutTransaction, jamais par un rollback de l'écriture comptable
     * déjà commitée.
     */
    private function dispatch(PayoutTransaction $payout): PayoutTransaction
    {
        $this->em->persist($payout);
        $this->em->flush();

        try {
            $gateway = $this->gatewayFactory->get($payout->getOperator());
            $gateway->transfer(
                referenceId: $payout->getReference(),
                amount: $payout->getAmount(),
                msisdn: $payout->getRecipientMsisdn(),
                externalId: $payout->getReference(),
                payerMessage: MobileMoneyTextSanitizer::sanitize(
                    $payout->getPurpose() === PayoutTransaction::PURPOSE_REFUND ? 'Remboursement Transito' : 'Retrait partenaire Transito'
                ),
                payeeNote: $payout->getPurpose(),
            );
            // 202 Accepted côté opérateur : le statut réel arrivera via
            // webhook (prod) ou via PollMobileMoneyPayoutsCommand (sandbox).
        } catch (MobileMoneyException $e) {
            $this->logger->error('PayoutService: échec initiation transfert', [
                'payoutId' => $payout->getId(),
                'purpose' => $payout->getPurpose(),
                'error' => $e->getMessage(),
            ]);
            $payout->setStatus(PayoutTransaction::STATUS_FAILED);
            $payout->setFailureReason($e->getMessage());
            $payout->setProcessedAt(new \DateTimeImmutable());
            $this->em->flush();
        }

        return $payout;
    }

    /**
     * Interroge l'opérateur pour un PayoutTransaction encore PENDING et
     * applique le résultat. Utilisée par PollMobileMoneyPayoutsCommand.
     */
    public function refreshStatus(PayoutTransaction $payout): void
    {
        if ($payout->getStatus() !== PayoutTransaction::STATUS_PENDING) {
            return;
        }

        $gateway = $this->gatewayFactory->get($payout->getOperator());

        try {
            $result = $gateway->getDisbursementStatus($payout->getReference());
        } catch (MobileMoneyException $e) {
            $payout->incrementAttempts();
            $this->em->flush();
            $this->logger->warning('PayoutService: échec de vérification de statut', [
                'payoutId' => $payout->getId(),
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $payout->incrementAttempts();
        $payout->setRawResponse($result->rawResponse);

        if (!$result->isFinal()) {
            $this->em->flush();
            return;
        }

        $payout->setStatus($result->status);
        $payout->setProviderReference($result->providerReference);
        $payout->setFailureReason($result->reason);
        $payout->setProcessedAt(new \DateTimeImmutable());
        $this->em->flush();

        if ($result->status === MobileMoneyStatus::FAILED) {
            $this->logger->error('PayoutService: transfert refusé par l\'opérateur — nécessite une action admin (rejeu manuel ou compensation).', [
                'payoutId' => $payout->getId(),
                'purpose' => $payout->getPurpose(),
                'reason' => $result->reason,
            ]);
            // Transfert refusé : aucun frais opérateur n'est prélevé sur un
            // décaissement qui n'a pas eu lieu.
            return;
        }

        $this->recordDisbursementFee($payout);
    }

    /**
     * Enregistre le coût réel (en % du montant transféré) que l'opérateur
     * facture à la plateforme pour ce décaissement. Débite le portefeuille
     * plateforme — jamais le montant reçu par le bénéficiaire (règle
     * métier : le client remboursé / l'agence qui retire touche toujours
     * le montant net plein).
     *
     * Appelé uniquement une fois le transfert confirmé par l'opérateur
     * (result final, non FAILED), pour ne jamais facturer un coût sur un
     * décaissement qui a finalement échoué.
     */
    private function recordDisbursementFee(PayoutTransaction $payout): void
    {
        $fee = $this->momoFeeService->disbursementFee($payout->getOperator(), $payout->getAmount());
        if (bccomp($fee, '0.00', 2) <= 0) {
            return;
        }

        $label = $payout->getPurpose() === PayoutTransaction::PURPOSE_REFUND
            ? sprintf('Décaissement remboursement (payout #%d)', $payout->getId())
            : sprintf('Décaissement retrait partenaire (payout #%d)', $payout->getId());

        $this->walletService->recordMomoDisbursementFee($payout->getOperator(), $fee, $label);
        $this->em->flush();
    }
}
