Oui. Voici l'ordre que je te recommande pour **Transito**, du plus prioritaire au moins prioritaire, en tenant compte des commandes que ton backend possède réellement.

## 🔴 Priorité 1 — Réservations impayées

À exécuter très régulièrement, idéalement **toutes les 5 minutes** :

```bash
php bin/console transito:bookings:expire-pending
```

### Rôle

```text
Réservation créée
      ↓
Paiement non effectué
      ↓
Délai dépassé
      ↓
Réservation expirée
      ↓
Sièges libérés
```

👉 **C'est la commande la plus importante à automatiser**, car sinon les sièges peuvent rester bloqués par des réservations abandonnées.

---

# 🔴 Priorité 2 — Cycle de vie des voyages

À exécuter également **toutes les 5 minutes** :

```bash
php bin/console transito:trips:sync-lifecycle
```

Elle s'occupe de synchroniser automatiquement les états des voyages et de gérer les passagers absents.

C'est celle qui doit maintenir ton système dans un état cohérent au fil du temps.

---

# 🔴 Priorité 3 — No-show

Ensuite :

```bash
php bin/console transito:trips:finalize-no-shows
```

Elle finalise les billets non présentés après le départ et la période de grâce.

⚠️ **Je ne te conseille pas encore de programmer cette commande indépendamment avant d'avoir vérifié si `sync-lifecycle` l'exécute déjà.**

Pour l'instant, teste-la manuellement.

---

# 🟠 Priorité 4 — Réconciliation financière

```bash
php bin/console transito:finance:reconcile
```

Cette commande contrôle :

```text
Wallet
   ↕
Ledger
```

Elle est importante pour ton modèle financier puisque tu veux que **chaque FCFA soit explicable**.

Je la programmerais par exemple **toutes les heures**.

---

# 🟠 Priorité 5 — Audit du workflow financier

```bash
php bin/console transito:finance:audit-workflows
```

Elle vérifie la chaîne :

```text
Réservation
    ↓
Paiement
    ↓
Ledger
    ↓
Billet
    ↓
Embarquement / No-show
```

C'est particulièrement important pour détecter les incohérences.

Je la programmerais **toutes les heures**, ou quelques fois par jour selon la charge.

---

# 🟡 Priorité 6 — Nettoyage OTP

```bash
php bin/console transito:auth:cleanup-otp
```

Cette commande supprime les challenges OTP expirés ou consommés depuis plus de 24 heures.

👉 Tu peux l'exécuter **une fois par jour**.

Par exemple :

```text
02:00
```

---

# 🟢 Priorité 7 — Contrat API

```bash
php bin/console transito:api:contract
```

⚠️ Celle-ci n'est **pas une tâche métier automatique**.

Elle sert à exporter l'inventaire des routes API.

Donc :

```text
❌ pas toutes les 5 minutes
❌ pas toutes les heures
```

Tu la lances seulement lorsque tu veux vérifier/générer le contrat de tes routes API.

---

# 📋 Ton classement complet

| Priorité | Commande                           | Fréquence conseillée    |
| -------: | ---------------------------------- | ----------------------- |
|     🔴 1 | `transito:bookings:expire-pending` | **5 min**               |
|     🔴 2 | `transito:trips:sync-lifecycle`    | **5 min**               |
|     🔴 3 | `transito:trips:finalize-no-shows` | À vérifier / périodique |
|     🟠 4 | `transito:finance:reconcile`       | **1 h**                 |
|     🟠 5 | `transito:finance:audit-workflows` | **1 h**                 |
|     🟡 6 | `transito:auth:cleanup-otp`        | **1 fois/jour**         |
|     🟢 7 | `transito:api:contract`            | Manuel                  |

---

# 🚀 Si tu veux simplement les tester maintenant

Dans ton terminal Windows, tu peux les exécuter **dans cet ordre** :

### 1️⃣

```bash
php bin/console transito:bookings:expire-pending
```

### 2️⃣

```bash
php bin/console transito:trips:sync-lifecycle
```

### 3️⃣

```bash
php bin/console transito:trips:finalize-no-shows
```

### 4️⃣

```bash
php bin/console transito:finance:reconcile
```

### 5️⃣

```bash
php bin/console transito:finance:audit-workflows
```

### 6️⃣

```bash
php bin/console transito:auth:cleanup-otp
```

Tu peux laisser :

```bash
php bin/console transito:api:contract
```

pour plus tard.

---

## ⭐ Et pour AlwaysData

À terme, je partirais sur quelque chose comme :

```text
Toutes les 5 min
├── expire-pending
└── sync-lifecycle

Toutes les heures
├── finance:reconcile
└── finance:audit-workflows

Tous les jours
└── auth:cleanup-otp
```

**Pour `finalize-no-shows`, je veux d'abord vérifier le code de `sync-lifecycle` et `finalize-no-shows` avant de te faire programmer les deux**, car si la première appelle déjà la seconde, les programmer séparément pourrait provoquer un double traitement du workflow no-show.

Si tu veux, colle-moi maintenant le résultat de :

```bash
php bin/console help transito:trips:sync-lifecycle
```

et

```bash
php bin/console help transito:trips:finalize-no-shows
```

et je te donne ensuite **la configuration exacte des tâches AlwaysData**, avec la fréquence et la commande à mettre pour chacune.
