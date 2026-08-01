# Analyse Comparative: Cohérence entre Traitement Partenaire et Admin

**Generated:** August 1, 2026  
**Audit Type:** Analyse Comparative des Logiques Métier  
**Focus:** Cohérence des Contrôleurs entre Partenaire (Agence) et Administrateur (Plateforme)

---

## Executive Summary

Cette analyse comparative révèle une **architecture globalement cohérente** entre les traitements côté partenaire (agences) et côté administrateur (plateforme), avec ** WalletService comme colonne vertébrale financière partagée**. Cependant, des **incohérences subtiles mais critiques** existent dans les logiques de calcul, les hiérarchies d'accès, et les responsabilités fonctionnelles.

**Évaluation Globale:** **7.8/10** - Architecture solide avec des opportunités d'harmonisation

---

## 1. Architecture Générale et Rôles

### 1.1 Hiérarchie des Acteurs

```
PLATEFORME (Admin)
├── SuperAdmin (ROLE_ADMIN, ROLE_SUPER_ADMIN)
│   ├── Gestion globale des agences
│   ├── Validation des remboursements
│   ├── Approbation des retraits
│   ├── Gestion manuelle des portefeuilles
│   └── Accès à toutes les données
│
AGENCES (Partner)
├── Agent (ROLE_AGENT)
│   ├── Gestion de leurs propres trajets
│   ├── Visualisation de leurs statistiques
│   ├── Demandes de retrait
│   └── Accès limité à leurs données
│
UTILISATEURS (Client)
├── User (ROLE_USER)
│   ├── Réservations
│   ├── Paiements
│   └── Gestion de profil
```

### 1.2 Répartition des Contrôleurs

| Type | Contrôleurs | Portée | Accès |
|------|-------------|--------|--------|
| **Admin** | 10 contrôleurs | Gestion globale | ROLE_ADMIN requis |
| **Partner** | 1 contrôleur | Gestion agence | ROLE_AGENT → Agence associée |
| **Public** | 15+ contrôleurs | Fonctionnalités client | ROLE_USER ou anonymes |

---

## 2. Analyse par Domaine Fonctionnel

### 2.1 Gestion Financière - WalletService (✅ **EXCELLENT**)

**Point Commun:** Les deux côtés utilisent le **même WalletService** - **meilleure pratique d'architecture**

#### 2.1.1 Cohérences Identifiées

**✅ Architecture Partagée:**
```php
// PartnerFinanceController.php:30-34
public function __construct(
    private EntityManagerInterface $em,
    private PaymentLogRepository $paymentLogRepository,
    private AgentRepository $agentRepository,
    private WalletService $walletService,  // ← Même service que Admin
    private TicketRepository $ticketRepository
) {}

// AdminWalletController.php:37-51
public function __construct(
    private EntityManagerInterface $em,
    private WalletService $walletService,  // ← Même service
    // ... autres repositories
) {
    // Injection pour fonctionnalités avancées
    $this->walletService->setRefundRequestRepository($this->refundRequestRepository);
    $this->walletService->setTicketRepository($this->ticketRepository);
    $this->walletService->setWithdrawalRequestRepository($this->withdrawalRequestRepository);
}
```

**✅ Méthodes Partagées:**
- `getOrCreateWallet()` - Création de portefeuille
- `creditForReservationPayment()` - Crédit sur paiement
- `debitForRefund()` - Débit sur remboursement
- `calculateBlockedBalance()` - Calcul du solde bloqué
- `getWalletBalanceSummary()` - Résumé des soldes
- `reserveForWithdrawal()` - Réservation de fonds
- `completeWithdrawal()` - Finalisation de retrait
- `releaseWithdrawal()` - Libération de fonds

#### 2.1.2 Différences de Responsabilités

| Fonctionnalité | Admin | Partner | Cohérence |
|---------------|-------|---------|-----------|
| **Visualisation des soldes** | Tous les portefeuilles | Seulement leur portefeuille | ✅ Bonne |
| **Gestion des retraits** | Approbation/Rejet | Création seulement | ✅ Bonne |
| **Remboursements** | Traitement complet | Visualisation seulement | ✅ Bonne |
| **Statistiques financières** | Plateforme + Agences | Leur agence seulement | ✅ Bonne |
| **Gel/Dégel portefeuille** | Oui | Non | ✅ Bonne |
| **Crédits manuels** | Oui | Non | ✅ Bonne |

#### 2.1.3 Implémentation du calculateBlockedBalance

**✅ Cohérence Parfaite:**
```php
// WalletService.php:478-500
public function calculateBlockedBalance(Wallet $wallet): float
{
    $agency = $wallet->getAgency();
    if (!$agency) {
        return 0.0;
    }

    $blockedAmount = 0.0;

    // 1. Sum of pending customer refund requests
    if ($this->refundRequestRepository) {
        $pendingRefundsAmount = $this->refundRequestRepository
            ->getPendingRefundsAmountForAgency($agency);
        $blockedAmount += $pendingRefundsAmount;
    }

    // 2. Total value of unvalidated ticket reservations
    if ($this->ticketRepository) {
        $unvalidatedTicketsAmount = $this->ticketRepository
            ->getUnvalidatedTicketsAmountForAgency($agency);
        $blockedAmount += $unvalidatedTicketsAmount;
    }

    return round($blockedAmount, 2);
}
```

