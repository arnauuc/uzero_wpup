# Runbook: desplegament del servidor de l'eina (VM a Proxmox)

Primera instal·lació, pas a pas (UZEROWPUP-28). Implementa els ADR [0002](../adr/0002-gestio-de-secrets.md) (secrets), [0003](../adr/0003-xarxa-i-acces.md) (xarxa) i [0004](../adr/0004-base-de-dades.md) (base de dades). Els scripts són a `deploy/` (índex a [deploy/README.md](../../deploy/README.md)) i es poden tornar a executar sense risc.

Altres runbooks: [xarxa](xarxa.md), [actualització](actualitzacio.md), [recuperació](recuperacio.md), [rotació de la KEK](rotacio-kek.md).

> **Cap secret al repositori.** Res d'aquest runbook ni dels scripts conté claus, IPs reals ni noms de màquina reals. Les claus d'un sol ús (Tailscale) i la KEK en brut només passen per la memòria i per la RAM (`/dev/shm`).

## Què es munta

| Peça | Detall |
| --- | --- |
| VM | Debian 13, UEFI (OVMF), TPM 2.0 virtual (`tpmstate0`, v2.0) |
| Usuari de servei | `wpup`: és propietari de `/opt/uzero-wpup` (0750), del `.env` (0600) i de la BD |
| Operadors | Un usuari Unix per persona, al grup `wpup-operators` o `wpup-admins` i a cap altre. No poden llegir res de l'anterior |
| Base de dades | PostgreSQL 17, només socket Unix, autenticació `peer`, rols `wpup_owner` (migracions) i `wpup_app` (aplicació) |
| Redis | Només socket Unix, sense persistència |
| Web | nginx a `127.0.0.1:8080` + PHP-FPM com a `wpup`; Tailscale Serve el publica a la tailnet |
| Serveis | `wpup-horizon.service` (workers) i `wpup-scheduler.service` (`schedule:work`) |
| PHP | 8.4 amb `pgsql`, `redis`, `sodium`, `pcntl`, `posix`, `intl`, `zip`, `gd` |
| Altres | `rsync`, `openssh-client`, Docker (per al staging de fases futures), `make tools` (WP-CLI fixat) |

## Seguretat dels scripts

- Els scripts de `deploy/` els executa **root des d'una còpia que és de root** (`/usr/local/src/uzero-wpup`), mai des de `/opt/uzero-wpup`, que és d'`wpup`: si no, l'usuari de servei podria canviar el que root executarà. `deploy/release.sh` manté aquesta còpia.
- El repositori privat, si ho és, es clona amb les credencials de root (p. ex. una *deploy key* de només lectura a `/root/.ssh`). L'usuari `wpup` no té accés a Git.

## 0. Proxmox: la VM amb TPM (abans de res)

El TPM s'ha d'afegir **abans de crear la KEK** (ADR-0002): la KEK queda lligada a aquell TPM. Aquestes ordres són documentació; les executa l'administrador del Proxmox (a la màquina que correspongui; aquí no es llancen mai des de l'eina).

```bash
# Exemple per a una VM nova amb id <VMID>, emmagatzematge <STORAGE>:
qm create <VMID> --name wpup --memory 8192 --cores 4 --machine q35 --bios ovmf \
  --scsihw virtio-scsi-single --scsi0 <STORAGE>:64 --net0 virtio,bridge=vmbr0 \
  --ostype l26 --cdrom <ISO_DEBIAN_13>
qm set <VMID> --efidisk0 <STORAGE>:1,efitype=4m,pre-enrolled-keys=0
qm set <VMID> --tpmstate0 <STORAGE>:1,version=v2.0
```

Si la VM ja existeix amb BIOS SeaBIOS, cal canviar-la a `--bios ovmf` i `--machine q35`; això obliga a reinstal·lar l'arrencada. És més senzill crear la VM bé des del principi.

Comprova-ho al Proxmox:

```bash
qm config <VMID> | grep -E 'bios|machine|efidisk0|tpmstate0'
# bios: ovmf / machine: q35 / efidisk0: ... / tpmstate0: <STORAGE>:vm-<VMID>-disk-N,size=4M,version=v2.0
```

I a la VM, després d'instal·lar Debian 13: `ls -l /dev/tpmrm0`. El desplegament ho comprova als passos `00` i `20` i s'atura si falla.

