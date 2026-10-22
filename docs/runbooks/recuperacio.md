# Runbook: recuperació en cas de desastre

Implementa l'[ADR-0002](../adr/0002-gestio-de-secrets.md), punt 2b: com recuperar l'eina si es perd la VM. També és el procediment de la **prova anual** (UZEROWPUP-28).

## Què es recupera i de què depèn

| Cal | On és | Qui el té |
| --- | --- | --- |
| Base de dades de l'eina (xifrada amb la KEK) | Còpia diària de `pg_dump` (ADR-0004, ADR-0009) a la destinació de còpies | Equip |
| **KEK en brut** (32 bytes, base64) | Entrada del KeePassXC del custodi | **Només Arnau** |
| El codi | El repositori Git (etiqueta) | Equip |
| Configuració del servidor | `deploy/` al repositori | Equip |
| IP pública de sortida i la tailnet | Xarxa de Proxmox, compte de Tailscale | Administrador de xarxa |

El fitxer segellat per `systemd-creds` **no serveix** en una VM nova: només s'obre amb el TPM i la clau del host de la VM original. Per això es guarda la KEK en brut. Si la VM es restaura des d'una còpia de Proxmox **amb** `tpmstate0` (vegeu [desplegament](desplegament.md)), el segellat continua obrint-se i no cal re-segellar res; aquest runbook és per al cas en què això no és possible.

Les còpies de les webs (dumps i arxius de WordPress) no estan xifrades amb la KEK i són accessibles directament des de la destinació, fins i tot sense l'eina.

## Procediment

Es fa a una **VM nova amb un TPM virtual nou** (vegeu la secció «Proxmox» del [desplegament](desplegament.md)). Tingues a mà: l'etiqueta de l'eina, la còpia de la BD, el KeePassXC del custodi i una clau d'autenticació de Tailscale d'un sol ús.

1. **Crea la VM** (Debian 13, OVMF, `tpmstate0` v2.0) i comprova `ls -l /dev/tpmrm0`.
2. **Prepara el servidor** com a la primera instal·lació, **sense** generar cap KEK:

   ```bash
   git clone <URL_DEL_REPOSITORI> /usr/local/src/uzero-wpup && cd /usr/local/src/uzero-wpup
   git checkout --detach <ETIQUETA>
   ./deploy/provision.sh --only 00
   ./deploy/provision.sh --only 10
   ./deploy/provision.sh --only 20
   ./deploy/provision.sh --only 30
   ./deploy/bin/add-operator.sh <USUARI_UNIX> <FITXER_CLAU_PUBLICA> --admin
   ```
3. **Xarxa** (runbook de [xarxa](xarxa.md)): passos `40` a `44`. Si el node antic de Tailscale encara apareix a l'administració, esborra'l. Comprova l'**IP de sortida** (`network.sh`): ha de ser la mateixa, perquè les claus SSH dels servidors Plesk porten `from="<IP>"`. Si no ho és, caldrà tornar a instal·lar les claus (`ssh-key:rotate`).
4. **BD i Redis:** `./deploy/provision.sh --only 50` i `--only 51`.
5. **Torna a segellar la KEK** amb el TPM nou, en mode recuperació:

   ```bash
   WPUP_RECOVERY=1 ./deploy/provision.sh --only 52
   ```

   Demana la KEK en brut sense eco (el custodi la copia del KeePassXC). Comprova que té 44 caràcters, que és base64 de 32 bytes, la segella amb `--with-key=tpm2+host`, comprova que s'obre i **destrueix la còpia en brut**. Si la BD restaurada encara fa servir versions antigues de la KEK (després d'una rotació, vegeu [rotacio-kek](rotacio-kek.md)), repeteix amb `WPUP_KEK_VERSION=<n>` per a cada versió que calgui.
