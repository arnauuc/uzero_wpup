# ADR-0002: Gestió de secrets i accés als servidors

**Estat:** Proposat
**Data:** 2026-10-21
**Decideixen:** Arnau
**Tasca:** UZEROWPUP-122

## Context

L'eina ha de guardar credencials que donen accés a tota la flota:

- **Claus SSH privades** per entrar als servidors Plesk.
- **Contrasenyes SFTP** dels hostings compartits que no admeten claus.
- **Credencials de les destinacions de còpia** (SFTP, S3, Dropbox, rclone) i de **Quipu**.
- **Secrets HMAC** de l'agent, un per execució.

Restriccions i fets:

- **Plesk:** cada lloc té el seu usuari de sistema, però un subscriptor pot tenir diversos llocs que comparteixen el mateix usuari. Mai es fa servir `root`.
- **Servidor de l'eina:** una VM a Proxmox. Per defecte no té TPM, tot i que Proxmox permet afegir-li un TPM 2.0 virtual.
- **Operadors:** diverses persones fan servir l'eina, cadascuna amb el seu usuari Unix, i cap d'elles ha de poder llegir els secrets.
- **El forat del pla inicial:** credencials xifrades a la BD amb l'`APP_KEY` del `.env`. Si els operadors executen `php artisan` amb el seu usuari, han de poder llegir el `.env` i, per tant, poden desxifrar-ho tot. A més, un dump de la BD i el `.env` junts ho exposen tot.

## Decisió

### 1. Un únic usuari de servei i execució via sudo

- Tota l'eina corre amb l'usuari de sistema `wpup`: CLI, workers d'Horizon i scheduler. És l'únic que pot llegir el `.env`, la clau mestra, el directori de l'aplicació i la BD.
- El binari que fan servir els operadors (`/usr/local/bin/wpup`) és un embolcall que crida `sudo -u wpup /opt/uzero-wpup/wpup "$@"`. La regla de sudoers només permet aquella comanda exacta i no conserva l'entorn (`env_reset`).
- La identitat de l'operador es llegeix de `SUDO_USER`, que fixa `sudo` i no es pot falsificar, i es mapa a `users`. Si un usuari Unix no està mapat, no pot fer res.
- Els operadors no tenen accés de lectura a `/opt/uzero-wpup`, al `.env`, a les credencials de systemd ni a la BD.

### 2. Xifratge en sobre amb una clau mestra fora de la BD i del `.env`

- Cada secret es xifra amb una clau de dades pròpia (*DEK*, aleatòria, XChaCha20-Poly1305 amb libsodium). La DEK es xifra amb la **clau mestra** (*KEK*). A la BD només hi ha el text xifrat, la DEK xifrada, l'identificador de la versió de la KEK i les metadades.
- La KEK es guarda com a credencial de systemd (`systemd-creds encrypt --with-key=auto`). Si la VM té TPM (virtual o físic), la credencial queda lligada al TPM i a la clau del host; si no en té, queda protegida per la clau del host (`/var/lib/systemd/credential.secret`, només llegible per root). systemd la desxifra i l'entrega només als serveis de l'usuari `wpup` (`LoadCredentialEncrypted=`). Per a la CLI via sudo, s'obté amb un helper restringit.
- L'`APP_KEY` de Laravel queda per a les sessions i les cookies del visor; no xifra cap credencial de llocs.
- **Còpia de la KEK:** fora del servidor, al gestor de contrasenyes de l'empresa, amb accés limitat als `admin`. Les còpies diàries de la BD de l'eina només contenen text xifrat. Si es perd la KEK, es perden totes les credencials.
- **Millora recomanada:** afegir un TPM 2.0 virtual a la VM de Proxmox. Lliga la KEK a aquella VM i fa que una còpia del disc no sigui suficient per desxifrar-la.

### 3. Identitats d'accés per compte d'hosting, no per lloc

- Una entitat nova, `hosting_accounts` (servidor + usuari de sistema de la subscripció de Plesk), agrupa els llocs que comparteixen usuari. Cada lloc apunta al seu compte d'hosting.
- Les credencials (clau SSH, contrasenya SFTP, host key fixada) pertanyen al compte d'hosting. Així es manté una sola clau per subscripció, com a Plesk.
- **Concurrència:** cal un lock per compte d'hosting, a més del lock per lloc, perquè dues execucions de llocs de la mateixa subscripció no es trepitgin (quota de disc, memòria cau compartida, `.maintenance`).

### 4. Claus SSH

- Ed25519, una per compte d'hosting, generades per l'eina (`wpup key:generate`). La privada no surt mai del servidor de l'eina i no es mostra mai.
- La pública s'instal·la a l'`authorized_keys` de l'usuari de la subscripció, amb restriccions: `from="<IP de l'eina>",no-port-forwarding,no-agent-forwarding,no-X11-forwarding`.
- La host key de cada servidor es fixa en la primera connexió, amb confirmació de l'admin. Si després canvia, la connexió falla (`StrictHostKeyChecking yes` amb un `known_hosts` propi de l'eina).
- Rotació: `wpup key:rotate <compte>` genera una clau nova, la instal·la, en comprova l'accés i retira l'antiga. Recomanada anualment o quan marxa algú amb accés de root a la VM.

