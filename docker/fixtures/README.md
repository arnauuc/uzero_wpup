# Llocs WordPress/WooCommerce de prova

Flota de llocs de prova per als tests d'integració (SshDriver, inventari, còpies, actualitzacions…). Projecte Compose **separat** del de desenvolupament: `uzero-wpup-fixtures` (`docker/fixtures/compose.yaml`).

> **Tot és d'usar i llençar.** Les credencials d'aquest directori (BD, administrador de WordPress, clau SSH) són valors de prova que només existeixen aquí. No es reutilitzen enlloc més ni apunten a cap lloc real. Mai hi posis dades reals.

## Llocs

| Lloc | PHP | BD | WordPress | WooCommerce | HTTP | SSH | Usuari SSH |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `wp74` | 7.4 | MariaDB 10.11 | 6.6.2 | 9.3.3 | `http://127.0.0.1:8081` | `127.0.0.1:2221` | `sub_wp74` |
| `wp81` | 8.1 | MariaDB 10.11 | 6.7.2 | 9.6.2 | `http://127.0.0.1:8082` | `127.0.0.1:2222` | `sub_wp81` |
| `wp83` | 8.3 | MariaDB 10.11 | 6.8.1 | 9.8.5 | `http://127.0.0.1:8083` | `127.0.0.1:2223` | `sub_wp83` |
| `wp80` | 8.0 | MariaDB 10.11 | 6.7.2 | 9.6.2 | `http://127.0.0.1:8085` | `127.0.0.1:2225` (només SFTP) | `sub_wp80` |
| `wp82` | 8.2 | MySQL 8.0 | 6.8.1 | 9.8.5 | `http://127.0.0.1:8084` | `127.0.0.1:2224` | `sub_wp82` |

Tots els ports es publiquen **només a `127.0.0.1`** (ADR-0003). Les BD (serveis `db`, MariaDB 10.11, i `db80`, MySQL 8.0) només són a la xarxa interna `fixtures`. Amb `wp80` i `wp82` la flota té dos contenidors de lloc i una BD més, així que cal vigilar la RAM (`db80` va amb `innodb-buffer-pool-size=64M` i `performance-schema=OFF`).

Cada lloc imita una subscripció de Plesk: usuari de sistema no root (`sub_<lloc>`, shell `/bin/bash`), docroot `/var/www/vhosts/<lloc>.fixtures.test/httpdocs`, Apache servint com aquest usuari, i SSH només amb clau (`PasswordAuthentication no`, `PermitRootLogin no`).

### Allotjament compartit (`wp80`) i MySQL 8.0 (`wp82`)

- **`wp80` només té SFTP**, com un allotjament compartit sense shell (per al futur `AgentDriver`): `sshd` té un bloc `Match User sub_wp80` amb `ForceCommand internal-sftp` (sense reenviaments ni TTY, sense chroot). Qualsevol comanda per SSH és refusada, de manera que `make fixtures-ssh SITE=wp80` no funciona; fes servir `sftp -i docker/fixtures/.keys/id_ed25519 -P 2225 -o UserKnownHostsFile=docker/fixtures/.keys/known_hosts sub_wp80@127.0.0.1`. La sembra i els snapshots entren amb `docker compose exec`, no per `sshd`, i continuen funcionant.
- **Límits de l'allotjament compartit a `wp80`:** `memory_limit=128M` i `max_execution_time=20`, aplicats només al web amb `php_admin_value` al vhost (variables `PHP_WEB_MEMORY_LIMIT` i `PHP_WEB_MAX_EXECUTION_TIME`). El PHP CLI no els té, perquè la sembra amb WP-CLI els necessita i els allotjaments compartits no tenen CLI.
- **`wp82` usa MySQL 8.0** (servei `db80`). Els usuaris de BD es creen amb `mysql_native_password`: el client MariaDB de les imatges dels llocs va amb `skip-ssl` i no pot fer l'autenticació completa de `caching_sha2_password` (per defecte a MySQL 8) sense TLS ni intercanvi de clau RSA.
- Els errors de PHP de tots els llocs (també `wp80` i `wp82`) van a `logs/php_error.log` de la subscripció.

Contingut de cada lloc (`seed.sh`):

