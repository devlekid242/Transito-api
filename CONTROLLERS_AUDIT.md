# Audit des Contrôleurs PHP - Transito-api

**Date:** 2026-08-18  
**Source:** d:\projet perso\transito\Transito-api\src\Controller\

## STRUCTURE

Exploration complète des contrôleurs et actions instrumentables pour logging/monitoring:

- **Contrôleur**: Nom du fichier/classe
- **Action**: Méthode publique (create, update, delete, login, register, auth, etc.)
- **Entité**: Type d'objet manipulé
- **Route**: Endpoint HTTP

---

## 🔷 CONTRÔLEURS RACINE (src/Controller/)

### 1. AuthController

| Action       | Entité                  | Route                      |
| ------------ | ----------------------- | -------------------------- |
| login        | User                    | POST /api/auth/login       |
| requestOtp   | OtpChallenge, User      | POST /api/auth/request-otp |
| confirmOtp   | OtpChallenge, User      | POST /api/auth/confirm-otp |
| register     | User, RegistrationToken | POST /api/auth/register    |
| logout       | User                    | POST /api/auth/logout      |
| refreshToken | User                    | POST /api/auth/refresh     |

### 2. UserController

| Action             | Entité              | Route                                |
| ------------------ | ------------------- | ------------------------------------ |
| currentUser        | User, Agent, Agency | GET /api/users/me                    |
| update             | User                | PUT /api/users/profile               |
| delete             | User                | DELETE /api/users/{id}               |
| changePassword     | User                | POST /api/users/change-password      |
| verifyPhoneOtp     | User                | POST /api/users/verify-phone-otp     |
| requestPhoneChange | User, OtpChallenge  | POST /api/users/request-phone-change |

### 3. BookingController

| Action            | Entité                             | Route                                   |
| ----------------- | ---------------------------------- | --------------------------------------- |
| create            | Reservation, Ticket, User          | POST /api/bookings                      |
| getBookingDetail  | Reservation, Ticket                | GET /api/bookings/{id}                  |
| cancel            | Reservation, RefundRequest         | POST /api/bookings/{id}/cancel          |
| reschedule        | ReservationReschedule, Reservation | POST /api/bookings/{id}/reschedule      |
| bookingReceipt    | Reservation, Ticket                | GET /api/bookings/{id}/receipt          |
| getAvailableSeats | Trip                               | GET /api/trips/{tripId}/available-seats |
| applyPromo        | Reservation, Promo                 | POST /api/bookings/{id}/apply-promo     |

### 4. TripController

| Action     | Entité            | Route                          |
| ---------- | ----------------- | ------------------------------ |
| index      | Trip, Agency      | GET /api/trips                 |
| uncoming   | Trip              | GET /api/trips/uncoming        |
| detail     | Trip, Reservation | GET /api/trips/{id}            |
| create     | Trip, Bus, Agency | POST /api/trips                |
| update     | Trip              | PUT /api/trips/{id}            |
| cancel     | Trip, Reservation | POST /api/trips/{id}/cancel    |
| reschedule | Trip              | PUT /api/trips/{id}/reschedule |
| search     | Trip              | POST /api/trips/search         |

### 5. AgencyController

| Action | Entité              | Route                               |
| ------ | ------------------- | ----------------------------------- |
| trips  | Trip, Agency        | GET /api/agencies/{agencyId}/trips  |
| points | AgencyPoint, Agency | GET /api/agencies/{agencyId}/points |
| update | Agency              | PUT /api/agencies/{agencyId}/admin  |
| detail | Agency, Wallet      | GET /api/agencies/{agencyId}        |

### 6. PaymentController

| Action    | Entité                     | Route                                |
| --------- | -------------------------- | ------------------------------------ |
| initiate  | PaymentIntent, Reservation | POST /api/payments/initiate          |
| confirm   | PaymentLog, Ticket, Wallet | POST /api/payments/confirm           |
| webhook   | PaymentLog, Reservation    | POST /api/payments/webhook           |
| refund    | Reservation, Wallet        | POST /api/payments/refund            |
| getStatus | PaymentIntent              | GET /api/payments/{paymentId}/status |

### 7. ClientController