### Les còpies de Proxmox (vzdump) inclouen el TPM

L'ADR-0002 necessita que el disc `tpmstate0` entri a les còpies de la VM. Comprova-ho:

```bash
# 1) El disc no ha de dur backup=0:
qm config <VMID> | grep tpmstate0        # no ha de contenir "backup=0"

# 2) Després d'una còpia, mira què conté l'arxiu (format VMA):
zstd -dc /var/lib/vz/dump/vzdump-qemu-<VMID>-<DATA>.vma.zst > /tmp/wpup.vma
vma list /tmp/wpup.vma | grep -i tpmstate     # ha de sortir drive-tpmstate0-backup
rm /tmp/wpup.vma
```

Si fas servir Proxmox Backup Server, mira que el *snapshot* tingui el fitxer `drive-tpmstate0-backup.img.fidx`. **Si no hi és**, restaurar la VM no recupera la KEK i només queda el camí de la KEK en brut ([recuperació](recuperacio.md)). Repeteix aquesta comprovació després de qualsevol canvi a la configuració de les còpies.

## 1. Preparar el servidor

Com a root a la VM, per la consola de Proxmox o per SSH inicial:

1. **Clona el repositori a la còpia de root** (a la versió que vols instal·lar):

   ```bash
   git clone <URL_DEL_REPOSITORI> /usr/local/src/uzero-wpup
   cd /usr/local/src/uzero-wpup
   git checkout --detach <ETIQUETA>
   ```

2. **Configuració opcional** (no secreta): `cp deploy/config.env.example deploy/config.env` i canvia el que calgui (noms dels grups, ruta, port).
3. **Comprovacions prèvies, paquets i TPM:**

   ```bash
   ./deploy/provision.sh --only 00     # root, Debian 13, /dev/tpmrm0
   ./deploy/provision.sh --only 10     # PHP 8.4, PostgreSQL 17, Redis, nginx, Node...
   ./deploy/provision.sh --only 20     # systemd-creds has-tpm2 i una prova de segellat
   ```

   **Si el pas 00 o el 20 falla, no continuïs**: arregla el TPM al Proxmox.
4. **Usuaris:**

   ```bash
   ./deploy/provision.sh --only 30
   ./deploy/bin/add-operator.sh <USUARI_UNIX> <FITXER_CLAU_PUBLICA> --admin   # el primer, com a admin
   ./deploy/bin/add-operator.sh <ALTRE_USUARI> <FITXER_CLAU_PUBLICA>          # els altres
   ```

   Cada operador és al seu grup i a cap més, sense contrasenya (només clau SSH).

## 2. Xarxa (UZEROWPUP-126)

