# uzero_wpup

Eina d'actualització segura de WordPress/WooCommerce **Uzero wpup** (binari `wpup`): còpia de seguretat, compatibilitat amb el PHP real del lloc, actualització, verificació visual i funcional, i rollback automàtic.

- [AGENTS.md](AGENTS.md): regles de treball per a agents i persones
- [docs/especificacio.md](docs/especificacio.md): especificació
- [docs/adr/](docs/adr/): decisions d'arquitectura
- Tasques: Plane, projecte UZEROWPUP

Estat: planificació. El codi comença amb UZEROWPUP-1.

## Desenvolupament

L'entorn de desenvolupament corre amb Docker Compose (projecte `uzero-wpup`).

Requisits: Docker amb Compose v2 i `make`. No cal PHP, Composer ni Node al host.

```bash
make up        # construeix les imatges, prepara .env/vendor/APP_KEY, arrenca i espera que tot estigui healthy
make migrate   # migracions a la BD `wpup` com a `wpup_owner`
make test      # Pest dins del contenidor, contra la BD `wpup_test`
make help      # llista totes les comandes (shell, artisan, composer, logs, ps, down...)
```

| Servei | URL / port al host |
| --- | --- |
| Aplicació | http://127.0.0.1:8000 |
| Horizon | http://127.0.0.1:8000/horizon |
| Mailpit (correu de prova) | http://127.0.0.1:8025 |
| PostgreSQL | Només dins de la xarxa de Compose (`make psql`); rols `wpup_owner` i `wpup_app` |
| Redis | Només dins de la xarxa de Compose (`make redis-cli`) |

- **Tots els ports es publiquen només a `127.0.0.1`** (ADR-0003); mai a `0.0.0.0`. El SMTP de Mailpit només és accessible dins de la xarxa de Compose.
- L'aplicació corre com a `wpup_app` i les migracions com a `wpup_owner` (connexió `pgsql_owner`, ADR-0004). En desenvolupament l'autenticació és `trust` dins de la xarxa de Compose; a la VM és socket Unix amb `peer`.
- El desenvolupament local **no fa servir `systemd-creds` ni el TPM**: l'ADR-0002 s'aplica a la VM de l'eina.
- El servidor `php artisan serve` és només per a desenvolupament; producció usarà PHP-FPM.
- L'UID/GID del contenidor és el de l'usuari del host (`WPUP_UID`/`WPUP_GID`).
- `make ci` i la resta de comandes de qualitat arriben amb la tasca UZEROWPUP-2.