### 5. Ús dels secrets en temps d'execució

- **Comandes SSH i SFTP:** amb phpseclib, amb la clau només en memòria.
- **rsync:** necessita el binari `ssh`. Per a cada feina s'arrenca un `ssh-agent` propi amb un socket en un directori privat (0700) i s'hi afegeix la clau amb `ssh-add -t <TTL>` des de l'entrada estàndard. L'agent es mata en un `finally`. Mai s'escriu cap fitxer de clau en clar.
- Els secrets es desxifren tan tard com sigui possible i es netegen de memòria (`sodium_memzero`) en acabar.
- Cada desxifratge registra a `audit_log` qui, quin secret, per a quina execució i quan.
- El `SecretRedactor` coneix els secrets actius de l'execució i els emmascara a tota sortida.

### 6. Contrasenyes SFTP i altres credencials

- Segueixen el mateix camí (xifratge en sobre i auditoria). Es fan servir només quan l'hosting no admet claus; `site:test` avisa si es fa servir una contrasenya on s'hi podria posar una clau.
- Introducció interactiva sense eco (`secret()`), mai com a argument de la línia de comandes, perquè quedaria a l'historial i a `ps`.

### 7. Rotació de la clau mestra

- `wpup secrets:rekey` crea una KEK nova i torna a xifrar totes les DEK. És idempotent i reprenible, i les DEK antigues continuen llegibles fins que acaba. Només `admin`.

## Opcions considerades

### Opció A: xifratge de Laravel amb l'`APP_KEY` (pla inicial)

| Dimensió | Valoració |
| --- | --- |
| Complexitat | Baixa |
| Seguretat | Feble: l'`APP_KEY` és al `.env`, que han de poder llegir els operadors |
| Operació | Simple |

**En contra:** qualsevol operador o qualsevol dump de BD + `.env` ho exposa tot.

### Opció B: xifratge en sobre + `systemd-creds` + execució via sudo (triada)

| Dimensió | Valoració |
| --- | --- |
| Complexitat | Mitjana |
| Seguretat | Bona: els operadors no veuen res, i la BD i les còpies no són suficients per desxifrar |
| Operació | Cal custodiar la KEK fora del servidor |

### Opció C: HashiCorp Vault / OpenBao

| Dimensió | Valoració |
| --- | --- |
| Complexitat | Alta (un servei més: desbloqueig, còpies, alta disponibilitat) |
| Seguretat | Molt bona (motor transit, auditoria pròpia, leases) |
| Operació | Desproporcionada per a una sola VM i un equip petit |

**Es pot reconsiderar** si l'eina passa a tenir diversos servidors o si l'empresa adopta Vault per a altres serveis. La interfície interna (`SecretStore`) ha de permetre el canvi.

## Anàlisi de compromisos

El risc principal no és un atacant extern a la BD, sinó l'abast de cada credencial i qui hi té accés dins del servidor. Executar-ho tot com a `wpup` via sudo elimina l'accés dels operadors als secrets i, a més, dona una identitat fiable per a l'auditoria. El xifratge en sobre amb la KEK fora de la BD fa que les còpies de l'eina no siguin un risc. Vault aportaria poc més a canvi de molta operació.

## Conseqüències

- **Més fàcil:** donar i treure accés a operadors (un usuari Unix i una línia a `users`), auditar l'accés a credencials i rotar les claus.
- **Més difícil:** la instal·lació del servidor (sudoers, systemd-creds, permisos) i la recuperació en cas de desastre, que depèn de tenir la KEK custodiada.
- **Canvis al model de dades:** la taula nova `hosting_accounts`, i les credencials passen dels llocs als comptes d'hosting.
- **Cal comprovar a F1:** que els usuaris de subscripció de Plesk tenen shell (no `/bin/false`) i que, si és un shell *chrooted*, s'hi poden fer servir els binaris de `/opt/plesk/php/X.Y/bin/php` i WP-CLI. Si no, cal una alternativa (shell no chrooted per a aquells usuaris, o l'agent).
- **Caldrà revisar:** l'adopció de Vault si l'eina creix, i el vTPM a Proxmox.

## Accions

1. [ ] UZEROWPUP-122 Revisar i acceptar aquest ADR
2. [ ] UZEROWPUP-15 Xifratge en sobre (`SecretStore`) amb KEK a systemd-creds
3. [ ] UZEROWPUP-16 Identitat per `SUDO_USER` i embolcall `wpup` via sudo
4. [ ] UZEROWPUP-13 Model `hosting_accounts`
5. [ ] UZEROWPUP-28 Desplegament: usuari `wpup`, sudoers, systemd-creds, permisos i custòdia de la KEK
6. [ ] UZEROWPUP-123 Comandes `key:generate`, `key:rotate` i `secrets:rekey`
7. [ ] UZEROWPUP-124 Lock per compte d'hosting