| Action                   | Entité            | Route                                  |
| ------------------------ | ----------------- | -------------------------------------- |
| dashboard                | Reservation, User | GET /api/client/dashboard              |
| paymentHistory           | PaymentLog, User  | GET /api/client/payments/history       |
| getReservations          | Reservation, User | GET /api/client/reservations           |
| getCancelledReservations | Reservation, User | GET /api/client/reservations/cancelled |

### 8. TicketController

| Action          | Entité              | Route                          |
| --------------- | ------------------- | ------------------------------ |
| validate        | Ticket, Agent       | POST /api/tickets/validate     |
| getTicketDetail | Ticket, Reservation | GET /api/tickets/{id}          |
| cancel          | Ticket, Reservation | POST /api/tickets/{id}/cancel  |
| markNoShow      | Ticket, Wallet      | POST /api/tickets/{id}/no-show |
| getQrCode       | Ticket              | GET /api/tickets/{id}/qr-code  |

### 9. SupportController

| Action          | Entité                         | Route                           |
| --------------- | ------------------------------ | ------------------------------- |
| create          | SupportTicket, User            | POST /api/support               |
| addResponse     | SupportTicket, SupportResponse | POST /api/support/{id}/response |
| close           | SupportTicket                  | POST /api/support/{id}/close    |
| getTickets      | SupportTicket, User            | GET /api/support/my-tickets     |
| getTicketDetail | SupportTicket                  | GET /api/support/{id}           |

### 10. EnrollmentController

| Action            | Entité                           | Route                                        |
| ----------------- | -------------------------------- | -------------------------------------------- |
| submitApplication | Application, ApplicationDocument | POST /api/public/enrollment                  |
| list              | Application                      | GET /api/public/enrollment/applications      |
| detail            | Application                      | GET /api/public/enrollment/{id}              |
| trackStatus       | Application                      | GET /api/public/enrollment/{reference}/track |

### 11. BusController

| Action         | Entité      | Route                          |
| -------------- | ----------- | ------------------------------ |
| getAgencyBus   | Bus, Agency | GET /api/buses                 |
| create         | Bus, Agency | POST /api/buses                |
| update         | Bus         | PUT /api/buses/{id}            |
| delete         | Bus         | DELETE /api/buses/{id}         |
| uploadDocument | Bus         | POST /api/buses/{id}/documents |

### 12. AgencyPointController

| Action | Entité              | Route                                            |
| ------ | ------------------- | ------------------------------------------------ |
| index  | AgencyPoint, Agency | GET /api/agencies/{agencyId}/points              |
| create | AgencyPoint, Agency | POST /api/agencies/{agencyId}/points             |
| update | AgencyPoint         | PUT /api/agencies/{agencyId}/points/{pointId}    |
| delete | AgencyPoint         | DELETE /api/agencies/{agencyId}/points/{pointId} |

### 13. DeviceController

| Action      | Entité       | Route                       |
| ----------- | ------------ | --------------------------- |
| register    | Device, User | POST /api/devices           |
| delete      | Device       | DELETE /api/devices/{id}    |
| updateToken | Device       | PUT /api/devices/{id}/token |

### 14. NotificationController

| Action        | Entité       | Route                             |
| ------------- | ------------ | --------------------------------- |
| index         | Notification | GET /api/notifications            |
| markAsRead    | Notification | POST /api/notifications/{id}/read |
| markAllAsRead | Notification | POST /api/notifications/read-all  |
| delete        | Notification | DELETE /api/notifications/{id}    |
| deleteAll     | Notification | DELETE /api/notifications         |

### 15. PromoController

| Action | Entité | Route                   |
| ------ | ------ | ----------------------- |
| active | Promo  | GET /api/promos/active  |
| list   | Promo  | GET /api/promos         |
| create | Promo  | POST /api/promos        |
| update | Promo  | PUT /api/promos/{id}    |
| delete | Promo  | DELETE /api/promos/{id} |

### 16. CityController

| Action | Entité | Route                |
| ------ | ------ | -------------------- |
| index  | City   | GET /api/cities      |
| create | City   | POST /api/cities     |
| update | City   | PUT /api/cities/{id} |

### 17. StatisticsController