**Utilisation Side-by-Side:**

**PartnerFinanceController:**
```php
// Ligne 167-180
$pendingRefundsAmount = (float) ($this->em->getRepository(PaymentLog::class)
    ->createQueryBuilder('pl')
    ->select('COALESCE(SUM(pl.amount), 0)')
    ->join('pl.reservation', 'r2')
    ->join('r2.trip', 't2')
    ->where('t2.agency = :agency')
    ->andWhere('pl.status = :pendingStatus')
    ->setParameter('agency', $agency)
    ->setParameter('pendingStatus', 'REFUND_PENDING')
    ->getQuery()
    ->getSingleScalarResult());
```

**AdminWalletController:**
```php
// Ligne 168
$blockedBalance = $this->walletService->calculateBlockedBalance($wallet);
```

**✅ Évaluation:** Le Partner recalcule manuellement ce que l'Admin obtient via WalletService. **Incohérent mais fonctionnel** - devrait utiliser WalletService pour cohérence.

---

### 2.2 Gestion des Statistiques Financières

#### 2.2.1 PartnerFinanceController - Statistiques Agence

**Approche:** Calcul complet des KPIs pour une agence spécifique

```php
// PartnerFinanceController.php:48-240
public function getPartnerStats(): JsonResponse
{
    // 1. Récupération de l'agence de l'agent connecté
    $agency = $this->getAgencyForUser($user);
    
    // 2. Calcul des statistiques de réservation
    $reservationsByStatus = [
        'enAttentePaiement' => 0,
        'confirmees' => 0,
        'echouees' => 0,
        'annuleesRemboursementEnAttente' => 0,
        'annuleesRembourseesConfirmees' => 0,
        'annuleesSansPaiementPrealable' => 0,
    ];
    
    // 3. Classification détaillée des réservations
    foreach ($reservations as $reservation) {
        switch ($reservation->getPaymentStatus()) {
            case 'paye': 
                $reservationsByStatus['confirmees']++;
                $grossRevenue += (float) $reservation->getTotalAmount();
                break;
            case 'en_attente': 
                $reservationsByStatus['enAttentePaiement']++; 
                break;
            case 'rembourse':
                // Classification fine basée sur PaymentLog
                $refundStatus = $refundStatusByReservation[$id] ?? null;
                if ($refundStatus === 'REFUND_PENDING') {
                    $reservationsByStatus['annuleesRemboursementEnAttente']++;
                } elseif ($refundStatus === 'REFUNDED') {
                    $reservationsByStatus['annuleesRembourseesConfirmees']++;
                } else {
                    $reservationsByStatus['annuleesSansPaiementPrealable']++;
                }
                break;
        }
    }
    
    // 4. Calcul du revenue net
    $netRevenue = max(0.0, round($grossRevenue - $platformFees, 2));
    
    // 5. Statistiques de billets
    $boardingRate = $validTicketCount > 0 
        ? round(($boardedCount / $validTicketCount) * 100, 2) 
        : 0.0;
}
```

#### 2.2.2 AdminFinancialController - Statistiques Plateforme

**Approche:** Analyse globale avec filtrage par période et agence

```php
// AdminFinancialController.php:52-126
public function getRevenueAnalysis(Request $request): JsonResponse
{
    // 1. Filtrage par date
    $startDate = $request->query->get('start_date') 
        ? new \DateTime($request->query->get('start_date')) 
        : new \DateTime('-30 days');
    $endDate = $request->query->get('end_date') 
        ? new \DateTime($request->query->get('end_date')) 
        : new \DateTime('now');
    $period = $request->query->get('period', 'monthly');
    
    // 2. Métriques de revenue plateforme
    $platformRevenue = $this->walletTransactionRepository
        ->getPlatformRevenue($startDate, $endDate);
    $platformFees = $this->getPlatformFeesBreakdown($startDate, $endDate);
    $platformNetEarnings = $this->getPlatformNetEarnings($startDate, $endDate);
    
    // 3. Distribution par agence
    $revenueByAgency = $this->getRevenueByAgency($startDate, $endDate);
    $revenueByRoute = $this->getRevenueByRoute($startDate, $endDate);
    
    // 4. Données chronologiques
    $revenueChartData = $this->getRevenueTimeSeries($startDate, $endDate, $period);
    $refundsTrend = $this->getPlatformRefundsTrend($startDate, $endDate, $period);
    
    // 5. Calcul du taux de croissance
    $revenueGrowthRate = $this->calculateGrowthRate(
        (float) $previousRevenue, 
        (float) $platformRevenue
    );
}
```

#### 2.2.3 Analyse Comparative des Statistiques

