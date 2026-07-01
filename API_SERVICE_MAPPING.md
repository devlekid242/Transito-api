# Mapping services frontend → endpoints API → contrôleurs backend

Résumé compact des services Angular/Ionic et des routes backend correspondantes.

**AuthService**:
- Endpoints:
  - POST `/api/auth/login` → `AuthController::login` (exists)
  - POST `/api/auth/refresh` → `AuthController::refresh` (exists)
  - POST `/api/auth/request-reset` → `AuthController::requestReset` (exists)
  - POST `/api/auth/verify-reset` → `AuthController::verifyReset` (exists)
  - POST `/api/register` → `AuthController::register` (✅ IMPLEMENTED)

**UserService**:
- Base: `/api/users`
- Endpoints:
  - GET `/api/users/me` → `UserController::currentUser` (exists)
  - PUT/PATCH `/api/users/me` → `UserController::update` (exists)
  - POST `/api/users/me/photo` → `UserController::updatePhoto` (✅ IMPLEMENTED)
  - POST `/api/users/change-password` → `UserController::changePassword` (✅ IMPLEMENTED)
  - POST `/api/users/addresses` → MISSING (addresses CRUD endpoints not implemented)
  - DELETE `/api/users/addresses/{id}` → MISSING
  - GET `/api/users/addresses` → MISSING

**TripService**:
- Base: `/api/trips` → `TripController` (exists)
- Endpoints implemented in `TripController`:
  - GET `/api/trips` (search/pagination)
  - GET `/api/trips/{id}` (detail)
  - GET `/api/trips/cities/departure`
  - GET `/api/trips/cities/arrival`
  - GET `/api/trips/popular`
- Additional used endpoint from frontend:
  - GET `/api/agencies/{agencyId}/trips` → `AgencyController::trips` (exists)

**TicketService**:
- Base: `/api/tickets` → `TicketController` (exists)
- Endpoints used:
  - GET `/api/tickets/{id}` → detail
  - GET `/api/tickets?reservation_id=...` → list by reservation
  - PATCH `/api/tickets/{id}/validate` → `TicketController::validate` (exists)
  - PATCH `/api/tickets/{id}` (cancel) → `TicketController` (exists)
  - GET `/api/tickets?trip_id=...` → manifest

**SupportService**:
- Base: `/api/support` → `SupportController` (exists)
- Endpoints used:
  - POST `/api/support` (create ticket)
  - GET `/api/support/my-tickets`
  - GET `/api/support/{id}`
  - POST `/api/support/{id}/responses`
  - GET `/api/support/{id}/responses`

**PromoService**:
- Base: `/api/promos` → `PromoController` (exists)
- Endpoints used:
  - GET `/api/promos/active`
  - GET `/api/promos/validate?code=...` (and optional `trip_id`)
  - POST `/api/promos/apply`
  - GET `/api/promos/discount?code=...&amount=...`
  - GET `/api/promos/my-codes`

**PaymentService**:
- Base: `/api/payments` → `PaymentController` (exists)
- Endpoints used:
  - POST `/api/payments/initiate`
  - POST `/api/payments/confirm`
  - GET `/api/payments/history`
  - GET `/api/payments/{id}`
  - POST `/api/payments/{id}/refund`
  - GET `/api/payments/methods`
  - POST `/api/payments/validate-card`
  - GET `/api/payments/transaction/{transactionId}`

**NotificationService**:
- Base: `/api/user-notifications` → `NotificationController` (exists)
- Endpoints used:
  - GET `/api/user-notifications`
  - GET `/api/user-notifications/unread`
  - PATCH `/api/user-notifications/{id}/read`
  - PATCH `/api/user-notifications/mark-all-read`
  - GET `/api/user-notifications/unread/count`
  - DELETE `/api/user-notifications/{id}`

**BookingService**:
- Base: `/api/bookings` → `BookingController` (exists)
- Endpoints used:
  - POST `/api/bookings`
  - GET `/api/bookings/{id}`
  - GET `/api/bookings/my-bookings`
  - GET `/api/bookings/active`
  - GET `/api/bookings/history`
  - POST `/api/bookings/{id}/cancel`
  - PUT `/api/bookings/{id}`
  - POST `/api/bookings/validate-seats`

**AgencyService**:
- Base: `/api/agencies` → `AgencyController` (partial)
- Endpoints used by frontend:
  - GET `/api/agencies` (list) → MISSING (not implemented in `AgencyController`)
  - GET `/api/agencies/{id}` (detail) → MISSING (not implemented)
  - GET `/api/agencies/search` → MISSING
  - GET `/api/agencies/{id}/points` → `AgencyController::points` (exists)
  - GET `/api/agencies/popular` → MISSING
  - GET `/api/agencies/city` → MISSING
  - POST `/api/agencies/{id}/rate` → MISSING

**AgencyPointService**:
- Base: `/api/agency-points` → `AgencyPointController` (exists)
- Endpoints used:
  - GET `/api/agency-points`
  - GET `/api/agency-points?agency_id=...`
  - GET `/api/agency-points/{id}`
  - POST `/api/agency-points`
  - PUT `/api/agency-points/{id}`
  - DELETE `/api/agency-points/{id}`

---

Actions recommandées (priorités):
1. ✅ `POST /api/register` — DONE (AuthController::register)
2. ✅ `POST /api/users/me/photo` — DONE (UserController::updatePhoto)
3. ✅ `POST /api/users/change-password` — DONE (UserController::changePassword)
4. Ajouter endpoints utilisateur restants : `/api/users/addresses` CRUD.
5. Compléter `AgencyController` pour fournir listes, détails et recherches (`/api/agencies`, `/api/agencies/{id}`, `/search`, `/popular`, `/city`, `/{id}/rate`).
6. Vérifier si d'autres routes front→back utilisent alternate naming — adapter le frontend si vous préférez.

**Endpoints implémentés dans cette session :**
- User entity: ajout du champ `profilePhotoUrl` (nullable)
- UserController: 
  - POST `/api/users/me/photo` (upload multipart form-data)
  - POST `/api/users/change-password` (old_password/new_password)
  - Serializer mis à jour pour inclure profilePhotoUrl
- AuthController: 
  - POST `/api/register` (fullName/email/phoneNumber/password → JWT auto-login)

Tous les fichiers modifiés compilent sans erreurs.

Si vous voulez, je peux maintenant :
- Générer des stubs pour les endpoints AgencyController manquants, ou
- Implémenter `/api/users/addresses` CRUD, ou
- Faire un rapport complet de validation.

