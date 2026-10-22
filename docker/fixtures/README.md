# Llocs WordPress/WooCommerce de prova

Flota de llocs de prova per als tests d'integració (SshDriver, inventari, còpies, actualitzacions…). Projecte Compose **separat** del de desenvolupament: `uzero-wpup-fixtures` (`docker/fixtures/compose.yaml`).

> **Tot és d'usar i llençar.** Les credencials d'aquest directori (BD, administrador de WordPress, clau SSH) són valors de prova que només existeixen aquí. No es reutilitzen enlloc més ni apunten a cap lloc real. Mai hi posis dades reals.

## Llocs

| Lloc | PHP | WordPress | WooCommerce | HTTP | SSH | Usuari SSH |
| --- | --- | --- | --- | --- | --- | --- |
| `wp74` | 7.4 | 6.6.2 | 9.3.3 | `http://127.0.0.1:8081` | `127.0.0.1:2221` | `sub_wp74` |
| `wp81` | 8.1 | 6.7.2 | 9.6.2 | `http://127.0.0.1:8082` | `127.0.0.1:2222` | `sub_wp81` |
| `wp83` | 8.3 | 6.8.1 | 9.8.5 | `http://127.0.0.1:8083` | `127.0.0.1:2223` | `sub_wp83` |

Tots els ports es publiquen **només a `127.0.0.1`** (ADR-0003). La BD (MariaDB 10.11) només és a la xarxa interna `fixtures`.

Cada lloc imita una subscripció de Plesk: usuari de sistema no root (`sub_<lloc>`, shell `/bin/bash`), docroot `/var/www/vhosts/<lloc>.fixtures.test/httpdocs`, Apache servint com aquest usuari, i SSH només amb clau (`PasswordAuthentication no`, `PermitRootLogin no`).

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
| `compose.yaml` | Serveis `db`, `wp74`, `wp81`, `wp83` i versions fixades |
| `site/` | `Dockerfile` (arg `PHP_VERSION`) i `entrypoint.sh` (usuari, sshd, Apache) |
| `db/init.sh` | Crea una BD i un usuari per lloc |
| `seed.sh <lloc>` | Sembra un lloc amb WP-CLI com a usuari de la subscripció (idempotent) |
| `snapshot.sh` | `snapshot` / `restore` / `has` |
| `plugins/wpup-php-guard/` | Plugin local que exigeix PHP 8.2 |

## Pendent (tasques de seguiment)

Vegeu UZEROWPUP-135: lloc només SFTP, límits d'allotjament compartit, MySQL 8.0, dominis reals i imatges fixades per digest.