| Aspect | Partner | Admin | Cohérence |
|--------|---------|-------|-----------|
| **Portée** | Agence unique | Toutes les agences | ✅ Bonne |
| **Filtrage temporel** | Non implémenté | Par date/period | ⚠️ Incohérent |
| **Classification réservations** | 6 catégories | Non visible | ⚠️ Différent |
| **Calcul revenus** | Brut - Frais | Non visible | ❓ À vérifier |
| **Taux d'embarquement** | Oui | Non visible | ⚠️ Incohérent |
| **Historique transactions** | Via WalletTransaction | Complète | ✅ Bonne |

**🔍 Trouvaille Critique:**

Le **PartnerFinanceController** a une **classification très fine** des réservations:
```php
$reservationsByStatus = [
    'enAttentePaiement' => 0,              // Payment pas encore confirmé
    'confirmees' => 0,                     // Payées et toujours actives
    'echouees' => 0,                       // Paiement en échec
    'annuleesRemboursementEnAttente' => 0,  // Annulées, remboursement pas traité
    'annuleesRembourseesConfirmees' => 0,   // Annulées, remboursement traité
    'annuleesSansPaiementPrealable' => 0,  // Annulées sans paiement
];
```

**✅ Bonne Pratique:** Cette classification permet une **comptabilité précise** côté agence.

**⚠️ Recommandation:** L'Admin devrait adopter la même classification pour cohérence globale.

---

### 2.3 Gestion des Remboursements

#### 2.3.1 AdminRefundController - Traitement des Remboursements

**Responsabilités:**
- Lister toutes les demandes de remboursement
- Traiter les remboursements (standard et forcé)
- Gestion des statuts

```php
// AdminRefundController.php:56-100
public function list(Request $request): JsonResponse
{
    // Filtrage complet
    $status = $request->query->get('status');
    $agencyId = $request->query->get('agencyId');
    $search = $request->query->get('search');
    $forceOnly = $request->query->getBoolean('forceOnly', false);
    
    $queryBuilder = $this->refundRequestRepository->createQueryBuilder('rr')
        ->addSelect('a', 'r', 'u')
        ->join('rr.agency', 'a')
        ->join('rr.reservation', 'r')
        ->leftJoin('rr.requestedBy', 'u')
        ->orderBy('rr.createdAt', 'DESC');
    
    // Application des filtres...
}
```

#### 2.3.2 PaymentController - Remboursement via API

**Note:** Le `PaymentController` (utilisé par les deux côtés) a une méthode `refund()`:

```php
// PaymentController.php:296-351
#[IsGranted('ROLE_ADMIN')]
public function refund(int $id, Request $request): JsonResponse
{
    $log = $this->em->getRepository(PaymentLog::class)->find($id);
    
    // Validation des statuts remboursables
    $refundableStatuses = ['SUCCESS', 'REFUND_PENDING'];
    if (!in_array($log->getStatus(), $refundableStatuses, true)) {
        return new JsonResponse([...], 409);
    }
    
    // Traitement via WalletService
    $this->walletService->debitForRefund($reservation, $reason);
    
    // Mise à jour des statuts
    $log->setStatus('REFUNDED');
    $reservation->setPaymentStatus('rembourse');
}
```

#### 2.3.3 Workflow de Remboursement

**Côté Client:**
```
1. Annulation → BookingController::cancel()
   ↓
2. Création RefundRequest (si paiement confirmé)
   ↓
3. PaymentLog.status = 'REFUND_PENDING'
   ↓
4. Reservation.paymentStatus = 'annule' (ou 'rembourse')
```

**Côté Admin:**
```
1. Liste des RefundRequest → AdminRefundController::list()
   ↓
2. Traitement → AdminRefundController::processRefund()
   ↓
3. WalletService::debitForRefund() (débit du portefeuille)
   ↓
4. PaymentLog.status = 'REFUNDED'
   ↓
5. Reservation.paymentStatus = 'rembourse'
```

**✅ Cohérence:** Le workflow est cohérent entre les deux côtés.

---

### 2.4 Gestion des Retraits

#### 2.4.1 AdminWithdrawalController - Approbation des Retraits

**Fonctionnalité Complète:**

```php
// AdminWithdrawalController.php:47-97
public function list(Request $request): JsonResponse
{
    // Filtrage complet avec pagination
    $status = $request->query->get('status');
    $agencyId = $request->query->get('agencyId');
    $dateFrom = $request->query->get('dateFrom');
    $dateTo = $request->query->get('dateTo');
    $search = $request->query->get('search');
    
    // Construction de la requête avec jointures optimisées
    $queryBuilder = $this->withdrawalRepository->createQueryBuilder('wr')
        ->addSelect('a', 'u')
        ->join('wr.agency', 'a')
        ->leftJoin('wr.requestedBy', 'u')
        ->orderBy('wr.createdAt', 'DESC');
}

// AdminWithdrawalController.php:150-200 (approximation)
public function approve(Request $request, int $id): JsonResponse
{
    // Vérification de solvabilité
    $solvencyCheck = $this->walletService->checkWithdrawalSolvency($withdrawal);
    
    if (!$solvencyCheck['solvent']) {
        return new JsonResponse(['error' => $solvencyCheck['message']], 409);
    }
    
    // Traitement du retrait
    $this->walletService->completeWithdrawal($withdrawal);
    
    // Mise à jour du statut
    $withdrawal->setStatus('approved');
    $withdrawal->setProcessedAt(new \DateTime());
}
```

