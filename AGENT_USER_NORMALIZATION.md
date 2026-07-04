# Refactorisation de l'Architecture BD - Agent/User Normalization

## Résumé des modifications

### 1. **Entité User (`src/Entity/User.php`)**

- Ajout constraint unique sur `email` dans la table
- Reste la source de vérité pour l'authentification et l'identité (fullName, email, phoneNumber, password)

### 2. **Entité Agent (`src/Entity/Agent.php`)**

**Avant (Architecture redondante):**

```php
- id (PK)
- name, email, phone, password_hash  (REDONDANCE)
- agency_id (FK)
- role, status
- created_at
```

**Après (Architecture normalisée):**

```php
- user_id (PK + FK → users.id) - OneToOne unique
- agency_id (FK → agencies.id)
- agentRole (au lieu de 'role')
- status
```

**Avantages:**

- Élimination de la duplication name/email/phone/password
- Source unique pour l'authentification = table `users`
- Un agent _hérite_ de l'identité de son User associé
- Facilite les requêtes et la sécurité

### 3. **Migration Doctrine (`migrations/Version20260702_RefactorAgentNormalization.php`)**

La migration:

- Ajoute colonne `user_id` (FK unique vers `users.id`)
- Supprime colonnes redondantes : `name`, `email`, `phone`, `password_hash`, `created_at`
- Renomme colonne `role` → `agent_role` (pour éviter conflits avec les rôles Symfony)
- Crée index unique sur `user_id`

### 4. **AuthController (`src/Controller/AuthController.php`)**

**Méthode `/api/auth/login` :**

- Accepte `phoneNumber` ou `email` comme identifiant
- Cherche l'utilisateur dans la table `users` uniquement
- Retourne les infos d'agent (si lié) dans la réponse JWT
- Les agents n'ont plus de login séparé — ils s'authentifient comme des users

**Méthode `/api/auth/register` :**

- Crée un User d'abord
- **Optionnel:** Peut créer un Agent associé si `agent` est fourni dans le payload
- Retourne les infos d'agent dans la réponse si créé

---

## Guide d'application de la migration

### Étape 1: Appliquer la migration Doctrine

```bash
cd Transito-api
php bin/console doctrine:migrations:migrate
```

### Étape 2: Migration des données (si agents existants)

Si vous avez des agents existants, il faut les migrer vers la nouvelle structure:

**Requête SQL manuelle ou seed Doctrine:**

```sql
-- Créer les users à partir des agents existants
INSERT INTO users (full_name, email, phone, password_hash, roles, created_at)
SELECT name, email, phone, password_hash, '["ROLE_AGENT"]', created_at
FROM agents
WHERE email NOT IN (SELECT email FROM users WHERE email IS NOT NULL);

-- Lier les agents aux users
UPDATE agents a
SET a.user_id = (SELECT u.id FROM users u WHERE u.email = a.email)
WHERE a.user_id IS NULL;
```

### Étape 3: Construire et tester

```bash
cd transito-partner-dashboard
npm.cmd run build
```

---

## Exemples de requête pour tester

### 1. **Enregistrement d'un partenaire simple (User)**

```bash
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "fullName": "Jean Dupont",
    "email": "jean@transito.com",
    "phoneNumber": "+242 123456789",
    "password": "SecurePass123!"
  }'
```

### 2. **Enregistrement d'un agent avec création simultanée du rôle**

```bash
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "fullName": "Marie Nkalla",
    "email": "marie@agence.com",
    "phoneNumber": "+242 987654321",
    "password": "AgentPass123!",
    "agent": {
      "agencyId": 1,
      "agentRole": "admin_agence",
      "status": "active"
    }
  }'
```

### 3. **Login avec email**

```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "jean@transito.com",
    "password": "SecurePass123!"
  }'
```

### 4. **Login avec phone (ancien format)**

```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "phoneNumber": "+242 123456789",
    "password": "SecurePass123!"
  }'
```

### 5. **Réponse login pour un agent**

```json
{
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer",
    "expires_in": 3600,
    "refresh_token": "...",
    "user": {
        "id": 5,
        "fullName": "Marie Nkalla",
        "email": "marie@agence.com",
        "phoneNumber": "+242 987654321",
        "roles": ["ROLE_AGENT"],
        "profilePhotoUrl": null,
        "agent": {
            "agentRole": "admin_agence",
            "status": "active",
            "agency": {
                "id": 1,
                "name": "Agence Kinshasa"
            }
        }
    }
}
```

---

## Compatibilité Frontend

Le frontend (`transito-partner-dashboard`) n'a pas besoin de changement majeur car:

1. L'authentification reste identique (email ou phone + password)
2. Le token JWT fonctionne de la même manière
3. La réponse login inclut les infos d'agent si disponible

### Utilisation côté front (optionnel):

```typescript
// Après login, vérifier si agent
if (user.agent) {
    console.log("Utilisateur est agent:", user.agent.agentRole);
    // Charger dashboard agent
}
```

---

## Points de vigilance

1. **Unicité d'email:** La migration ajoute une contrainte unique sur `email`. Vérifier qu'aucun doublon n'existe avant migration.
2. **Données héritées:** Si agents existants, exécuter script migration pour créer les users correspondants.
3. **Backward Compatibility:** Les endpoints anciens `/api/agents` continuent de marcher, mais l'authentification passe par `users`.
4. **Fixtures:** Adapter `DataFixtures/PartnerDashboardFixture.php` pour créer d'abord User, puis Agent.

---

## Prochaines étapes

1. ✅ Refactoriser entités (Agent, User)
2. ✅ Créer migration Doctrine
3. ✅ Adapter AuthController (login/register)
4. ⏳ **Prochaine:** Appliquer migration BDD
5. ⏳ **Puis:** Tester endpoints auth
6. ⏳ **Enfin:** Mettre à jour fixtures de test