| Action              | Entité            | Route                        |
| ------------------- | ----------------- | ---------------------------- |
| getAgentStatistics  | Agent, Ticket     | GET /api/statistics/agent    |
| getClientStatistics | User, Reservation | GET /api/statistics/client   |
| getPlatformStats    | -                 | GET /api/statistics/platform |

### 18. AgencyDocumentController

| Action   | Entité                 | Route                                   |
| -------- | ---------------------- | --------------------------------------- |
| upload   | AgencyDocument, Agency | POST /api/agency-documents              |
| list     | AgencyDocument, Agency | GET /api/agency-documents               |
| download | AgencyDocument         | GET /api/agency-documents/{id}/download |
| delete   | AgencyDocument         | DELETE /api/agency-documents/{id}       |

### 19. FaqController

| Action | Entité | Route                 |
| ------ | ------ | --------------------- |
| list   | FAQ    | GET /api/faqs         |
| create | FAQ    | POST /api/faqs        |
| update | FAQ    | PUT /api/faqs/{id}    |
| delete | FAQ    | DELETE /api/faqs/{id} |

### 20. PusherAuthController

| Action       | Entité | Route                 |
| ------------ | ------ | --------------------- |
| authenticate | User   | POST /api/pusher/auth |

### 21. GetCitiesController

| Action     | Entité | Route           |
| ---------- | ------ | --------------- |
| \_\_invoke | City   | GET /api/cities |

### 22. GetValidationStatsController

| Action     | Entité       | Route                                    |
| ---------- | ------------ | ---------------------------------------- |
| \_\_invoke | Ticket, Trip | GET /api/trips/{tripId}/validation-stats |

---

## 🔷 CONTRÔLEURS ADMIN (src/Controller/Admin/)

### 1. AdminAuthController

| Action         | Entité      | Route                           |
| -------------- | ----------- | ------------------------------- |
| me             | Admin, User | GET /api/admin/auth/me          |
| permissions    | Admin       | GET /api/admin/auth/permissions |
| logout         | Admin, User | POST /api/admin/auth/logout     |
| updateActivity | Admin       | POST /api/admin/auth/activity   |

### 2. AdminUserController

| Action        | Entité             | Route                                     |
| ------------- | ------------------ | ----------------------------------------- |
| list          | User, Agent, Admin | GET /api/admin/users                      |
| detail        | User               | GET /api/admin/users/{id}                 |
| update        | User               | PUT /api/admin/users/{id}                 |
| delete        | User               | DELETE /api/admin/users/{id}              |
| suspend       | User               | POST /api/admin/users/{id}/suspend        |
| activate      | User               | POST /api/admin/users/{id}/activate       |
| resetPassword | User               | POST /api/admin/users/{id}/reset-password |

### 3. AdminAgencyController

| Action            | Entité                      | Route                                 |
| ----------------- | --------------------------- | ------------------------------------- |
| list              | Agency, Wallet              | GET /api/admin/agencies               |
| detail            | Agency                      | GET /api/admin/agencies/{id}          |
| create            | Agency, User, Agent, Wallet | POST /api/admin/agencies              |
| update            | Agency                      | PUT /api/admin/agencies/{id}          |
| delete            | Agency                      | DELETE /api/admin/agencies/{id}       |
| toggleStatus      | Agency                      | PUT /api/admin/agencies/{id}/status   |
| operationalImpact | Agency                      | GET /api/admin/agencies/{id}/impact   |
| approve           | Agency                      | POST /api/admin/agencies/{id}/approve |
| reject            | Agency                      | POST /api/admin/agencies/{id}/reject  |

### 4. AdminTripController

| Action     | Entité                    | Route                                |
| ---------- | ------------------------- | ------------------------------------ |
| list       | Trip, Agency              | GET /api/admin/trips                 |
| detail     | Trip, Reservation, Ticket | GET /api/admin/trips/{id}            |
| cancel     | Trip, Reservation         | POST /api/admin/trips/{id}/cancel    |
| update     | Trip                      | PUT /api/admin/trips/{id}            |
| reschedule | Trip, Reservation         | PUT /api/admin/trips/{id}/reschedule |

### 5. AdminReservationController