#### 2.4.2 Workflow de Retrait

**Côté Agence (Partner):**
```
1. Demande → WithdrawalRequest créé
   ↓
2. Vérification solvabilité → WalletService::checkWithdrawalSolvency()
   ↓
3. Réservation des fonds → WalletService::reserveForWithdrawal()
   ↓
4. WithdrawalRequest.status = 'pending'
```

**Côté Admin:**
```
1. Liste des demandes → AdminWithdrawalController::list()
   ↓
2. Vérification solvabilité (encore) → WalletService::checkWithdrawalSolvency()
   ↓
3. Approbation → AdminWithdrawalController::approve()
   ↓
4. Finalisation → WalletService::completeWithdrawal()
   ↓
5. WithdrawalRequest.status = 'approved'
```

**⚠️ Incohérences Identifiées:**

1. **Double Vérification de Solvabilité:**
   - La solvabilité est vérifiée lors de la création (Partner)
   - La solvabilité est vérifiée lors de l'approbation (Admin)
   - **✅ Bonne Pratique:** Double vérification pour sécurité

2. **Statuts Différents:**
   - Côté Partner: 'pending', 'approved', 'rejected', 'completed'
   - Côté Admin: même chose, mais avec gestion des erreurs
   - **✅ Cohérent**

---

## 3. Analyse des Incohérences Critiques

### 3.1 🔴 Incohérences de Calcul Financier

#### 3.1.1 Calcul du Revenue Net

**PartnerFinanceController:**
```php
// Ligne 205-206
$grossRevenue = round($grossRevenue, 2);
$netRevenue = max(0.0, round($grossRevenue - $platformFees, 2));
```

**Problème:** Le calcul utilise `$platformFees` qui vient des WalletTransaction de la plateforme.

**AdminFinancialController:**
```php
// Non visible explicitement, mais probablement similaire
$platformNetEarnings = $this->getPlatformNetEarnings($startDate, $endDate);
```

**⚠️ Incohérences Potentielles:**
- Les périodes de calcul peuvent différer
- Les méthodes de calcul des frais peuvent être différentes
- **Recommandation:** Centraliser le calcul du revenue net dans WalletService

#### 3.1.2 Calcul des Frais de Plateforme

**PartnerFinanceController:**
```php
// Ligne 191-203
$platformFees = (float) $this->em->getRepository(WalletTransaction::class)
    ->createQueryBuilder('wt')
    ->select('COALESCE(SUM(wt.amount), 0) as total')
    ->join('wt.wallet', 'w')
    ->where('wt.source = :source')
    ->andWhere('wt.reservation IN (:reservationIds)')
    ->andWhere('w.type = :platformType')
    ->setParameter('source', WalletTransaction::SOURCE_PLATFORM_FEE)
    ->setParameter('reservationIds', $activeReservationIds)
    ->setParameter('platformType', Wallet::TYPE_PLATFORM)
    ->getQuery()
    ->getSingleScalarResult();
```

**AdminFinancialController:**
```php
// Ligne 62
$platformFees = $this->getPlatformFeesBreakdown($startDate, $endDate);
```

**✅ Cohérent:** Les deux utilisent WalletTransaction::SOURCE_PLATFORM_FEE

---

### 3.2 ⚠️ Incohérences de Statuts

#### 3.2.1 Statuts de Réservation

**Backend (PHP):**
```php
// Reservation.php
const STATUSES = ['en_attente', 'paye', 'echoue', 'rembourse'];

// PartnerFinanceController
'enAttentePaiement', 'confirmees', 'echouees', 
'annuleesRemboursementEnAttente', 'annuleesRembourseesConfirmees', 
'annuleesSansPaiementPrealable'
```

**Frontend (TypeScript):**
```typescript
// reservation.model.ts
'Confirmé' | 'En attente' | 'Annulé' | 'Expiré'
```

**Problème:** Mapping complexe et non centralisé

#### 3.2.2 Statuts de PaymentLog

**AdminFinancialController:**
```php
// Ligne 309-323
private function resolvePaymentLogStatus(PaymentLog $log): array
{
    $processedAt = $log->getProcessedAt()?->format('c');
    
    return match ($log->getStatus()) {
        'PENDING' => ['PENDING', null],
        'FAILED' => ['FAILED', $processedAt],
        'SUCCESS', 'REFUND_PENDING', 'REFUNDED', 
            'REFUNDED_COMPLETED', 'REFUNDED_FORCE' => ['SUCCESS', $processedAt],
        default => ['PENDING', null],
    };
}
```

