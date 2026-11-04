# Runbook: actualitzar l'eina (desplegar una versió nova)

Per desplegar una versió etiquetada de l'eina al servidor ja instal·lat ([desplegament](desplegament.md)). Ho fa un `admin` amb accés a root a la VM. L'script és `deploy/release.sh`.

## Abans de començar

1. La versió ha passat `make ci` i el PR s'ha fusionat a `main`; hi ha una **etiqueta** (`vX.Y.Z`) feta per una persona.
2. **No hi ha cap execució important en curs:** `wpup runs` (o el panell). Horizon espera que acabi el treball actual, però és millor no desplegar enmig d'una actualització d'un lloc.
3. Hi ha una **còpia recent de la BD de l'eina** (la diària, ADR-0009) i la còpia de la VM al Proxmox és d'avui. Les migracions són transaccionals, però no es desfan soles.
4. Llegeix les notes de la versió: qualsevol canvi de configuració (`.env`, `config/wpup.php`), de dependències del sistema (PHP, extensions) o de les unitats de systemd s'ha d'aplicar **abans** o **durant** aquest procediment.

## Passos

Com a root a la VM, des de la **còpia de root** del repositori (no des de `/opt/uzero-wpup`):

```bash
cd /usr/local/src/uzero-wpup
./deploy/release.sh <ETIQUETA> --dry-run     # opcional: només sincronitza i mostra el que faria
./deploy/release.sh <ETIQUETA>
```

L'script fa, en aquest ordre:

1. **`git fetch --tags`** a la còpia de root i `git checkout --detach <ETIQUETA>` (falla si l'etiqueta no existeix; amb `WPUP_REQUIRE_SIGNED_TAGS=1` també si no està signada).
2. **Còpia del codi** a `/opt/uzero-wpup` amb `rsync`, propietat de `wpup`, sense tocar `.env`, `storage/`, `vendor/` ni `bootstrap/cache`.
3. **`composer install --no-dev --optimize-autoloader`**, compilació dels recursos (`npm ci && npm run build`) i `make tools` (WP-CLI fixat, amb el seu *checksum*), tot com a `wpup`.
4. **Migracions com a `wpup_owner`:** `php artisan migrate --database=pgsql_owner --force`. Si una migració falla, PostgreSQL desfà la transacció i l'esquema queda com estava; l'script s'atura aquí i els serveis continuen amb el codi nou al disc però sense reiniciar (vegeu «Si alguna cosa falla»).
5. **Cachés** (`config:cache`, `route:cache`, `view:cache`, `event:cache`).
6. **Reinici ordenat:** `horizon:terminate` (Horizon acaba el treball actual i `systemd` el torna a arrencar amb el codi nou), reinici del scheduler i recàrrega de PHP-FPM.

Variables d'entorn de l'script: `WPUP_SRC_DIR` (còpia de root, per defecte `/usr/local/src/uzero-wpup`), `WPUP_REPO_URL`, `WPUP_REQUIRE_SIGNED_TAGS`.

> Si la versió canvia les unitats de systemd, la configuració de nginx/PHP-FPM o el `sudoers`, torna a executar el pas corresponent: `./deploy/provision.sh --only 55` (unitats), `--only 54` (web), `--only 53` (CLI i sudoers). Són idempotents.

## Comprovar el resultat

```bash
cat /opt/uzero-wpup/.release                 # etiqueta i commit desplegats
systemctl status wpup-horizon wpup-scheduler # actius
sudo ./deploy/checks/server.sh               # comprovacions del servidor
wpup doctor                                  # com a operador, comprovacions de l'eina
```

Si la versió toca la KEK, el motor d'execucions o la CLI, fes també un `wpup site:test` d'un lloc de prova.

## Si alguna cosa falla

- **Falla `composer` o els recursos:** no s'ha tocat res al sistema d'execució (els serveis encara corren amb el codi anterior en memòria). Arregla-ho i torna a executar `release.sh`; és idempotent.
- **Falla una migració:** l'esquema no ha canviat. Torna a l'etiqueta anterior (`./deploy/release.sh <ETIQUETA_ANTERIOR>`) o arregla la migració.
- **L'aplicació no arrenca bé després del desplegament:** torna a l'etiqueta anterior amb `./deploy/release.sh <ETIQUETA_ANTERIOR>`. **Això no desfà les migracions que ja s'havien aplicat:** si la versió nova va canviar l'esquema d'una manera incompatible amb la vella, restaura la BD de l'eina des de la còpia ([recuperació](recuperacio.md), passos de la BD) abans de tornar enrere.
- **Una execució d'actualització ha quedat interrompuda pel reinici:** `engine:recover` la reprèn o la reverteix (ho fa el planificador; vegeu l'ADR-0006). Revisa-ho amb `wpup runs`.

## Altres tasques de manteniment

- **Actualitzar Debian, PHP, PostgreSQL (menors):** `apt update && apt upgrade` i reinicia els serveis afectats (`systemctl restart wpup-horizon wpup-scheduler php8.4-fpm`). Les versions majors de PostgreSQL es fan com a molt un cop l'any i **després** de provar la restauració ([recuperació](recuperacio.md)).
- **Actualitzar el WP-CLI fixat:** és un canvi de versió i de *checksum* al `Makefile` i a `config/wpup.php`; es desplega amb una versió normal.
- **Rotar la KEK:** [rotació de la KEK](rotacio-kek.md).