- WordPress + WooCommerce amb versions fixades; EUR, país ES.
- Un producte simple i un de variable (2 variacions).
- Pagaments «contra reemborsament» i «xec» (cap passarel·la real) i una zona d'enviament amb tarifa plana.
- `classic-editor` 1.6.3 i `hello-dolly` 1.6 (versions antigues, per tenir actualitzacions pendents).
- `wpup-php-guard` (a `plugins/`): declara `Requires PHP: 8.2`. Només s'activa a `wp83`; a `wp74` i `wp81` queda inactiu (WordPress no el deixa activar).
- Administrador de WordPress `admin` / `fixture-admin-test-only`.

## Ús

```bash
make fixtures-up         # genera la clau si cal, construeix, aixeca, sembra i fa el snapshot (1a vegada: lent)
make fixtures-ssh SITE=wp81
make fixtures-reset      # torna tots els llocs a l'estat del snapshot (uns segons)
make fixtures-snapshot   # redefineix l'estat conegut amb l'estat actual
make fixtures-down       # atura (conserva volums i snapshots)
```

Exemple des de l'amfitrió:

```bash
ssh -i docker/fixtures/.keys/id_ed25519 -p 2222 -o UserKnownHostsFile=docker/fixtures/.keys/known_hosts \
    sub_wp81@127.0.0.1 'wp --path=/var/www/vhosts/wp81.fixtures.test/httpdocs plugin list'
```

- `make fixtures-keys` genera un parell ed25519 a `docker/fixtures/.keys/` (ignorat per git). Només la clau pública es munta als contenidors; no es desa a cap imatge.
- Els snapshots (volcat de BD + tarball del docroot, per lloc) són a `docker/fixtures/.snapshots/` (ignorat per git). `fixtures-reset` buida el docroot, el restaura i reimporta la BD.
- Les claus de host SSH es conserven en un volum; si elimines els volums (`docker compose -f docker/fixtures/compose.yaml down -v`), esborra també `.keys/known_hosts` i `.snapshots/`.
- WP-Cron està desactivat i no hi ha correu de sortida (`sendmail_path=/bin/true`).
- Els errors de PHP (web i CLI) van a `/var/www/vhosts/<lloc>.fixtures.test/logs/php_error.log`, com a Plesk. Hi ha `rsync`.
- Cada lloc té també l'usuari `sub_<lloc>_nosh`, amb la mateixa clau i el shell `/bin/false`, per als tests negatius del driver.
- El contenidor `app` i els llocs comparteixen la xarxa `uzero-wpup-fixtures-link` (la crea `make up`; la BD dels llocs no hi és): els tests d'integració (`tests/Integration`) hi arriben per nom (`wp74:22`…). Si els llocs no estan aixecats, aquests tests se salten, i el resum de `make ci` ho mostra.

## Fitxers

| Fitxer | Funció |
| --- | --- |
| `compose.yaml` | Serveis `db`, `db80`, `wp74`, `wp81`, `wp83`, `wp80`, `wp82` i versions fixades |
| `site/` | `Dockerfile` (arg `PHP_IMAGE`) i `entrypoint.sh` (usuari, sshd, Apache) |
| `db/init.sh` | Crea una BD i un usuari per lloc |
| `seed.sh <lloc>` | Sembra un lloc amb WP-CLI com a usuari de la subscripció (idempotent) |
| `snapshot.sh` | `snapshot` / `restore` / `has` |
| `plugins/wpup-php-guard/` | Plugin local que exigeix PHP 8.2 |

## Imatges fixades per digest

Les imatges base (`php:X.Y-apache`, `mariadb:10.11`, `mysql:8.0`) es fixen amb `tag@sha256:…` a `compose.yaml`, de manera que una reconstrucció no canvia res sense que ho decideixis. Per actualitzar un digest (índex multiarquitectura):

```bash
docker buildx imagetools inspect php:8.2-apache | head -3    # línia «Digest:»
# o, sense Docker:
TOKEN=$(curl -fsSL "https://auth.docker.io/token?service=registry.docker.io&scope=repository:library/php:pull" | jq -r .token)
curl -fsSI -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json' \
  https://registry-1.docker.io/v2/library/php/manifests/8.2-apache | grep -i docker-content-digest
```

Substitueix el digest a `compose.yaml` (`PHP_IMAGE`, `db` i `db80`) i reconstrueix amb `make fixtures-up`.

## Pendent (tasques de seguiment)

Vegeu UZEROWPUP-135: queden els dominis reals de prova (resolució DNS i certificats).