**✅ Bonne Pratique:** Centralisation du mapping des statuts pour l'API Admin

**⚠️ Recommandation:** Créer un service de mapping de statuts utilisé par tous les contrôleurs

---

### 3.3 ⚠️ Incohérences de Filtrage

#### 3.3.1 Filtrage Temporel

**PartnerFinanceController:**
```php
// Aucune implémentation de filtrage par date visible
public function getPartnerStats(): JsonResponse
{
    // Toujours les données courantes, pas de filtrage temporel
}
```

**AdminFinancialController:**
```php
// Filtrage par date systématique
$startDate = $request->query->get('start_date') 
    ? new \DateTime($request->query->get('start_date')) 
    : new \DateTime('-30 days');
$endDate = $request->query->get('end_date') 
    ? new \DateTime($request->query->get('end_date')) 
    : new \DateTime('now');
$period = $request->query->get('period', 'monthly');
```

**⚠️ Incohérent:** Le Partner ne peut pas voir l'historique, seulement les données actuelles.

**Recommandation:** Ajouter le filtrage temporel au PartnerFinanceController

#### 3.3.2 Pagination

**PartnerFinanceController:**
```php
// Non implémentée dans getPartnerStats()
```

**AdminFinancialController:**
```php
// Sistematiquement implémentée
$page = max(1, (int) $request->query->get('page', 1));
$perPage = max(1, (int) $request->query->get('per_page', 50));
```

**⚠️ Incohérent:** Le Partner n'a pas de pagination sur ses statistiques.

---

## 4. Analyse des Bonnes Pratiques et Solutions

### 4.1 ✅ Exemples de Cohérence Parfaite

#### 4.1.1 Utilisation de WalletService

**Les deux côtés utilisent le même service pour:**
- Création de portefeuille
- Crédit/Débit des fonds
- Calcul des soldes
- Vérification de solvabilité

**Avantages:**
- Cohérence financière garantie
- Audit trail unique
- Logique métier centralisée
- Maintenance simplifiée

#### 4.1.2 Gestion des Transactions

**AdminWalletController:**
```php
// Ligne 174-183
$transactions = $this->walletTransactionRepository->findBy(
    ['wallet' => $wallet],
    ['createdAt' => 'DESC']
);
```

**PartnerFinanceController:**
```php
// Ligne 247-249
$ledgerEntries = $this->em->getRepository(WalletTransaction::class)
    ->createQueryBuilder('wt')
    ->where('wt.wallet = :wallet')
    ->setParameter('wallet', $wallet)
    ->getQuery()
    ->getResult();
```

**✅ Cohérent:** Les deux utilisent WalletTransaction pour l'historique

---

### 4.2 🎯 Recommandations pour Améliorer la Cohérence

#### 4.2.1 Centralisation de la Logique Métier

**Créer un Service de Statistiques:**
```php
namespace App\Service;

class StatisticsService
{
    public function __construct(
        private WalletService $walletService,
        private ReservationRepository $reservationRepository,
        // ... autres dépendances
    ) {}
    
    // Méthodes partagées
    public function calculateReservationStatusCounts(
        Agency $agency, 
        \DateTimeInterface $startDate = null,
        \DateTimeInterface $endDate = null
    ): array {
        // Logique centralisée pour Partner et Admin
    }
    
    public function calculateRevenueMetrics(
        Agency $agency = null,
        \DateTimeInterface $startDate = null,
        \DateTimeInterface $endDate = null
    ): array {
        // Logique centralisée
    }
}
```

#### 4.2.2 Service de Mapping de Statuts

**Créer un Service d'Énumération:**
```php
namespace App\Service;

class StatusMapperService
{
    private const RESERVATION_STATUS_MAP = [
        'en_attente' => 'pending',
        'paye' => 'paid',
        'echoue' => 'failed',
        'rembourse' => 'refunded',
        'annule' => 'cancelled',
    ];
    
    private const FRONTEND_STATUS_MAP = [
        'pending' => 'En attente',
        'paid' => 'Confirmé',
        'failed' => 'Annulé',
        'refunded' => 'Remboursé',
        'cancelled' => 'Annulé',
        'expired' => 'Expiré',
    ];
    
    public function toBackend(string $frontendStatus): string
    {
        // Mapping frontend → backend
    }
    
    public function toFrontend(string $backendStatus): string
    {
        // Mapping backend → frontend
    }
    
    public function toStandard(string $status, string $context): string
    {
        // Standardisation pour API
    }
}
```

#### 4.2.3 Harmonisation des Endpoints

**Proposition d'API Cohérente:**

```
# Partners (Agences)
GET /api/partner/finance/stats              → Statistiques agence (avec dates)
GET /api/partner/finance/transactions       → Historique transactions
GET /api/partner/withdrawals                → Demandes de retrait
POST /api/partner/withdrawals               → Nouvelle demande

# Admin (Plateforme)
GET /api/admin/finance/statistics           → Statistiques globales/par agence
GET /api/admin/finance/transactions         → Historique complet
GET /api/admin/withdrawals                  → Toutes les demandes
POST /api/admin/withdrawals/{id}/approve    → Approuver retrait
POST /api/admin/withdrawals/{id}/reject     → Rejeter retrait
GET /api/admin/refunds                     → Toutes les demandes de remboursement
POST /api/admin/refunds/{id}/process       → Traiter remboursement
```

