# CLI `wpup`: opcions comunes

Totes les comandes de l'operador s'executen amb `sudo -u wpup wpup <comanda>` (ADR-0002). El binari `wpup` només deixa passar les comandes de l'eina (`config/wpup.php`, `cli.allowed_commands`).

## Selecció de llocs

Les comandes que actuen sobre llocs accepten **una** d'aquestes formes:

| Forma | Exemple | Què selecciona |
| --- | --- | --- |
| Noms | `wpup update botiga-a botiga-b` | Aquests llocs, encara que estiguin inactius (la comanda decideix què fer-ne) |
| `--tag=<etiqueta>` (repetible) | `wpup update --tag=woo --tag=nit` | Els llocs **actius** que tenen qualsevol de les etiquetes |
| `--all` | `wpup inventory --all` | Tots els llocs actius |

Barrejar formes, no indicar-ne cap o escriure un nom desconegut és un error, i no es fa res.

## `--dry-run`

Totes les comandes que fan canvis tenen `--dry-run` (AGENTS.md, regla 9). Ho comprova un test sobre totes les comandes registrades que implementen `App\Console\ChangesState`. En una execució, el dry-run fa córrer només els passos de lectura (inventari, comprovacions) i descriu la resta.

## Execucions encuades

Les comandes llargues (inventari, actualització, còpia, verificació, rollback…) creen una execució per lloc, l'encuen i tornen de seguida amb el número:

```
Execució #42 encuada (update, botiga-a).
```

| Opció | Efecte |
| --- | --- |
| *(cap)* | Torna de seguida; consulta-la amb `wpup runs` |
| `--follow` | Mostra cada canvi de pas en directe i espera el final |
| `--wait` | Espera el final sense mostrar els passos |

Interrompre `--follow` o `--wait` (Ctrl-C) no atura l'execució, que continua al worker.

**Codi de sortida** amb `--follow` o `--wait` (preval el pitjor resultat):

| Codi | Significat |
| --- | --- |
| 0 | Totes han acabat bé (o s'han omès) |
| 1 | Alguna ha fallat |
| 2 | Alguna s'ha revertit (rollback correcte) |
| 3 | Alguna s'ha cancel·lat |

## `--json`

Per a scripts: la sortida és un sol document JSON a stdout, sense text per a l'operador. Per a execucions: `{"runs": [{"id", "site", "type", "status", "result"}]}`.

## `site:add` i `site:edit`

Només `admin`. Alta i edició d'un lloc i del seu perfil; tot queda a `audit_log` (`site.add`, `site.edit` amb el valor d'abans i d'ara de cada camp canviat). **Cap opció accepta credencials**: les claus i contrasenyes es gestionen a part (`ssh-key:*`, `secrets:*`) i la clau del servidor es fixa amb `site:test`.

```
wpup site:add botiga-a --url=https://botiga-a.example --wp-path=/var/www/vhosts/botiga-a/httpdocs \
    --server=plesk1 --hostname=plesk1.example --account=botiga_a --driver=ssh \
    --tag=woo --strategy=staging_first --window-days=1-5 --window-start=01:00 --window-end=05:00
wpup site:edit botiga-a --verify-url=/ --verify-url=/cart --diff-threshold=1.5 --notify=email
wpup site:edit botiga-a --clear=mask --deactivate
```

| Opció | Camp |
| --- | --- |
| `--url`, `--wp-path`, `--php-binary` | URL (http/https), camí absolut de WordPress i del PHP |
| `--tag=*`, `--strategy` | Etiquetes; `in_place` o `staging_first` |
| `--window-days` (`1-5,7`, 1 = dilluns), `--window-start`, `--window-end` | Finestra de manteniment (HH:MM); sense finestra, la per defecte de `config/wpup.php` |
| `--auto-update` / `--no-auto-update` | Actualització automàtica |
| `--verify-url=*`, `--mask=*`, `--diff-threshold` | URLs a verificar (camí relatiu o del mateix domini), selectors a emmascarar, llindar 0–100 |
| `--checkout-product`, `--freeze=*`, `--exclude=*` | Producte de prova, components congelats i exclosos |
| `--notify=*` | Canals d'avís: `email`, `telegram`, `slack` |
| `--include-uploads` / `--no-include-uploads`, `--external-backup` | Còpia d'uploads i còpia externa (ADR-0009) |

Només a `site:add`: `--server` i `--account` (obligatoris; es reutilitzen si ja existeixen, o es creen), `--hostname` (obligatori per a un servidor nou), `--ssh-port`, `--panel`, `--driver`. A `site:edit`: `--activate` / `--deactivate`.

- **Edició:** només canvia el que s'indica. Les opcions de llista substitueixen la llista sencera. Per buidar un camp, `--clear=<camp>` (repetible): `tag`, `php-binary`, `window`, `verify-url`, `mask`, `checkout-product`, `freeze`, `exclude`, `notify`, `external-backup`. No es pot indicar un valor i esborrar-lo alhora.
- **Interactiu:** si falten valors obligatoris i hi ha terminal, `site:add` els pregunta; `site:edit` sense opcions ofereix URL, camí i estratègia. Amb `--no-interaction` els valors que falten són un error de validació.
- `--dry-run` mostra què es crearia o canviaria sense escriure ni auditar; `--json` dóna la sortida per a scripts.

## `site:test`

```bash
sudo -u wpup wpup site:test botiga-a
sudo -u wpup wpup site:test --tag=woo --json
```

Prova la connexió i els prerequisits de cada lloc sense tocar WordPress. Mostra una taula per lloc amb el resultat de cada comprovació (ok, avís o error): connexió, shell, eines, PHP del domini, WP-CLI, WordPress, permisos d'escriptura i disc. Si algun lloc té un error, surt amb codi 1.

La primera vegada instal·la el WP-CLI fixat de l'eina a `~/.wpup` del compte, fora del lloc.

**Primer contacte amb un servidor (fixació de la host key, ADR-0002).** Si el servidor encara no té host key fixada, `site:test` en mostra l'empremta (`SHA256:…`), i un **admin** l'ha de confirmar després de comprovar-la al servidor (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`):

- en mode interactiu, amb una pregunta;
- en mode no interactiu, amb `--trust-host-key=SHA256:…`.

En el moment de fixar-la es torna a llegir la clau i es compara amb l'empremta confirmada. Un operador no pot fixar-la. Amb `--dry-run`, només mostra què es fixaria. Una host key que ja està fixada no es canvia mai des d'aquí: si el servidor la canvia, totes les connexions fallen fins que un admin ho investiga. La fixació queda a l'auditoria (`host_key.pin`).
