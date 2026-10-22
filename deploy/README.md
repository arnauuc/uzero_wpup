# deploy/: configuració versionada del servidor de l'eina

Scripts de bash idempotents i fitxers de configuració per muntar la VM de l'eina (Debian 13 a Proxmox). Sense Ansible. Es llancen com a **root** a la VM, des d'una còpia del repositori que sigui de root (mai des de `/opt/uzero-wpup`).

**Aquí no hi ha cap secret, IP real ni nom de màquina real.** Les claus d'un sol ús (Tailscale) es demanen en temps d'execució i la KEK en brut només viu a la RAM.

## Runbooks

| Document | Per a què |
| --- | --- |
| [desplegament.md](../docs/runbooks/desplegament.md) | Primera instal·lació, pas a pas; el TPM al Proxmox; custòdia de la KEK; disseny de la CLI |
| [xarxa.md](../docs/runbooks/xarxa.md) | Tailscale, nftables, sshd, Serve; proves des de la tailnet, la LAN i Internet; accés d'emergència |
| [actualitzacio.md](../docs/runbooks/actualitzacio.md) | Desplegar una versió nova (`release.sh`) |
| [recuperacio.md](../docs/runbooks/recuperacio.md) | Desastre i prova anual |
| [rotacio-kek.md](../docs/runbooks/rotacio-kek.md) | Rotar la clau mestra |

## Scripts

| Fitxer | Què fa |
| --- | --- |
| `provision.sh` | Executa els passos de `steps/` en ordre (`--list`, `--only NN`, `--from NN`) |
| `release.sh <etiqueta>` | Desplega el codi a `/opt/uzero-wpup`, migra i reinicia (instal·lació i actualitzacions) |
| `steps/00-preflight.sh` | Root, Debian 13, `/dev/tpmrm0` |
| `steps/10-packages.sh` | PHP 8.4 i extensions, PostgreSQL 17, Redis, nginx, rsync, Node... |
| `steps/20-tpm.sh` | `systemd-creds has-tpm2` i prova de segellat `tpm2+host` |
| `steps/30-users.sh` | Usuari `wpup`, grups, `/opt/uzero-wpup` (0750) |
| `steps/40-tailscale.sh` ... `44-docker-guard.sh` | Xarxa (UZEROWPUP-126): Tailscale, nftables, sshd, Serve, Docker |
| `steps/50-postgres.sh` | PostgreSQL per socket + `peer`, rols `wpup_owner` i `wpup_app` |
| `steps/51-redis.sh` | Redis per socket, sense persistència |
| `steps/52-kek.sh` | Genera i segella la KEK (`WPUP_RECOVERY=1` per recuperar-la del KeePassXC) |
| `steps/53-cli.sh` | `/usr/local/bin/wpup`, el helper `wpup-cli` i el sudoers |
| `steps/54-web.sh` | PHP-FPM, nginx a `127.0.0.1` i `.env` de producció |
| `steps/55-systemd.sh` | Unitats de Horizon i del scheduler, amb el drop-in de la KEK |
| `steps/56-docker.sh` | Docker (per al staging de fases futures) |
| `bin/add-operator.sh` | Crea un operador (Unix + clau SSH, sense res més) |
| `bin/kek-shred.sh` | Destrueix la còpia en brut de la KEK un cop guardada al KeePassXC |
| `bin/wpup-kek-units` | Genera el drop-in `LoadCredentialEncrypted=` de totes les versions de la KEK |
| `checks/server.sh` | TPM, KEK, permisos, serveis i que els operadors no llegeixen res |
| `checks/network.sh` | Xarxa dins de la VM (Funnel, *exit node*, sockets, Docker, IP de sortida) |
| `checks/reachability.sh` | Des d'una altra màquina: que el 22 i el 443 responen per la tailnet i no per la LAN ni Internet |

`files/` conté les plantilles i la configuració que s'instal·len (nftables, sshd, systemd, sudoers, PostgreSQL, Redis, PHP, nginx, `.env`, journald, Docker, Tailscale). `lib/common.sh` té les funcions compartides. `config.env.example` mostra les variables no secretes que es poden canviar (`deploy/config.env` no es versiona).

## Ordre dels passos

`00 → 10 → 20 → 30` (base i TPM), `40 → 44` (xarxa, des de la tailnet), `50 → 51` (BD i Redis), `52` (KEK + custòdia), `53 → 56` (CLI, web, serveis, Docker), i després `release.sh`. El detall i els avisos de cada pas són als runbooks.

## Qualitat

```bash
make deploy-lint     # bash -n i shellcheck (si és instal·lat) sobre deploy/**/*.sh i els scripts sense extensió
```

L'objectiu **no** forma part de `make ci` encara. Cap d'aquests scripts no es prova a Docker: es validen a la VM real (vegeu les notes de les tasques UZEROWPUP-126 i UZEROWPUP-28).