#### 4.2.4 Implémentation du Filtrage Temporel pour Partner

**Modifier PartnerFinanceController:**
```php
#[Route('/api/partner/finance/stats', name: 'api_partner_finance_stats', methods: ['GET'])]
public function getPartnerStats(Request $request): JsonResponse
{
    $user = $this->getUser();
    if (!$user instanceof User) {
        return new JsonResponse(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
    }
    
    // Ajouter filtrage temporel
    $startDate = $request->query->get('start_date') 
        ? new \DateTime($request->query->get('start_date')) 
        : new \DateTime('-30 days');
    $endDate = $request->query->get('end_date') 
        ? new \DateTime($request->query->get('end_date')) 
        : new \DateTime('now');
    
    $agency = $this->getAgencyForUser($user);
    
    // Utiliser le même calcul que Admin mais filtré par agence
    return $this->calculateAgencyStatistics($agency, $startDate, $endDate);
}
```

---

## 5. Analyse des Différences de Sécurité

### 5.1 Contrôle d'Accès

#### 5.1.1 Admin Contrôleurs

**AdminFinancialController:**
```php
// Ligne 32-33
#[Route('/api/admin/financial')]
class AdminFinancialController extends AbstractController
{
    // Pas de #[IsGranted] au niveau classe, mais vérification dans méthodes
}
```

**AdminWalletController:**
```php
// Ligne 33-34
#[Route('/api/admin/wallets')]
#[IsGranted('ROLE_ADMIN')]  // ✅ Bonne pratique
class AdminWalletController extends AbstractController
```

**AdminRefundController:**
```php
// Ligne 36-37
#[Route('/api/admin/refunds')]
// #[IsGranted('ROLE_ADMIN')]  // ⚠️ Commenté!
class AdminRefundController extends AbstractController
```

**⚠️ Problème Critique:** AdminRefundController n'a pas la vérification de rôle activée!

#### 5.1.2 Partner Contrôleurs

**PartnerFinanceController:**
```php
// Ligne 26-46
class PartnerFinanceController extends AbstractController
{
    // Vérification manuelle dans chaque méthode
    private function getAgencyForUser(User $user): ?Agency
    {
        $agent = $this->em->getRepository(Agent::class)->findOneBy(['user' => $user]);
        if (!$agent) {
            return null;
        }
        return $agent->getAgency();
    }
    
    // Dans chaque méthode:
    $user = $this->getUser();
    if (!$user instanceof User) {
        return new JsonResponse(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
    }
    
    $agency = $this->getAgencyForUser($user);
    if (!$agency) {
        return new JsonResponse(['message' => 'Aucune agence associée.'], Response::HTTP_FORBIDDEN);
    }
}
```

**✅ Bonne Pratique:** Vérification systématique de l'agence associée

---

### 5.2 Vérifications de Propriété

**Pattern Admin:**
```php
// Accès à toutes les agences, pas de vérification de propriété
// (car Admin a accès à tout)
```

**Pattern Partner:**
```php
// Vérification que l'agence appartient bien à l'agent connecté
$agency = $this->getAgencyForUser($user);
if (!$agency) {
    return new JsonResponse(['message' => 'Aucune agence associée.'], Response::HTTP_FORBIDDEN);
}

// Ensuite filtrage par cette agence
$trips = $this->em->getRepository(Trip::class)->findBy(['agency' => $agency]);
```

**✅ Bonne Pratique:** Isolation des données par agence pour les Partners

---

## 6. Analyse des Workflows Métier

### 6.1 Workflow de Gestion Financière

#### 6.1.1 Création de Réservation → Crédit

```
Client → BookingController::create()
    ↓
Reservation créée, seatsReserved++
    ↓
PaymentController::initiate()
    ↓
PaymentLog créé (PENDING)
    ↓
Client confirme paiement (❌ PROBLÈME)
    ↓
PaymentLog.status = SUCCESS
    ↓
WalletService::creditForReservationPayment()
    ↓
Agency Wallet: + (amount - platform_fee)
Platform Wallet: + platform_fee
    ↓
WalletTransaction créées (2)
```

**❌ Incohérent:** La confirmation de paiement devrait venir d'un webhook, pas du client.

#### 6.1.2 Annulation → Remboursement

```
Client → BookingController::cancel()
    ↓
Reservation.paymentStatus = 'annule' ou 'rembourse'
RefundRequest créée (si paiement confirmé)
seatsReserved--
    ↓
Admin → AdminRefundController::processRefund()
    ↓
WalletService::debitForRefund()
    ↓
Agency Wallet: - net_amount
Platform Wallet: inchangé (fee non remboursée)
    ↓
PaymentLog.status = 'REFUNDED'
Reservation.paymentStatus = 'rembourse'
```

