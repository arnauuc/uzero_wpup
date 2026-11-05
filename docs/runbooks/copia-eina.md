# Runbook: còpia de l'eina a Dropbox

La còpia de l'eina mateixa ([ADR-0009](../adr/0009-copies-i-retencio.md), punt 3; UZEROWPUP-47). No té res a veure amb els punts de restauració dels llocs.

## Què hi ha a la còpia

Cada dia a les 07:00 (`wpup.tool_backup.time`, després de la finestra d'actualitzacions i de la purga dels punts de restauració) l'eina fa un fitxer `tool/wpup-AAAAMMDD-HHMMSS.tar.age`. És un `tar` xifrat amb `age` que conté:

| Fitxer | Contingut |
| --- | --- |
| `manifest.json` | Data de la còpia, nom de la BD i llista de fitxers inclosos i omesos |
| `db.dump` | `pg_dump --format=custom` de la BD de l'eina (llocs, execucions, `audit_log`, secrets xifrats amb la KEK, dades de facturació…) |
| `files/<camí absolut>` | La configuració no secreta (`WPUP_TOOL_BACKUP_CONFIG_PATHS`, per defecte `deploy/config.env`) i els informes (`WPUP_TOOL_BACKUP_REPORT_PATHS`) |

**No hi ha mai:**

- Ni els artefactes de paquets ni les captures, que tenen retenció pròpia.
- Ni el `.env`, ni claus, ni cap secret. Una llista negra fixa (`ToolBackupContents`) omet, digui el que digui la configuració, els `.env*`, `*.key`, `*.pem`, `id_*`, `.ssh`, `secrets`, `credstore*`, els enllaços simbòlics i qualsevol fitxer que sembli que conté una clau privada o una contrasenya. Cada camí omès queda als avisos de l'execució.
- Ni la KEK. Els secrets de la BD continuen xifrats amb la KEK: per fer-los servir després de restaurar, cal la KEK del KeePassXC ([rotacio-kek.md](rotacio-kek.md)).

El xifratge es fa a la VM abans de pujar res. El text en clar (dump, còpies i `tar`) només existeix dins d'un directori `0700` del directori de staging (`WPUP_BACKUP_STAGING_DIR`) i s'esborra en acabar, també si falla.

**Retenció:** l'última còpia de cadascun dels 30 dies més recents que en tenen, i la primera de cadascun dels 12 mesos més recents que en tenen (`wpup.tool_backup.retention`). Una còpia manual el mateix dia substitueix la d'aquell dia, i si la VM ha estat aturada uns dies, no es perd història. Les claus que no segueixen el format (`tool/notes.txt`…) no s'esborren mai. Cada esborrat queda a `audit_log` com a `tool_backup.deleted`.

## Configuració

### 1. Clau d'age

La clau privada **no toca mai la VM**. A l'ordinador d'Arnau:

```bash
age-keygen -o - 2>/dev/null
```

- La línia `AGE-SECRET-KEY-1…` és la **clau privada**: es desa al KeePassXC, al costat de la KEK. No es guarda enlloc més.
- La línia `# public key: age1…` és la **clau pública** (el destinatari). No és secreta.

Al `.env` de la VM:

```bash
WPUP_TOOL_BACKUP_AGE_RECIPIENTS=age1…
```

Hi pot haver més d'un destinatari separat per comes (per exemple, una segona clau de reserva). Sense cap destinatari vàlid, la còpia no es fa i l'execució falla.

### 2. Destinació de Dropbox

La còpia va a la fila de `backup_targets` que es diu `tool` (`WPUP_TOOL_BACKUP_TARGET`):

- de tipus `dropbox` (una app de Dropbox amb accés només a la seva *App folder*);
- activa i **no vinculada a cap lloc**: les còpies de l'eina contenen les dades de tots els llocs i no comparteixen carpeta amb cap punt de restauració;
- amb la credencial al `SecretStore` (`credential_ref`).

Es crea amb `wpup backup-target:dropbox` (UZEROWPUP-107, en un PR a part), per exemple amb `config.root` = `eina`. Les còpies queden a `Aplicacions/<app>/eina/tool/` del Dropbox.

### 3. Comprovar-ho

```bash
sudo -u wpup /opt/uzero-wpup/wpup tool:backup --dry-run
```

Necessita els workers en marxa (obrir la destinació revela la credencial, [ADR-0011](../adr/0011-cli-sense-clau-mestra.md)). Mostra la clau que es faria servir, quants fitxers s'inclourien, els destinataris, les còpies que hi ha i les que esborraria la retenció. No fa cap dump ni puja ni esborra res.

`wpup tool:backup` sense `--dry-run` fa la còpia en aquell moment. Cada execució deixa un `tool_backup.completed` (o `tool_backup.failed`) a `audit_log`, amb la clau, la mida, el SHA-256 i els avisos.

## Restaurar

Es fa en una màquina de confiança: la VM nova ja provisionada (UZEROWPUP-28) o, per a una prova, qualsevol màquina amb `age`, `tar` i PostgreSQL 17.

1. **Descarregar** la còpia de Dropbox (`Aplicacions/<app>/eina/tool/wpup-….tar.age`) i comprovar-ne el SHA-256 amb el de l'`audit_log` (`tool_backup.completed`), si encara es té.
2. **Desxifrar.** La clau privada passa només per la RAM:

   ```bash
   umask 077
   # Enganxa la línia AGE-SECRET-KEY-1… del KeePassXC:
   cat > /dev/shm/wpup-backup-key.txt
   age --decrypt --identity /dev/shm/wpup-backup-key.txt --output /dev/shm/wpup.tar wpup-AAAAMMDD-HHMMSS.tar.age
   shred -u /dev/shm/wpup-backup-key.txt
   mkdir /dev/shm/wpup-restore && tar --extract --file=/dev/shm/wpup.tar --directory=/dev/shm/wpup-restore
   ```

   Si l'arxiu és gran, fes-ho en un directori `0700` del disc en lloc de `/dev/shm`, i esborra'l en acabar.
3. **Revisar** `manifest.json` (data, fitxers, omesos) i el dump:

   ```bash
   pg_restore --list /dev/shm/wpup-restore/db.dump | grep -E 'TABLE (DATA )?public\.(sites|runs|audit_log|secrets)'
   ```

4. **Restaurar la BD en una base de dades buida.** A la VM, amb els rols ja creats pel desplegament (`wpup_owner`, `wpup_app`), com a `postgres`, perquè es conservin propietaris, permisos i els triggers d'`audit_log`:

   ```bash
   sudo -u postgres createdb --owner=wpup_owner wpup
   sudo -u postgres pg_restore --exit-on-error --dbname=wpup /dev/shm/wpup-restore/db.dump
   sudo -u wpup php /opt/uzero-wpup/artisan migrate --database=pgsql_owner --force
   ```

   L'última ordre només aplica les migracions que siguin més noves que la còpia. No restauris mai sobre una BD amb dades.
5. **Restaurar la configuració**: copia els fitxers de `files/` al seu camí (el del `manifest.json`), revisant-los abans.
6. **KEK**: carrega la KEK del KeePassXC segons el desplegament (UZEROWPUP-28) i [rotacio-kek.md](rotacio-kek.md). Sense la mateixa KEK, els secrets de la BD no es poden obrir.
7. **Comprovacions:**
   - `wpup runs` mostra l'historial fins a la data de la còpia, i `wpup site:test <lloc>` connecta amb un lloc (els secrets s'obren).
   - El recompte de `sites`, `runs` i `audit_log` coincideix amb el de l'origen a la data de la còpia.
   - `wpup tool:backup --dry-run` torna a trobar la destinació.
8. **Neteja**: `shred -u /dev/shm/wpup.tar` i esborra `/dev/shm/wpup-restore`.

## Prova anual

La restauració es prova un cop l'any dins del procediment de recuperació (UZEROWPUP-28): es restaura l'última còpia en una BD de prova (`createdb --owner=wpup_owner wpup_restore_test`, i després `pg_restore --dbname=wpup_restore_test`), es fan les comprovacions del punt 7 que no depenen del web (recomptes i `pg_restore --list`), i després s'esborra (`dropdb wpup_restore_test`). Anota la data i el resultat a la tasca.