| Action  | Entité                     | Route                                    |
| ------- | -------------------------- | ---------------------------------------- |
| list    | Reservation, Trip          | GET /api/admin/reservations              |
| detail  | Reservation, Ticket        | GET /api/admin/reservations/{id}         |
| update  | Reservation                | PUT /api/admin/reservations/{id}         |
| cancel  | Reservation, RefundRequest | POST /api/admin/reservations/{id}/cancel |
| getKpis | Reservation                | GET /api/admin/reservations/kpis         |

### 6. AdminRefundController

| Action          | Entité                             | Route                                |
| --------------- | ---------------------------------- | ------------------------------------ |
| list            | RefundRequest, Reservation         | GET /api/admin/refunds               |
| detail          | RefundRequest                      | GET /api/admin/refunds/{id}          |
| processStandard | RefundRequest, Wallet              | POST /api/admin/refunds/{id}/process |
| forceRefund     | RefundRequest, Wallet, Reservation | POST /api/admin/refunds/{id}/force   |
| reject          | RefundRequest                      | POST /api/admin/refunds/{id}/reject  |

### 7. AdminWalletController

| Action         | Entité                    | Route                                 |
| -------------- | ------------------------- | ------------------------------------- |
| list           | Wallet, Agency            | GET /api/admin/wallets                |
| detail         | Wallet, WalletTransaction | GET /api/admin/wallets/{id}           |
| adjustBalance  | Wallet, WalletTransaction | POST /api/admin/wallets/{id}/adjust   |
| reconciliation | Wallet                    | GET /api/admin/wallets/reconciliation |

### 8. AdminWithdrawalController

| Action  | Entité                                | Route                                    |
| ------- | ------------------------------------- | ---------------------------------------- |
| list    | WithdrawalRequest, Agency             | GET /api/admin/withdrawals               |
| detail  | WithdrawalRequest                     | GET /api/admin/withdrawals/{id}          |
| approve | WithdrawalRequest, Wallet             | POST /api/admin/withdrawals/{id}/approve |
| reject  | WithdrawalRequest                     | POST /api/admin/withdrawals/{id}/reject  |
| process | WithdrawalRequest, Wallet, PaymentLog | POST /api/admin/withdrawals/{id}/process |

### 9. AdminSupportController

| Action             | Entité                         | Route                                         |
| ------------------ | ------------------------------ | --------------------------------------------- |
| getAllTickets      | SupportTicket                  | GET /api/admin/support/tickets                |
| getTicketDetail    | SupportTicket, SupportResponse | GET /api/admin/support/tickets/{id}           |
| assignTicket       | SupportTicket                  | POST /api/admin/support/tickets/{id}/assign   |
| updateTicketStatus | SupportTicket                  | PUT /api/admin/support/tickets/{id}/status    |
| closeTicket        | SupportTicket                  | POST /api/admin/support/tickets/{id}/close    |
| addResponse        | SupportTicket, SupportResponse | POST /api/admin/support/tickets/{id}/response |
| searchTickets      | SupportTicket                  | GET /api/admin/support/tickets/search         |

### 10. AdminFinancialController

| Action               | Entité              | Route                                              |
| -------------------- | ------------------- | -------------------------------------------------- |
| reconciliation       | Wallet, Reservation | GET /api/admin/financial/reconciliation            |
| reconciliationAgency | Wallet, Agency      | GET /api/admin/financial/reconciliation/{agencyId} |
| getReports           | PaymentLog, Wallet  | GET /api/admin/financial/reports                   |
| exportReport         | PaymentLog          | POST /api/admin/financial/export                   |

### 11. AdminDashboardController

| Action            | Entité             | Route                             |
| ----------------- | ------------------ | --------------------------------- |
| getKpis           | User, Agency, Trip | GET /api/admin/dashboard/kpis     |
| getRecentActivity | -                  | GET /api/admin/dashboard/activity |
| getRevenue        | PaymentLog, Wallet | GET /api/admin/dashboard/revenue  |
| getTripMetrics    | Trip, Reservation  | GET /api/admin/dashboard/trips    |

### 12. AdminApplicationController

