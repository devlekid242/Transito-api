# Mode interactif complet (recommandé en prod)
php bin/console app:admin:create

# Avec options, prompt du mot de passe uniquement
php bin/console app:admin:create --email=admin@transito.cg --role=SUPER_ADMIN

# Entièrement scripté (CI / déploiement) — mot de passe généré et affiché une fois
php bin/console app:admin:create --email=admin@transito.cg --role=SUPER_ADMIN --no-interaction

# Mettre à jour un admin existant sans confirmation
php bin/console app:admin:create --email=admin@transito.cg --role=ADMIN --force