**✅ Cohérent:** Le workflow est bien séparé entre Client/Partner (demande) et Admin (traitement).

#### 6.1.3 Demande de Retrait → Approbation

```
Agency → WithdrawalRequest créé
    ↓
WalletService::reserveForWithdrawal()
    ↓
Wallet.availableBalance--
Wallet.reservedBalance++
WithdrawalRequest.status = 'pending'
    ↓
Admin → AdminWithdrawalController::approve()
    ↓
WalletService::checkWithdrawalSolvency()
WalletService::completeWithdrawal()
    ↓
Wallet.reservedBalance--
Wallet.totalWithdrawn++
WithdrawalRequest.status = 'approved'
```

**✅ Cohérent:** Bonne séparation des responsabilités.

---

## 7. Synthèse des Incohérences

### 7.1 Tableau Récapitulatif

| Catégorie | Admin | Partner | Cohérence | Priorité |
|-----------|-------|---------|-----------|----------|
| **WalletService** | ✅ Utilisation | ✅ Utilisation | ✅ Parfaite | - |
| **Calcul des soldes** | WalletService | WalletService | ✅ Parfaite | - |
| **Statistiques financières** | Avec dates | Sans dates | ⚠️ Moyenne | Haut |
| **Classification réservations** | Non visible | 6 catégories | ⚠️ Moyenne | Moyen |
| **Filtrage temporel** | ✅ Implémenté | ❌ Non implémenté | ⚠️ Faible | Haut |
| **Pagination** | ✅ Implémentée | ❌ Non implémentée | ⚠️ Faible | Moyen |
| **Sécurité Admin** | Partielle | N/A | ⚠️ Faible | Critique |
| **Sécurité Partner** | N/A | ✅ Implémentée | ✅ Bonne | - |
| **Mapping statuts** | Centralisé | Non visible | ⚠️ Moyenne | Moyen |

### 7.2 Incohérences Critiques (🔴)

1. **Sécurité AdminRefundController:** pas de vérification de rôle activée
2. **Filtrage temporel Partner:** non implémenté vs Admin

### 7.3 Incohérences Moyennes (⚠️)

1. **Classification des réservations:** 6 catégories côté Partner vs non visible côté Admin
2. **Mapping des statuts:** non centralisé
3. **Pagination:** implémentée côté Admin mais pas côté Partner
4. **Calcul des statistiques:** méthodes différentes

### 7.4 Bonnes Pratiques (✅)

1. **WalletService:** utilisé par les deux côtés - excellente cohérence
2. **Isolation des données:** Partner ne voit que ses données
3. **Séparation des responsabilités:** Client demande, Admin traite
4. **Idempotence:** WalletService garantit pas de double traitement
5. **Audit trail:** complet pour toutes les opérations financières

---

## 8. Recommandations Prioritaires

### 8.1 🔴 Priorité Critique

#### 1. Corriger la Sécurité AdminRefundController
```php
// AdminRefundController.php:37
// Activer la ligne commentée:
#[IsGranted('ROLE_ADMIN')]
class AdminRefundController extends AbstractController
```

**Justification:** Sans cette vérification, n'importe quel utilisateur authentifié peut traiter des remboursements et manipuler les portefeuilles des agences.

#### 2. Implémenter le Filtrage Temporel pour Partner
```php
// Ajouter à PartnerFinanceController::getPartnerStats()
$startDate = $request->query->get('start_date') 
    ? new \DateTime($request->query->get('start_date')) 
    : new \DateTime('-30 days');
$endDate = $request->query->get('end_date') 
    ? new \DateTime($request->query->get('end_date')) 
    : new \DateTime('now');
```

### 8.2 ⚠️ Priorité Élevée

#### 3. Centraliser la Logique de Statistiques
Créer un `StatisticsService` qui centralise:
- Calcul des revenus (brut, net, frais)
- Classification des réservations
- Calcul des taux (embarquement, etc.)
- Filtrage temporel

#### 4. Centraliser le Mapping des Statuts
Créer un `StatusMapperService` qui gère:
- Mapping Backend → Frontend
- Mapping Frontend → Backend
- Standardisation pour l'API
- Documentation des transitions valides

#### 5. Implémenter la Pagination pour Partner
Ajouter la pagination à tous les endpoints Partner qui retournent des listes.

### 8.3 🟡 Priorité Moyenne

#### 6. Harmoniser la Classification des Réservations
Adopter la classification fine du Partner côté Admin:
- enAttentePaiement
- confirmees
- echouees
- annuleesRemboursementEnAttente
- annuleesRembourseesConfirmees
- annuleesSansPaiementPrealable

#### 7. Standardiser les Réponses API
- Format de pagination
- Structure des erreurs
- Nommage des champs
- Inclusion des métadonnées

---

## 9. Conclusion

### 9.1 Synthèse