| Action          | Entité                           | Route                                          |
| --------------- | -------------------------------- | ---------------------------------------------- |
| list            | Application                      | GET /api/admin/applications                    |
| detail          | Application, ApplicationDocument | GET /api/admin/applications/{id}               |
| approve         | Application, Agency, User, Agent | POST /api/admin/applications/{id}/approve      |
| reject          | Application                      | POST /api/admin/applications/{id}/reject       |
| requestMoreInfo | Application                      | POST /api/admin/applications/{id}/request-info |
| getKpis         | Application                      | GET /api/admin/applications/kpis               |

### 13. AdminAuditController

| Action | Entité   | Route                        |
| ------ | -------- | ---------------------------- |
| list   | AuditLog | GET /api/admin/audit         |
| detail | AuditLog | GET /api/admin/audit/{id}    |
| export | AuditLog | POST /api/admin/audit/export |

### 14. AdminProfileController

| Action         | Entité      | Route                                   |
| -------------- | ----------- | --------------------------------------- |
| getProfile     | Admin, User | GET /api/admin/profile                  |
| updateProfile  | Admin, User | PUT /api/admin/profile                  |
| changePassword | Admin, User | POST /api/admin/profile/change-password |

### 15. AdminCityController

| Action | Entité | Route                         |
| ------ | ------ | ----------------------------- |
| list   | City   | GET /api/admin/cities         |
| create | City   | POST /api/admin/cities        |
| update | City   | PUT /api/admin/cities/{id}    |
| delete | City   | DELETE /api/admin/cities/{id} |

### 16. AdminSystemSettingsController

| Action | Entité         | Route                        |
| ------ | -------------- | ---------------------------- |
| list   | SystemSettings | GET /api/admin/settings      |
| update | SystemSettings | PUT /api/admin/settings/{id} |

### 17. AdminAdminController

| Action       | Entité      | Route                             |
| ------------ | ----------- | --------------------------------- |
| list         | Admin, User | GET /api/admin/admins             |
| detail       | Admin       | GET /api/admin/admins/{id}        |
| create       | Admin, User | POST /api/admin/admins            |
| update       | Admin       | PUT /api/admin/admins/{id}        |
| delete       | Admin       | DELETE /api/admin/admins/{id}     |
| toggleStatus | Admin       | PUT /api/admin/admins/{id}/status |

### 18. ModerationStatsController

| Action        | Entité              | Route                                  |
| ------------- | ------------------- | -------------------------------------- |
| getStats      | SupportTicket, User | GET /api/admin/moderation/stats        |
| getComparison | -                   | GET /api/admin/moderation/comparison   |
| getChartData  | -                   | GET /api/admin/moderation/chart/{type} |

---

## 🔷 CONTRÔLEURS PARTNER (src/Controller/Partner/)

### 1. PartnerOperationsController

| Action          | Entité                   | Route                               |
| --------------- | ------------------------ | ----------------------------------- |
| context         | Agency, Wallet           | GET /api/partner/context            |
| dashboard       | Trip, Agent, Bus, Agency | GET /api/partner/dashboard          |
| createBus       | Bus, Agency              | POST /api/partner/buses             |
| createTrip      | Trip, Bus, Agency        | POST /api/partner/trips             |
| updateBus       | Bus                      | PUT /api/partner/buses/{id}         |
| updateTrip      | Trip                     | PUT /api/partner/trips/{id}         |
| manageBusPoints | Bus, AgencyPoint         | POST /api/partner/buses/{id}/points |

### 2. PartnerFinanceController

| Action            | Entité                    | Route                                |
| ----------------- | ------------------------- | ------------------------------------ |
| getPartnerStats   | Wallet, Trip, Reservation | GET /api/statistics                  |
| listReports       | PaymentLog, Wallet        | GET /api/partner/finance/reports     |
| exportReport      | PaymentLog                | POST /api/partner/finance/export     |
| requestWithdrawal | WithdrawalRequest, Wallet | POST /api/partner/finance/withdraw   |
| withdrawalHistory | WithdrawalRequest         | GET /api/partner/finance/withdrawals |

### 3. PartnerAnalyticsController

| Action             | Entité                    | Route                                  |
| ------------------ | ------------------------- | -------------------------------------- |
| overview           | Ticket, Trip, Reservation | GET /api/partner/analytics             |
| dailyStats         | Trip, Ticket              | GET /api/partner/analytics/daily       |
| performanceMetrics | Trip, Agent               | GET /api/partner/analytics/performance |

