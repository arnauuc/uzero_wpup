# Runbook: rotació de la clau mestra (KEK)

Quan cal fer-la (ADR-0002): si la còpia de la KEK es veu compromesa, si canvia el custodi o si algú amb accés de root a la VM marxa.

Des de l'[ADR-0011](../adr/0011-cli-sense-clau-mestra.md), la KEK és un **parell de claus X25519**:

- La **clau privada** és secreta. Està segellada amb el TPM i només la carreguen els workers i el scheduler.
- La **clau pública** no és secreta. És a la configuració de l'eina (`WPUP_SECRETS_PUBLIC_KEY_V<n>`) i amb ella qualsevol procés pot desar secrets, però no llegir-los.

El parell el crea i el segella **root**. L'eina (usuari `wpup`) no pot fer-ho i no mostra mai la clau privada. `wpup secrets:rekey` només torna a tancar les claus de dades (DEK) amb la versió vigent, i s'executa als workers.

## Passos

1. **Crear el parell nou (root a la VM).** Exemple per passar de la versió 1 a la 2. La clau privada són 32 bytes aleatoris, i tant el fitxer en clar com la pública que se'n deriva passen només per la RAM (`/dev/shm`):

   ```bash
   umask 077
   head -c 32 /dev/urandom | base64 > /dev/shm/wpup-kek-v2.txt
   systemd-creds encrypt --with-key=tpm2+host --name=wpup-kek-v2 /dev/shm/wpup-kek-v2.txt /etc/credstore.encrypted/wpup-kek-v2
   php -r 'echo base64_encode(sodium_crypto_box_publickey_from_secretkey(base64_decode(trim(file_get_contents("/dev/shm/wpup-kek-v2.txt"))))), "\n";'
   ```

   L'última ordre mostra la **clau pública**. No és secreta: copia-la per al pas 3.

2. **Custòdia.** El custodi (Arnau) desa el contingut de `/dev/shm/wpup-kek-v2.txt` (la clau **privada**) en una entrada nova del seu KeePassXC (punt 2c de l'ADR-0002). Després, esborra el fitxer:

   ```bash
   shred -u /dev/shm/wpup-kek-v2.txt
   ```

3. **Carregar totes dues versions.**
   - Al `.env` de l'eina, afegeix `WPUP_SECRETS_PUBLIC_KEY_V2=<clau pública del pas 1>` sense treure la `V1`, i posa `WPUP_KEK_VERSION=2`.
   - A les unitats de systemd dels **workers i del scheduler** (mai a la CLI ni al web), afegeix `LoadCredentialEncrypted=wpup-kek-v2` sense treure la `v1`.
   - Regenera la memòria cau de configuració (`sudo -u wpup php artisan config:cache`): les claus públiques i la versió vigent es llegeixen del `.env` en carregar la configuració, i sense aquest pas la CLI, el web i els workers continuarien amb la configuració antiga.
   - Fes `systemctl daemon-reload`, reinicia els workers i el scheduler, i recarrega PHP-FPM perquè llegeixi la configuració nova.

   Des d'ara, els secrets nous es tanquen amb la v2 i els antics encara es poden obrir amb la v1. En menys d'un minut, el scheduler comprova que la clau privada v2 correspon a la pública. Fins llavors, i si no hi correspon, la CLI i el web **es neguen a desar secrets** ("has not been confirmed") i el scheduler ho registra com a error crític. Revisa la clau pública del pas 1 abans de continuar.

4. **Tornar a tancar les claus de dades.** Un `admin` executa:

   ```bash
   sudo -u wpup wpup secrets:rekey --dry-run
   sudo -u wpup wpup secrets:rekey
   ```

   La CLI compta els secrets pendents i demana confirmació. La feina la fa un worker (execució de tipus `secrets_rekey`), i la CLI n'espera el resultat. És idempotent: si s'interromp, es torna a executar i continua. Queda a l'auditoria (`secrets.rekey`).

5. **Retirar la versió antiga.** Quan `secrets:rekey` digui que no en queda cap de pendent:
   - treu `LoadCredentialEncrypted=wpup-kek-v1` de les unitats;
   - treu `WPUP_SECRETS_PUBLIC_KEY_V1` del `.env`;
   - esborra `/etc/credstore.encrypted/wpup-kek-v1`;
   - regenera la memòria cau de configuració (`config:cache`) i reinicia els workers i el scheduler.

   El custodi marca l'entrada antiga del KeePassXC com a retirada. No l'esborris fins que hagi caducat la còpia de la BD de l'eina feta abans de la rotació: per restaurar-la cal la clau privada antiga.
