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

**Cua i concurrència** (ADR-0006, `config/wpup.php` → `concurrency`). Per defecte la VM fa **una execució alhora**: 1 global, 1 per servidor d'hosting i 1 de staging, a més del lock per lloc i per compte d'hosting. Una execució que troba un nivell ple es queda `queued` i es torna a provar cada `retry_seconds`, sense gastar intents ni fallar per temps. Si un worker mor, les places que tenia s'alliberen en un minut com a molt. Una execució marcada `now` (només un admin, auditada) se salta la cua global però no la del servidor ni els locks.

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

## `site:import`

Només `admin`. Importa llocs en bloc des d'un CSV o YAML: tot o res, idempotent i amb `--dry-run`. Esquema, exemples i regles a [importacio-de-llocs.md](importacio-de-llocs.md).

```bash
sudo -u wpup wpup site:import llocs.yaml --dry-run
sudo -u wpup wpup site:import llocs.csv
```

## Retenció dels punts de restauració (ADR-0009)

Un punt de restauració d'una actualització correcta es conserva 7 dies, i cada lloc en té com a màxim 2 (`backups.retention_days` i `backups.max_per_site` a `config/wpup.php`). Fer-ne un de tercer esborra el més antic que no estigui retingut. La mateixa retenció s'aplica a la còpia externa.

Mai no s'esborra, sigui quina sigui l'edat: un punt protegit manualment (`backups.protected`); el d'una execució que no ha acabat; el d'una execució revertida o fallida que encara no s'ha revisat; el d'una execució de la qual depèn una execució en curs (p. ex. un rollback); el que fa servir una restauració encara no acabada (`backup:restore`, des que s'encua); el més recent verificat d'un lloc amb una execució en curs; ni cap punt d'un lloc que té agafat el bloqueig, el seu o el del seu compte d'hosting. La retenció ho llegeix de l'estat de l'execució: no cal marcar res.

| Comanda | Què fa |
| --- | --- |
| `run:ack <execució>` | Marca com a revisada una execució acabada. El seu punt de restauració deixa d'estar retingut i caduca, com a mínim, 7 dies després de la revisió; després segueix la retenció normal. No toca una protecció manual. Demana confirmació |
| `backup:prune [llocs…\|--tag=…\|--all]` | Sense selecció, tots els llocs (també els inactius). Mostra què s'esborraria (i què es conserva i per què) i, després de confirmar-ho, ho esborra a totes les destinacions. Amb `--json` cal `--dry-run` o `--force`. Surt amb error si algun fitxer no s'ha pogut esborrar (la fila queda viva i es torna a provar a la propera neteja) o si s'ha perdut el bloqueig d'un lloc a mitja neteja |

El planificador fa la mateixa neteja cada dia a les `backups.prune_time` (per defecte, 06:30) amb `engine:prune-restore-points`, que no és accessible des del binari `wpup`. Totes dues tenen `--dry-run`, i cada lloc netejat deixa un resum a l'auditoria (`backups.pruned`).

## `backup:restore`

Restaura un lloc, al mateix lloc, des d'una còpia registrada (`backup:list`): els fitxers (`--files`), la base de dades (`--db`) o tots dos.

```bash
sudo -u wpup wpup backup:restore botiga --point=run12 --files --db --dry-run
sudo -u wpup wpup backup:restore botiga --backup=41 --backup=42 --files --db --accept-data-loss
```

- **Què es restaura:** `--point` indica el punt de restauració (el directori `site-3/run12`, el seu nom `run12` o `manual-…`, o l'id de l'execució); de cada tipus es fa servir la còpia verificada al servidor del lloc o, si no n'hi ha, la d'una destinació externa. `--backup=<id>` (repetible) tria les còpies exactes, sempre d'un mateix punt. Es refusa qualsevol còpia d'un altre lloc, esborrada o no verificada.
- **Com:** s'encua una execució del tipus `restore` amb el bloqueig del lloc. Fa un inventari nou; comprova que cada còpia encara té el SHA-256 registrat a la seva destinació; fa un punt de restauració verificat de l'estat actual (si no el pot fer, no restaura res); torna a comprovar el SHA-256 de cada còpia, a la destinació i al lloc, just abans de restaurar; restaura els fitxers i després la base de dades; i comprova que WP-CLI respon i buida la memòria cau d'objectes.
- **Si alguna cosa falla**, no es desfà res automàticament: el resultat diu què s'ha restaurat i la comanda per tornar al punt de l'estat d'abans (`--point=run<execució de la restauració> --files --db`).
- **Confirmació:** cal escriure el nom del lloc. Sense terminal interactiu, o amb `--json`, cal `--force` (o `--dry-run`). Amb `--dry-run` només fa l'inventari i la comprovació de les còpies, sense tocar res.
- **Botigues (regla de seguretat 4):** la base de dades es reemplaça sencera, i es perd tot el que s'hi ha escrit des de la còpia. En un lloc amb WooCommerce, l'execució s'atura abans de tocar res si no s'indica `--accept-data-loss`. Mentre la marca d'aigua de comandes i usuaris (UZEROWPUP-58) no es pugui comparar, l'eina no sap quines comandes es perdrien i ho considera sempre possible.
- **Auditoria:** cada intent queda a `backup.restore` amb l'execució, el punt d'origen, les còpies, què s'ha restaurat, el punt de l'estat d'abans i si s'ha acceptat perdre dades.

## Claus i secrets (només `admin`, mai pel web)

| Comanda | Què fa |
| --- | --- |
| `ssh-key:generate <servidor> <compte>` | Genera la clau Ed25519 del compte, la desa xifrada i mostra **només** la línia per a `~/.ssh/authorized_keys`, restringida a l'IP de sortida (`from="…",no-port-forwarding,no-agent-forwarding,no-X11-forwarding`). Cal `WPUP_EGRESS_IP` |
| `ssh-key:rotate <servidor> <compte>` | Instal·la una clau nova amb l'actual, la prova, la fa vigent i retira l'antiga de `~/.ssh/authorized_keys`. Si s'interromp, es torna a executar i continua; sempre queda una clau que funciona. Avisa si l'antiga encara dona accés (p. ex. si la va instal·lar el proveïdor fora de `~/.ssh`) |
| `secrets:set-password <servidor> <compte>` | Contrasenya SFTP d'un compte amb driver d'agent. S'escriu dues vegades sense eco, mai com a argument |
| `secrets:rekey` | Torna a xifrar totes les claus de dades amb la KEK vigent. És idempotent. Vegeu [runbooks/rotacio-kek.md](runbooks/rotacio-kek.md) |

Totes tenen `--dry-run` i queden a l'auditoria. La clau privada no surt mai a la sortida, als logs ni al disc.