---

## 🔷 CONTRÔLEURS AGENT (src/Controller/Agent/)

### 1. AgentOperationsController

| Action         | Entité              | Route                                |
| -------------- | ------------------- | ------------------------------------ |
| dashboard      | Trip, Ticket, Agent | GET /api/agent/dashboard             |
| trips          | Trip, Agent         | GET /api/agent/trips                 |
| manifest       | Trip, Ticket        | GET /api/agent/trips/{id}/manifest   |
| ticketDetails  | Ticket              | GET /api/agent/tickets/{id}          |
| validateTicket | Ticket, Agent       | POST /api/agent/tickets/validate     |
| markNoShow     | Ticket, Wallet      | POST /api/agent/tickets/{id}/no-show |

---

## 📊 STATISTIQUES D'EXPLORATION

### Fichiers trouvés

- **Contrôleurs racine**: 24 fichiers
- **Contrôleurs Admin**: 18 fichiers
- **Contrôleurs Agent**: 1 fichier
- **Contrôleurs Partner**: 3 fichiers
- **Total**: 46 fichiers PHP

### Actions par catégorie (CRUD + Auth)

| Catégorie  | Nombre | Exemples                                   |
| ---------- | ------ | ------------------------------------------ |
| **CREATE** | ~25    | submitApplication, create, register        |
| **READ**   | ~50    | list, detail, index, getKpis, dashboard    |
| **UPDATE** | ~30    | update, approve, updateStatus, reschedule  |
| **DELETE** | ~15    | delete, reject, cancel                     |
| **AUTH**   | ~6     | login, logout, confirmOtp, me, permissions |
| **AUTRE**  | ~30    | validate, reconciliation, export, webhook  |

### Entités critiques pour l'instrumentation

1. **User** - 40+ actions (authentification, profil)
2. **Reservation** - 30+ actions (création, annulation, remboursement)
3. **Trip** - 25+ actions (création, modification, annulation)
4. **Ticket** - 15+ actions (validation, annulation)
5. **Agency** - 20+ actions (gestion, statut)
6. **Wallet** - 15+ actions (transactions, retrait)
7. **PaymentLog** - 10+ actions (paiement, webhook)
8. **RefundRequest** - 8+ actions (demande, traitement)
9. **SupportTicket** - 12+ actions (création, réponse, fermeture)
10. **Admin/Agent** - 10+ actions (gestion, permissions)

---

## 🎯 ACTIONS À INSTRUMENTER EN PRIORITÉ

### CRITIQUES (Transactions financières)

- `BookingController::create` - Création de réservation
- `PaymentController::initiate` - Initiation de paiement
- `PaymentController::confirm` - Confirmation de paiement
- `AdminRefundController::processStandard` - Traitement remboursement standard
- `AdminRefundController::forceRefund` - Forçage remboursement
- `AdminWithdrawalController::approve` - Approbation retrait
- `AdminWithdrawalController::process` - Traitement retrait

### IMPORTANTS (Gestion entités)

- `TripController::create` - Création voyage
- `TripController::cancel` - Annulation voyage
- `TicketController::validate` - Validation billet
- `AuthController::login` - Connexion utilisateur
- `AuthController::register` - Inscription utilisateur
- `AdminUserController::suspend` - Suspension utilisateur
- `AdminAgencyController::create` - Création agence
- `AdminAgencyController::toggleStatus` - Changement statut agence

### MONITORING (Statistiques/Reports)

- `AdminDashboardController::getKpis`
- `PartnerFinanceController::getPartnerStats`
- `AdminFinancialController::reconciliation`
- `ClientController::dashboard`
- `StatisticsController::*`

---

## 📝 NOTES D'INSTRUMENTATION

1. **Authentification** : Tous les contrôleurs Admin doivent être instrumentés
2. **Transactions** : Priorité absolue sur les opérations de paiement/portefeuille
3. **Audit** : Créations, suppressions, changements de statut
4. **Notifications** : Effectuer les appels AdminNotificationService
5. **Erreurs** : Capturer les JsonResponse avec codes HTTP >= 400
