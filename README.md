# uzero_wpup

Eina d'actualització segura de WordPress/WooCommerce **Uzero wpup** (binari `wpup`): còpia de seguretat, compatibilitat amb el PHP real del lloc, actualització, verificació visual i funcional, i rollback automàtic.

- [AGENTS.md](AGENTS.md): regles de treball per a agents i persones
- [docs/especificacio.md](docs/especificacio.md): especificació
- [docs/adr/](docs/adr/): decisions d'arquitectura
- Tasques: Plane, projecte UZEROWPUP

Estat: planificació. El codi comença amb UZEROWPUP-1.

## Desenvolupament

Requisits mínims: PHP 8.3+, Composer 2 i Node 24+ (verificador).

```bash
composer install
cp .env.example .env && php artisan key:generate
./wpup --version                 # binari de la CLI (delega a artisan)
./vendor/bin/pint --test         # estil
./vendor/bin/phpstan analyse     # Larastan, nivell max
./vendor/bin/pest                # tests
cd verifier && npm ci && npm run lint && npm run typecheck && npm test
```

L'entorn Docker i el `Makefile` (`make ci`) arriben amb les tasques UZEROWPUP-3 i següents.
