# Runbook: rotació de la clau mestra (KEK)

Quan cal fer-la (ADR-0002): si la còpia de la KEK es veu compromesa, si canvia el custodi o si algú amb accés de root a la VM marxa.

La KEK la crea i la segella **root**; l'eina (usuari `wpup`) no pot fer-ho, i no la mostra mai. `wpup secrets:rekey` només torna a xifrar les claus de dades (DEK) amb la KEK vigent.

## Passos

1. **Crear la KEK nova (root a la VM).** Exemple per passar de la versió 1 a la 2:

   ```bash
   umask 077
   openssl rand -base64 32 > /root/wpup-kek-v2.txt
   systemd-creds encrypt --with-key=tpm2+host --name=wpup-kek-v2 /root/wpup-kek-v2.txt /etc/credstore.encrypted/wpup-kek-v2
   ```

2. **Custòdia.** El custodi (Arnau) desa el contingut de `/root/wpup-kek-v2.txt` a una entrada nova del seu KeePassXC (punt 2c de l'ADR-0002). Després, esborra el fitxer:

   ```bash
   shred -u /root/wpup-kek-v2.txt
   ```

3. **Carregar totes dues versions.** Les unitats de Horizon i del scheduler i la CLI carreguen totes les versions que hi ha a `/etc/credstore.encrypted` (drop-in generat per `deploy/bin/wpup-kek-units`, i `wpup-cli` per a la CLI):
   - posa `WPUP_KEK_VERSION=2` al `.env` de l'eina;
   - com a root, des de la còpia de root del repositori: `./deploy/provision.sh --only 55` (actualitza el drop-in i reinicia els workers).

   Des d'ara, els secrets nous es xifren amb la v2 i els antics encara es poden llegir amb la v1.

4. **Tornar a xifrar.** Un `admin` executa:

   ```bash
   sudo -u wpup wpup secrets:rekey --dry-run
   sudo -u wpup wpup secrets:rekey
   ```

   És idempotent: si s'interromp, es torna a executar i continua. Queda a l'auditoria (`secrets.rekey`).

5. **Retirar la KEK antiga.** Quan `secrets:rekey` digui que no en queda cap de pendent:
   - esborra `/etc/credstore.encrypted/wpup-kek-v1`;
   - executa `./deploy/provision.sh --only 55` (el drop-in ja no la llista) i reinicia els workers.

   El custodi marca l'entrada antiga del KeePassXC com a retirada. No l'esborris fins que la còpia de la BD de l'eina feta abans de la rotació hagi caducat: per restaurar-la cal la KEK antiga (vegeu [recuperació](recuperacio.md)).