Segueix el [runbook de xarxa](xarxa.md): passos `40` (Tailscale amb la clau d'un sol ús, que es demana per terminal i no es desa), `41` (nftables), `42` (sshd), `43` (Tailscale Serve) i `44` (Docker). **Entra per la tailnet abans de tancar el tallafocs.** A partir d'aquí, continua per SSH a través de la tailnet.

## 3. Base de dades i Redis

```bash
./deploy/provision.sh --only 50     # PostgreSQL: socket, peer, rols wpup_owner i wpup_app
./deploy/provision.sh --only 51     # Redis: socket Unix, sense persistència
```

El pas `50` comprova que `wpup` entra com a `wpup_app` per `peer` i que no hi ha cap escolta TCP al 5432. L'auditoria immutable (`REVOKE` + *trigger* a `audit_log`) la creen les migracions, executades com a `wpup_owner` (pas 6).

> El mapatge `peer` deixa que l'usuari `wpup` pugui entrar també com a `wpup_owner`: l'ADR-0004 ho accepta (l'ús del propietari és només per migrar i tot el DDL queda registrat per `log_statement = 'ddl'`).

## 4. La KEK: generar-la, segellar-la i custodiar-la

```bash
./deploy/provision.sh --only 52
```

El pas torna a comprovar `/dev/tpmrm0` i `systemd-creds has-tpm2`, genera 32 bytes aleatoris en base64 **només a la RAM** (`/dev/shm/wpup-kek-v1.txt`, 0600) i els segella amb:

```bash
systemd-creds encrypt --with-key=tpm2+host --name=wpup-kek-v1 /dev/shm/wpup-kek-v1.txt /etc/credstore.encrypted/wpup-kek-v1
```

Mai amb `--with-key=auto`: si el TPM falla, la comanda falla, no es degrada en silenci. Després comprova que el segellat obre i dona el mateix valor.

### Custòdia (Arnau, el custodi)

1. Des de la teva sessió SSH de la tailnet: `sudo cat /dev/shm/wpup-kek-v1.txt` (44 caràcters en base64).
2. Al **teu KeePassXC personal** (no a cap base compartida), crea una entrada nova «wpup KEK v1» i enganxa-hi la clau al camp de contrasenya. Desa la base. Comprova que les dues còpies del fitxer de KeePassXC estan actualitzades (ADR-0002, punt 2c).
3. **Comprova que s'ha copiat bé:** torna a copiar el valor de KeePassXC i compara'n l'empremta, sense mostrar-lo:

   ```bash
   # Al servidor: empremta de la còpia en brut
   sudo sha256sum /dev/shm/wpup-kek-v1.txt
   # Al teu portàtil: enganxa el valor i compara
   printf '%s' "<VALOR_DEL_KEEPASSXC>" | sha256sum
   ```
4. **Destrueix la còpia en brut:**

   ```bash
   sudo ./deploy/bin/kek-shred.sh 1     # demana escriure «guardada»
   ```

   Fa `shred -u` del fitxer. Com que és a la RAM, tampoc no sobreviu a un reinici. Si no has pogut guardar-la i el servidor es reinicia, la KEK segellada continua existint (root la pot tornar a mostrar amb `systemd-creds decrypt --name=wpup-kek-v1 /etc/credstore.encrypted/wpup-kek-v1 -`); només s'ha de fer abans de posar secrets a l'eina.

Fins que el custodi no l'ha guardada, **no s'ha de carregar cap credencial a l'eina**.

## 5. CLI per als operadors, serveis i web

```bash
./deploy/provision.sh --only 53     # /usr/local/bin/wpup, helper, sudoers
./deploy/provision.sh --only 54     # PHP-FPM, nginx a 127.0.0.1, .env de producció
./deploy/provision.sh --only 55     # unitats de systemd de Horizon i del scheduler
./deploy/provision.sh --only 56     # Docker (opcional, fases de staging)
```

El pas `54` crea el `.env` només si no existeix (`WPUP_APP_URL=https://<node>.<tailnet>.ts.net` per posar `APP_URL`). Omple `WPUP_EGRESS_IP` a mà (l'IP pública fixa de sortida; vegeu el runbook de xarxa).

## 6. Instal·lar l'aplicació

```bash
./deploy/release.sh <ETIQUETA>
```

Copia el codi a `/opt/uzero-wpup`, fa `composer install --no-dev`, compila els recursos, descarrega el WP-CLI fixat (`make tools`), genera l'`APP_KEY` si falta, executa les migracions com a `wpup_owner` i engega Horizon i el scheduler. Detalls al [runbook d'actualització](actualitzacio.md).

Primer administrador (una sola vegada):

```bash
sudo -u wpup php /opt/uzero-wpup/artisan user:bootstrap <USUARI_UNIX_DE_L_ADMIN> --name="<NOM>"
```

## 7. Comprovar-ho tot

```bash
sudo ./deploy/checks/server.sh <OPERADOR_1> <OPERADOR_2>   # TPM, KEK, permisos, serveis, i que els operadors no llegeixen res
sudo ./deploy/checks/network.sh                             # xarxa (runbook de xarxa)
```

I com a operador (no root), des d'una sessió SSH normal:

```bash
wpup list                      # funciona i et mostra les comandes permeses
wpup user:list                 # llegeix la BD amb la teva identitat (SUDO_USER)
wpup site:test <LLOC_DE_PROVA> # prova una connexió (només amb un lloc autoritzat)
sudo -n true                   # ha de FALLAR: l'operador no té sudo general
ls /opt/uzero-wpup             # ha de FALLAR (permís denegat)
```

## CLI i KEK: el disseny triat

**El problema.** La KEK és una credencial de systemd segellada amb el TPM i la clau del host. Només el gestor de serveis (root) la pot desxifrar, i només la lliura a les unitats amb `LoadCredentialEncrypted=`. Un `sudo -u wpup wpup …` simple arrenca la CLI **sense** `$CREDENTIALS_DIRECTORY`, i no podria llegir cap secret.

**Opcions valorades.**

| Opció | Com funciona | Pros | Contres |
| --- | --- | --- | --- |
| A. `sudo -u wpup` + KEK en un fitxer llegible per `wpup` | La KEK en clar a disc | Simple | Trenca l'ADR-0002: KEK en clar a disc |
| B. `systemd-run` directe per l'operador | L'operador crida `systemd-run -p User=wpup -p LoadCredentialEncrypted=…` | Cap script propi | Cal que l'operador pugui parlar amb el gestor de serveis (polkit) i triar les propietats: podria arrencar un servei com a `wpup` amb qualsevol ordre. Massa permisos |
| **C. Helper d'arrel amb `systemd-run` (triada)** | `wpup` → `sudo /usr/local/libexec/wpup-cli` → `systemd-run --pty/--pipe --wait -p User=wpup -p LoadCredentialEncrypted=… -- /opt/uzero-wpup/wpup "$@"` | La KEK arriba per `$CREDENTIALS_DIRECTORY` com a Horizon; la CLI corre com a `wpup` (mai root) i amb el mateix *sandbox* que les unitats; un sol fitxer de root, curt i auditable; `SUDO_USER` arriba intacte | Hi ha un fitxer d'arrel que s'executa amb `sudo` (superfície a vigilar); s'han de mantenir sincronitzades les opcions de seguretat amb les unitats |

**Què és exactament la regla de sudoers.** L'ADR-0002 deia `sudo -u wpup /opt/uzero-wpup/wpup`. Amb l'opció C la regla és `%wpup-operators,%wpup-admins ALL=(root) NOPASSWD: /usr/local/libexec/wpup-cli *`, amb `env_reset` i `!setenv`. Continua sent **una sola ordre permesa**, però s'executa com a root i *el helper* és qui baixa a `wpup`. La llista de comandes de l'eina (`allowed` a `/opt/uzero-wpup/wpup` i `wpup.cli.allowed_commands`) continua fent de segona barrera: `tinker`, `migrate`, `key:generate`, etc. no són accessibles.

**Garanties del helper.** Comprova que és root i que ve de `sudo` (`SUDO_USER` definit, no root, i membre d'un dels dos grups); carrega totes les versions de la KEK presents a `/etc/credstore.encrypted` (important durant una rotació); usa `--pty` si hi ha terminal (per als `secret()` sense eco) i `--pipe` si no; registra l'operador i la comanda (només el primer argument) a `journald` amb la facilitat `auth`; passa `SUDO_USER` a l'entorn del servei; i no hi ha cap contrasenya ni secret a la línia de comandes (l'eina no n'accepta, ADR-0002 punt 6).

## Notes i decisions

- **PHP-FPM no rep la KEK.** El panell web no desxifra mai credencials (AGENTS.md, «mai pel web»); tot el que les necessita corre a Horizon o a la CLI. Si el panell necessités mai la KEK, caldria un `ADR` i `LoadCredentialEncrypted=` al servei de PHP-FPM.
- **nginx corre com a `wpup`** perquè pugui llegir `/opt/uzero-wpup` (0750) sense obrir el directori a cap altre usuari.
- **Redis sense persistència.** Una reinici buida cues i locks; els locks són *leases* que caduquen i `engine:recover` reprèn o reverteix les execucions interrompudes (ADR-0006).
- **`known_hosts` propi de l'eina.** L'eina fixa les *host keys* dels servidors a la seva BD i fa servir el seu propi `known_hosts` amb `StrictHostKeyChecking yes` (ADR-0002, punt 4): no cal gestionar res a mà, i no es toca el `~/.ssh/known_hosts` de ningú.
- **`/tmp` privat.** Les unitats tenen `PrivateTmp=yes`: els sockets dels `ssh-agent` d'`rsync` viuen allà (directoris 0700). A Debian 13 `/tmp` és un `tmpfs` (RAM): els sockets hi caben de sobra, però vigila que cap tasca nova hi deixi fitxers grans (verifica-ho amb les tasques de còpies, ADR-0009).
- **La retenció del registre de sortida** (90 dies) i el seu límit de mida són al runbook de xarxa.