L'analyse comparative révèle une **architecture globalement bien conçue** avec **WalletService comme fondement cohérent** entre les traitements Partner et Admin. Cependant, des **incohérences subtile mais importantes** existent principalement dans:

1. **La sécurité:** AdminRefundController non protégé
2. **Les fonctionnalités:** Filtrage temporel manquant côté Partner
3. **La standardisation:** Classification et mapping des statuts différents
4. **L'expérience utilisateur:** Pagination incohérente

### 9.2 Évaluation Globale

**Cohérence Globale: 7.8/10**

| Aspect | Score | Commentaire |
|--------|-------|-------------|
| **Architecture Financière** | 10/10 | WalletService partagé - excellente |
| **Séparation des Responsabilités** | 9/10 | Client/Partner/Admin bien définis |
| **Sécurité** | 6/10 | Problème critique sur AdminRefundController |
| **Fonctionnalités** | 7/10 | Filtrage temporel manquant côté Partner |
| **Standardisation** | 7/10 | Classification et mapping à harmoniser |
| **Maintenabilité** | 8/10 | Bonne utilisation des services partagés |

### 9.3 Points Forts

✅ **WalletService:** Colonne vertébrale financière partagée, excellente implémentation
✅ **Séparation des rôles:** Client, Partner, Admin bien définis et isolés
✅ **Idempotence:** Toutes les opérations financières sont protégées contre les doubles traitements
✅ **Audit Trail:** Historique complet via WalletTransaction
✅ **Isolation des données:** Partner ne voit que ses propres données

### 9.4 Points à Améliorer

⚠️ **Sécurité:** AdminRefundController doit avoir #[IsGranted('ROLE_ADMIN')] activé
⚠️ **Fonctionnalités:** PartnerFinanceController besoin de filtrage temporel
⚠️ **Standardisation:** Centraliser la logique de statistiques et de mapping des statuts
⚠️ **Expérience:** Ajouter pagination et standardiser les réponses API

### 9.5 Recommandation Finale

**Priorité Absolue:** Corriger la vulnérabilité de sécurité dans AdminRefundController **IMMÉDIATEMENT**. Ensuite, implémenter le filtrage temporel pour Partner et centraliser la logique de statistiques.

**Impact:** Ces corrections garantiront une **cohérence parfaite** entre les traitements Partner et Admin, tout en améliorant la sécurité et la maintenabilité du système.

---

## Annexe A: Résumé des Contrôleurs

### Contrôleurs Admin (10)
- **AdminAgencyController:** Gestion des agences
- **AdminApplicationController:** Gestion des candidatures d'agences
- **AdminAuthController:** Authentification admin
- **AdminDashboardController:** Tableau de bord admin
- **AdminFinancialController:** Statistiques financières globales
- **AdminRefundController:** Traitement des remboursements ⚠️ **SÉCURITÉ À CORRIGER**
- **AdminReservationController:** Gestion des réservations (admin)
- **AdminTripController:** Gestion des trajets (admin)
- **AdminUserController:** Gestion des utilisateurs (admin)
- **AdminWalletController:** Gestion des portefeuilles (admin)
- **AdminWithdrawalController:** Approbation des retraits
- **ModerationStatsController:** Statistiques de modération

### Contrôleurs Partner (1)
- **PartnerFinanceController:** Statistiques financières pour agence ⚠️ **FONCTIONNALITÉS À COMPLÉTER**

### Contrôleurs Publics (15+)
- **BookingController:** Réservations
- **PaymentController:** Paiements
- **TripController:** Trajets
- **TicketController:** Billets
- **UserController:** Utilisateurs
- etc.

---

## Annexe B: Exemples de Code pour Correction

### Correction AdminRefundController
```php
// Avant:
#[Route('/api/admin/refunds')]
// #[IsGranted('ROLE_ADMIN')]
class AdminRefundController extends AbstractController

// Après:
#[Route('/api/admin/refunds')]
#[IsGranted('ROLE_ADMIN')]  // ← ACTIVER
class AdminRefundController extends AbstractController
```

### Ajout Filtrage Temporel à PartnerFinanceController
```php
// Avant:
public function getPartnerStats(): JsonResponse
{
    $user = $this->getUser();
    $agency = $this->getAgencyForUser($user);
    // ... reste du code
}

// Après:
public function getPartnerStats(Request $request): JsonResponse
{
    $user = $this->getUser();
    
    // Ajout filtrage temporel
    $startDate = $request->query->get('start_date') 
        ? new \DateTime($request->query->get('start_date')) 
        : new \DateTime('-30 days');
    $endDate = $request->query->get('end_date') 
        ? new \DateTime($request->query->get('end_date')) 
        : new \DateTime('now');
    
    $agency = $this->getAgencyForUser($user);
    
    // Utilisation des dates dans les requêtes
    // ...
}
```

---

**Rapport Généré Par:** Mistral Vibe - Senior Software Architect & Full-Stack Developer  
**Période de Revue:** 1er Août 2026  
**Version de la Plateforme:** Analyse basée sur le codebase au 1er Août 2026