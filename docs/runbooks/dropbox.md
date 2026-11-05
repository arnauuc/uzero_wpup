# Runbook: destinació de còpia a Dropbox

Implementa la destinació Dropbox d'[ADR-0009](../adr/0009-copies-i-retencio.md) (UZEROWPUP-107). L'eina només toca la carpeta de la seva app: no veu la resta del Dropbox.

## 1. Crear l'app de Dropbox

1. Ves a <https://www.dropbox.com/developers/apps> i prem **Create app**.
2. Tria **Scoped access** i, com a tipus d'accés, **App folder**. Posa-li un nom (p. ex. `uzero-wpup-copies`).
3. A la pestanya **Permissions** activa `files.metadata.read`, `files.content.read` i `files.content.write`, i prem **Submit**. Fes-ho **abans** d'obtenir el refresh token: si canvies els permisos després, cal generar-ne un de nou.
4. A **Settings** copia l'**App key** i l'**App secret**.

## 2. Obtenir el refresh token (flux OAuth amb codi)

Es fa una sola vegada des del teu portàtil (no cal que sigui a la VM).

1. Obre aquesta URL al navegador, amb la teva App key, i accepta l'accés:

   ```
   https://www.dropbox.com/oauth2/authorize?client_id=<APP_KEY>&response_type=code&token_access_type=offline
   ```

   `token_access_type=offline` és obligatori: sense, no es rep cap refresh token. Dropbox mostra un **codi d'autorització** d'un sol ús.

2. Canvia el codi per un refresh token. No escriguis els valors a la línia de comandes: l'historial de la shell els desaria. Fes servir `read -s`:

   ```bash
   read -rp  'App key: '        APP_KEY
   read -rsp 'App secret: '     APP_SECRET; echo
   read -rsp 'Codi d autoritzacio: ' AUTH_CODE; echo
   curl -sS https://api.dropboxapi.com/oauth2/token \
     -d code="$AUTH_CODE" -d grant_type=authorization_code \
     -u "$APP_KEY:$APP_SECRET"
   unset APP_KEY APP_SECRET AUTH_CODE
   ```

3. A la resposta JSON, desa el valor de `refresh_token` (no caduca; ignora l'`access_token`). Copia'l a un gestor de contrasenyes fins al pas següent i no el deixis en cap fitxer ni al portapapers més del necessari.

## 3. Registrar la destinació a l'eina

Com a `admin`, a la VM:

```bash
sudo -u wpup wpup backup-target:dropbox dropbox-principal --root=uzero/copies --dry-run
sudo -u wpup wpup backup-target:dropbox dropbox-principal --root=uzero/copies
```

- `--root` és una carpeta relativa dins de la carpeta de l'app, en minúscules (lletres, xifres, `.`, `_`, `-` separades per `/`).
- L'ordre demana **App key**, **App secret** i **Refresh token** sense eco. Mai es passen com a arguments.
- Les credencials es desen xifrades al `SecretStore` i l'acció queda a l'`audit_log` (`backup_target.dropbox.set`). La sortida no mostra cap valor.
- La comanda només crea o actualitza la destinació; no l'enllaça a cap lloc.

## Rotació

Per canviar l'App secret, el refresh token o la carpeta, torna a executar la mateixa comanda amb el mateix nom: substitueix el secret existent i actualitza `--root`. Si canvies `--root`, les còpies que ja hi havia a la carpeta antiga no es mouen.

## Revocació

1. A la consola de l'app de Dropbox, **Settings**, prem **Delete app**, o bé revoca l'accés des de <https://www.dropbox.com/account/connected_apps>. El refresh token deixa de funcionar a l'instant.
2. A l'eina, les còpies a Dropbox fallaran amb un error d'autorització fins que hi hagi credencials noves (apartat anterior). Els punts de restauració existents a Dropbox continuen al teu Dropbox; no se n'esborra res.