6. **CLI, web i serveis:** `./deploy/provision.sh --only 53`, `--only 54`, `--only 55`. Omple `WPUP_EGRESS_IP` al `.env` i posa `WPUP_KEK_VERSION` a la versió vigent.
7. **Restaura la BD de l'eina.** Primer el codi (crea l'esquema), després les dades:

   ```bash
   ./deploy/release.sh <ETIQUETA>                 # instal·la el codi; les migracions deixen l'esquema buit
   systemctl stop wpup-horizon wpup-scheduler
   runuser -u postgres -- dropdb wpup
   runuser -u postgres -- psql -X -d postgres -f - < deploy/files/postgres/roles.sql   # recrea la BD buida amb els permisos per defecte
   runuser -u postgres -- pg_restore --no-owner --role=wpup_owner -d wpup <FITXER_DUMP>
   ```

   (El dump és el `pg_dump -Fc` de la còpia diària de l'eina; copia'l a la VM per `scp` des de la destinació de còpies, per la tailnet.) Si el dump és d'una versió de l'eina anterior a l'etiqueta, torna a executar `./deploy/release.sh <ETIQUETA>` perquè apliqui les migracions pendents.
8. **Arrenca i comprova** (a la VM de la prova anual **no** arrenquis els serveis: vegeu més avall):

   ```bash
   systemctl start wpup-horizon wpup-scheduler
   sudo ./deploy/checks/server.sh <OPERADORS>
   sudo ./deploy/checks/network.sh
   ```
9. **Prova final, el que demostra la recuperació:** com a operador,

   ```bash
   wpup user:list              # la BD és la restaurada
   wpup site:test <LLOC>       # desxifra les credencials del lloc i hi connecta: ha de sortir OK
   ```

   Si `site:test` falla amb un error de KEK o de desxifratge, la KEK que has posat no és la de la BD (o és d'una versió diferent): no continuïs, revisa el KeePassXC.
10. **Tanca:** registra la recuperació (data, qui, versió de l'etiqueta, durada) i, si és una recuperació real, programa `ssh-key:rotate` per a tots els comptes (la VM antiga ja no és de fiar) i, si algú amb accés a la VM antiga ha marxat, [rota la KEK](rotacio-kek.md).

## Si la KEK s'ha perdut

Si no hi ha còpia al KeePassXC i la VM (amb el seu TPM) ja no existeix, les credencials de la BD **no es poden desxifrar** (és el risc acceptat de l'ADR-0002, punt 2c). Cal:

1. Instal·lar l'eina nova amb una KEK nova (pas `52` normal) i la BD buida.
2. Tornar a donar d'alta cada compte d'hosting, clau SSH, contrasenya SFTP, destinació de còpia i credencials de Quipu.
3. Tornar a instal·lar les claus públiques noves als servidors.

## Prova anual (obligatòria)

Almenys un cop l'any, i després de qualsevol canvi gran al desplegament o abans d'actualitzar PostgreSQL a una versió major:

1. Crea una **VM de prova** nova amb un TPM nou (nom de màquina diferent del de producció; el node de Tailscale de prova s'esborra al final).
2. Segueix els passos 1 a 9 d'aquest procediment amb la **còpia de BD més recent** i la KEK del KeePassXC del custodi. El custodi és present.
3. Executa `wpup site:test` d'**un sol lloc** per demostrar que les credencials es desxifren. La VM de prova surt per la mateixa IP pública que producció (la mateixa xarxa de Proxmox), de manera que les restriccions `from=` de les claus SSH ho permeten. No actualitzis cap lloc des de la VM de prova.
4. **Destrueix-ho tot:** atura i esborra la VM de prova *amb* el seu disc i el seu `tpmstate0`, esborra el node de Tailscale, i esborra la còpia de la BD que hi hagis baixat. La VM de prova té totes les credencials de la flota: no pot quedar res.
5. Anota el resultat (data, durada, incidències i millores del procediment) a la tasca de Plane de la prova anual.

Mentre dura la prova, la producció continua en marxa: la VM de prova no ha de tenir Horizon ni el scheduler engegats (`systemctl stop wpup-horizon wpup-scheduler`) per evitar que dues eines executin les mateixes finestres d'actualització.
