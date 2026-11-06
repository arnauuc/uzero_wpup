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
| `--include-uploads` / `--no-include-uploads` | Inclou o exclou `uploads` de la còpia (ADR-0009) |
| `--external-backup`, `--external-backup-notes` | Còpia externa declarada: `plesk`, `dropbox`, `plugin`, `other` o `none`, i notes (màx. 500 caràcters). Les notes necessiten un tipus ja declarat o indicat alhora. Només `admin` |

Només a `site:add`: `--server` i `--account` (obligatoris; es reutilitzen si ja existeixen, o es creen), `--hostname` (obligatori per a un servidor nou), `--ssh-port`, `--panel`, `--driver`. A `site:edit`: `--activate` / `--deactivate`.

- **Edició:** només canvia el que s'indica. Les opcions de llista substitueixen la llista sencera. Per buidar un camp, `--clear=<camp>` (repetible): `tag`, `php-binary`, `window`, `verify-url`, `mask`, `checkout-product`, `freeze`, `exclude`, `notify`, `external-backup`. No es pot indicar un valor i esborrar-lo alhora. `--clear=external-backup` esborra el tipus i les notes.
- **Interactiu:** si falten valors obligatoris i hi ha terminal, `site:add` els pregunta; `site:edit` sense opcions ofereix URL, camí i estratègia. Amb `--no-interaction` els valors que falten són un error de validació.
- `--dry-run` mostra què es crearia o canviaria sense escriure ni auditar; `--json` dóna la sortida per a scripts.

## `plan`: compatibilitat amb PHP, capa 2 (anàlisi estàtica)

`wpup plan` no toca el lloc, però sí que descarrega al servidor de l'eina el paquet nou de cada connector i tema que s'actualitzaria (artefactes, UZEROWPUP-49) i l'analitza amb PHP_CodeSniffer + PHPCompatibility, amb `testVersion` igual al PHP real del lloc. `plan` no té `--dry-run` perquè mai modifica el lloc.

- Un **error** omet l'actualització d'aquest component (motiu «l'anàlisi estàtica hi veu problemes», amb el nombre d'errors i fins a 3 `fitxer:línia`). Els **avisos** no la bloquegen: surten a sota de la taula, com a `Avís <tipus>:<slug>: fitxer:línia missatge`, i es desen al pla de l'execució.
- Si l'anàlisi no es pot fer (descàrrega bloquejada, temps esgotat, PHPCompatibility falla, PHP del lloc il·legible, l'artefacte no coincideix amb el seu SHA-256) el component s'omet igualment, per precaució, i el detall en diu el motiu. Només s'analitzen els components que encara s'actualitzarien: un lloc bloquejat, un component congelat, exclòs, major o amb requisits no complerts no baixa cap paquet.
- Només compta el PHP **del web** del lloc, amb `testVersion` exactament igual a la seva versió menor (p. ex. `8.2-8.2`). Si no s'ha verificat pel web (és el del CLI), cap component no s'analitza i tots s'ometen per precaució.
- Cada paquet té el seu propi temps (descàrrega i anàlisi) i el pas té un pressupost total (`static_analysis.step_budget_seconds`). Quan el que queda no cobreix un altre paquet (`min_remaining_seconds`), la resta s'ometen amb el motiu «temps d'anàlisi esgotat: s'omet per precaució» i el pas acaba.
- No s'analitzen el nucli (en tenen prou els requisits declarats) ni les traduccions (no porten codi).
- S'exclouen `vendor/`, `node_modules/` i els polyfills coneguts (`wpup.static_analysis.ignore`, patrons de `phpcs --ignore`). Els límits (temps, memòria, mida de la sortida, nombre de fitxers) són a `wpup.static_analysis`.
- El resultat es desa a la taula `compat_scans`, per SHA-256 del paquet, versió menor de PHP i versió de les regles (PHPCompatibility, PHP_CodeSniffer i exclusions): el mateix paquet no es torna a analitzar.
- Limitació coneguda: PHPCompatibility 9.3 no detecta la sintaxi nova de PHP 8 (`match`, tipus unió, operador nullsafe…) i només coneix algunes funcions de PHP 8.0. Aquests casos els cobreix la capa 3 (execució real).

## Còpies externes conegudes (ADR-0009, punt 2)

L'eina no fa còpies de llarga durada, però vigila que n'hi hagi. `wpup inventory` mostra una fila **Còpia externa** per lloc: `ok` o `AVÍS: …`.

- **Declaració** (només `admin`): `wpup site:edit botiga-a --external-backup=plesk --external-backup-notes="Plesk, diària, 14 dies"`. Tipus: `plesk`, `dropbox`, `plugin`, `other`, `none`.
- **Detecció:** l'inventari busca plugins de còpies (UpdraftPlus, BackWPup, All-in-One WP Migration, Duplicator, BlogVault) a la llista de plugins instal·lats (`config/wpup.php` → `inventory.external_backups`). Per als actius que exposen l'última còpia amb un format fiable (ara UpdraftPlus) llegeix, amb `wp option pluck`, la data i si va anar bé. Es desa a `environment.external_backups` de la captura.
- **Avís** (la declaració cobreix «hi ha còpia», però el que es troba al lloc compta sempre):
  - cap còpia externa coneguda (cap declarada i cap plugin actiu), o `plugin` declarat sense cap plugin actiu;
  - la darrera còpia d'un plugin ha fallat (UpdraftPlus desa també les execucions fallides), i no compta com a recent;
  - un plugin que exposa la data no s'ha pogut llegir (un que no l'exposa mai no avisa);
  - l'última còpia correcta és més antiga de `max_age_days` (7 per defecte), encara que s'hagi declarat `plesk`, `dropbox` o `other`.
  Sense inventari, o amb una captura sense detecció, només es jutja la declaració i l'avís ho diu.
- `--external-backup-notes=""` (buit) esborra les notes; canviar el tipus sense indicar-ne conserva les notes. Les notes es retallen i no poden ser només espais.
- `wpup inventory` mostra l'avís i, si cal, «Detecció de còpies» amb el que no s'ha pogut llegir. `--json` afegeix `external_backup_warnings` i `external_backup_detection_warnings` a cada lloc, i `--csv` la columna `external_backup_warnings`.

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

## `plan` i la política de versions (ADR-0005)

`wpup plan [llocs…|--tag=…|--all]` encua un inventari fresc i calcula què s'actualitzaria i què s'ometria, i per què, sense tocar el lloc. Hi aplica la política de versions:

- **Majors:** core `x.y`, WooCommerce `X.0` i plugins o temes amb canvi al primer número. Fins a F7 s'ometen amb «major pendent: cal staging (F7)», i el pla diu «Cal staging primer: sí». La taula hi afegeix `(major)`.
- **Quarantena:** una versió nova espera `planning.quarantine_days` (per defecte, 7) des de la data de publicació de WordPress.org o des de la primera vegada que l'inventari la va veure (`package_versions`), la que sigui anterior. La taula mostra els dies que falten. Si surt una versió correctiva, compta la seva pròpia data.
- **Excepcions sense quarantena:** el core `x.y.z` dins de la mateixa branca, i les actualitzacions que corregeixen una vulnerabilitat coneguda de la versió instal·lada (la font arriba amb UZEROWPUP-128). Només es consulta per a les versions que encara són en quarantena. S'aplica la primera versió que les corregeix, amb les mateixes regles de staging si és major; si és anterior a l'oferta, no se'n coneixen els requisits i s'omet amb el motiu. Surten a «Excepcions de seguretat».

| Opció | Què fa |
| --- | --- |
| `--allow-major` | Només `admin`. Aplica in-place, sense staging, els canvis majors d'aquesta execució. Surten a «Majors aplicades: cal revisar» |
| `--skip-quarantine` | Només `admin`. Aplica les versions que encara són en quarantena, per a casos urgents que la font de vulnerabilitats no detecta |

Totes dues queden a l'auditoria (`plan.version_policy_overridden`, i als `flags` de `run.queued`) i a l'execució (`runs.flags`), perquè l'actualització i l'informe en vegin el mateix valor. Un operador que les fa servir rep un error i queda a l'auditoria (`command.denied`). Si l'admin deixa de ser-ho abans que el worker planifiqui, el pas falla (`run.denied`). Amb `--json`, cada pla porta `requires_staging`, `overrides` i, per a cada canvi, `major`, `quarantine_bypass` i `quarantine_days_left`.

## Retenció dels punts de restauració (ADR-0009)

Un punt de restauració d'una actualització correcta es conserva 7 dies, i cada lloc en té com a màxim 2 (`backups.retention_days` i `backups.max_per_site` a `config/wpup.php`). Fer-ne un de tercer esborra el més antic que no estigui retingut. La mateixa retenció s'aplica a la còpia externa.

Mai no s'esborra, sigui quina sigui l'edat: un punt protegit manualment (`backups.protected`); el d'una execució que no ha acabat; el d'una execució revertida o fallida que encara no s'ha revisat; el d'una execució de la qual depèn una execució en curs (p. ex. un rollback); el que fa servir una restauració encara no acabada (`backup:restore`, des que s'encua); el més recent verificat d'un lloc amb una execució en curs; ni cap punt d'un lloc que té agafat el bloqueig, el seu o el del seu compte d'hosting. La retenció ho llegeix de l'estat de l'execució: no cal marcar res.

| Comanda | Què fa |
| --- | --- |
| `run:ack <execució>` | Marca com a revisada una execució acabada. El seu punt de restauració deixa d'estar retingut i caduca, com a mínim, 7 dies després de la revisió; després segueix la retenció normal. No toca una protecció manual. Demana confirmació |
| `backup:prune [llocs…\|--tag=…\|--all]` | Sense selecció, tots els llocs (també els inactius). Mostra què s'esborraria (i què es conserva i per què) i, després de confirmar-ho, ho esborra a totes les destinacions. Amb `--json` cal `--dry-run` o `--force`. Surt amb error si algun fitxer no s'ha pogut esborrar (la fila queda viva i es torna a provar a la propera neteja) o si s'ha perdut el bloqueig d'un lloc a mitja neteja |

El planificador fa la mateixa neteja cada dia a les `backups.prune_time` (per defecte, 06:30) amb `engine:prune-restore-points`, que no és accessible des del binari `wpup`. Totes dues tenen `--dry-run`, i cada lloc netejat deixa un resum a l'auditoria (`backups.pruned`).

## Còpia de l'eina (ADR-0009, punt 3)

| Comanda | Què fa |
| --- | --- |
| `tool:backup [--dry-run] [--force]` | Encua per als workers la còpia de l'eina (BD, configuració no secreta i informes, xifrada amb `age`) cap a la destinació de Dropbox `tool`, i n'espera el resultat. Amb `--dry-run` només mostra què s'inclouria i què esborraria la retenció. Sense `--force`, abans de fer-la mostra què esborraria la retenció i demana confirmació. Només n'hi pot haver una alhora. Vegeu [runbooks/copia-eina.md](runbooks/copia-eina.md) |

El planificador l'encua cada dia a les `tool_backup.time` (per defecte, 07:00) sense esperar-la, si no n'hi ha cap altra en cua o en curs.

## `backup:restore`

Restaura un lloc, al mateix lloc, des d'una còpia registrada (`backup:list`): els fitxers (`--files`), la base de dades (`--db`) o tots dos.

```bash
sudo -u wpup wpup backup:restore botiga --point=run12 --files --db --accept-data-loss --dry-run
sudo -u wpup wpup backup:restore blog --backup=41 --files --force
```

- **Què es restaura:** `--point` indica el punt de restauració (el directori `site-3/run12`, el seu nom `run12` o `manual-…`, o l'id de l'execució). De cada tipus es fa servir la còpia verificada al servidor del lloc; si no hi és o ja no té el SHA-256 registrat, una còpia externa verificada del mateix punt, amb un avís. `--backup=<id>` (repetible) tria les còpies exactes, sempre d'un mateix punt, sense alternativa. Es refusa qualsevol còpia d'un altre lloc, esborrada o no verificada.
- **Ordre de l'execució** (tipus `restore`, amb el bloqueig del lloc):
  1. Inventari nou, de només lectura i orientatiu: si falla (un lloc trencat), es continua sense, i mai bloqueja el lloc.
  2. Comprovació de les còpies abans d'escriure res: SHA-256 a la seva destinació i, de l'arxiu de fitxers, què va excloure.
  3. Mode manteniment (si no ho estava), abans del punt de l'estat actual, perquè no entri cap comanda entre aquest punt i la restauració.
  4. Punt de restauració verificat de l'estat actual. L'arxiu de codi exclou només el que l'arxiu restaurat va excloure, de manera que cobreix tot el que la restauració pot sobreescriure o esborrar (també `uploads` si l'arxiu restaurat els inclou). Si no es pot fer, no es restaura res.
  5. Restauració dels fitxers, un altre cop el mode manteniment (el mirall de fitxers esborra `.maintenance`) i la base de dades. Al servidor del lloc es restaura des del magatzem mateix, i el driver en torna a comprovar la mida i el SHA-256 just abans. Una còpia externa es verifica mentre es descarrega i es puja al lloc amb el checksum comprovat.
  6. Comprovació que WP-CLI respon, buidatge de la memòria cau d'objectes i el mode manteniment com estava abans, també si alguna cosa ha fallat.
- Les ordres de WP-CLI de còpia, restauració i manteniment s'executen amb `--skip-plugins --skip-themes`, perquè un lloc que un plugin o un tema fa caure amb un error fatal es pugui copiar i restaurar. Un mu-plugin trencat continua impedint-ho.
- **Si alguna cosa falla**, no es desfà res automàticament: el resultat diu què s'ha restaurat i, si s'ha restaurat alguna cosa, la comanda per tornar al punt de l'estat d'abans (`--point=run<execució de la restauració> --files --db`, més `--accept-data-loss` en una botiga).
- **Confirmació:** cal escriure el nom del lloc. Sense terminal interactiu, o amb `--json`, cal `--force` (o `--dry-run`), excepte en els dos casos de sota, que sempre demanen una persona al terminal. Amb `--dry-run` només fa l'inventari i la comprovació de les còpies, sense tocar res.
- **Botigues (regla de seguretat 4):** la base de dades es reemplaça sencera, i es perd tot el que s'hi ha escrit des de la còpia. Cada còpia de la base de dades desa, just abans del volcat, la marca d'aigua de comandes i usuaris (UZEROWPUP-58): l'ID i les dates més altes de les taules de comandes (HPOS i `posts`, amb les metadades, els articles i les notes), d'usuaris (`users`, amb una empremta agregada per veure-hi també els canvis in situ com la contrasenya o el correu, i `usermeta`) i de `wc_customer_lookup`. Les files creades o modificades fins a 5 minuts abans de la lectura (`wpup.backups.watermark.margin_seconds`) també compten com a escrites. L'execució de restauració la compara amb el lloc i diu quines comandes i quins usuaris són nous o s'han modificat des de la còpia (ID, tipus, estat, data i rols; mai noms, correus ni adreces). Si la còpia no té marca d'aigua o no es pot llegir el lloc, es tracta com si hi hagués escriptures noves. Restaurar la base de dades d'una botiga (WooCommerce instal·lat segons l'últim inventari, o un lloc sense cap inventari conegut) demana `--accept-data-loss` **i** escriure el nom del lloc en un terminal interactiu: `--force` no ho salta. L'execució ho torna a comprovar amb el seu propi inventari i s'atura abans de tocar res si no hi ha totes dues coses.
- **Lloc trencat:** si el punt de l'estat actual no es pot fer ni amb el mode sense plugins, `--without-pre-backup` permet restaurar igualment. Només de manera interactiva (mai amb `--force`), i l'execució ho diu: no hi haurà manera de tornar a l'estat d'abans.
- **Temporals:** els fitxers temporals d'una restauració al directori de treball del lloc (`restore-<hex>.…`) s'esborren en acabar, els que hagi deixat una restauració interrompuda s'esborren a l'inici de la següent, i la neteja del magatzem esborra els antics.
- **Auditoria:** cada intent queda a `backup.restore` amb l'execució, el punt d'origen, les còpies, què s'ha restaurat, el punt de l'estat d'abans, si el lloc és una botiga, si s'ha acceptat i confirmat de manera interactiva perdre'n les dades i si s'ha demanat `--without-pre-backup`.

### Base de dades d'una actualització revertida (regla de seguretat 4)

El rollback d'una actualització (UZEROWPUP-57 i UZEROWPUP-59) restaura sempre els fitxers del punt de restauració de l'execució. La base de dades, en canvi, només es restaura automàticament si se sap que no s'ha escrit res a les comandes ni als usuaris des de la còpia:

- Abans de tocar res, es compara la marca d'aigua de la còpia amb el lloc. Si no hi ha escriptures noves, s'activa el mode manteniment (si no ho estava) i es prepara la còpia al lloc (la del servidor del lloc, o una d'externa que s'hi puja). Just abans d'importar-la es torna a escriure `.maintenance` (WordPress ignora el que té més de 10 minuts) i es torna a comprovar, perquè no hi entri cap comanda entremig. Després es comprova que WP-CLI respon. Si no es pot activar el mode manteniment, no es restaura.
- Risc residual: el mode manteniment atura les peticions a WordPress, però no el cron del sistema ni el WP-CLI del servidor. Una escriptura d'aquests en els pocs segons entre l'última comprovació i la importació no es pot descartar del tot.
- Si una còpia no es pot llegir, no es pot pujar al lloc o no té el SHA-256 registrat, no s'ha importat res i es prova la següent del mateix punt; l'avís diu el motiu de cadascuna. Només una fallada un cop començada la importació pot deixar la base de dades a mitges (i aleshores el mode manteniment es manté).
- Si l'execució s'interromp a mitja importació, en reprendre's continua amb la mateixa còpia sense tornar a comprovar (la decisió ja estava presa i desada abans d'importar), i desactiva el mode manteniment si l'havia activat aquest pas.
- Si n'hi ha, o no es pot saber (còpia sense marca d'aigua, marca no vàlida, lloc que no es pot llegir), la base de dades **no es toca**. El pas acaba com a «BD no restaurada» i l'avís, desat al resultat del pas per a l'informe i la notificació, porta el llistat de comandes i usuaris afectats (ID, tipus, estat, data i rols; mai dades personals) i la comanda per forçar-ho.
- Val per a qualsevol lloc, botiga o no. Cada intent queda a l'auditoria (`rollback.database`; `denied` quan no s'ha restaurat per escriptures noves).

Per restaurar-la igualment, sabent que es perdran aquestes comandes i usuaris, es fa a mà amb `backup:restore` des del punt de l'execució revertida:

```bash
sudo -u wpup wpup backup:restore botiga --point=run12 --db --accept-data-loss --dry-run
sudo -u wpup wpup backup:restore botiga --point=run12 --db --accept-data-loss
```

És la mateixa restauració de sempre, amb totes les proteccions: cal escriure el nom del lloc en un terminal interactiu (`--force` no ho salta), abans es fa un punt verificat de l'estat actual (amb les comandes noves, per poder-les recuperar), l'execució torna a comprovar i llistar les escriptures noves, i queda a l'auditoria (`backup.restore`). Una restauració manual demana sempre aquesta confirmació en una botiga, també quan no hi ha escriptures noves. El punt d'una execució revertida no caduca mentre no s'ha revisat (`run:ack`).

## Claus i secrets (només `admin`, mai pel web)

| Comanda | Què fa |
| --- | --- |
| `ssh-key:generate <servidor> <compte>` | Genera la clau Ed25519 del compte, la desa xifrada i mostra **només** la línia per a `~/.ssh/authorized_keys`, restringida a l'IP de sortida (`from="…",no-port-forwarding,no-agent-forwarding,no-X11-forwarding`). Cal `WPUP_EGRESS_IP` |
| `ssh-key:rotate <servidor> <compte>` | Instal·la una clau nova amb l'actual, la prova, la fa vigent i retira l'antiga de `~/.ssh/authorized_keys`. Si s'interromp, es torna a executar i continua; sempre queda una clau que funciona. Avisa si l'antiga encara dona accés (p. ex. si la va instal·lar el proveïdor fora de `~/.ssh`) |
| `secrets:set-password <servidor> <compte>` | Contrasenya SFTP d'un compte amb driver d'agent. S'escriu dues vegades sense eco, mai com a argument |
| `backup-target:dropbox <nom> --root=<carpeta>` | Crea o actualitza una destinació de còpia a Dropbox. Demana App key, App secret i refresh token sense eco (cal terminal interactiu, mai com a arguments), els comprova amb Dropbox i els desa xifrats; no desa res si Dropbox els rebutja. No deixa canviar `--root` si la destinació ja té còpies. No l'enllaça a cap lloc. Vegeu [runbooks/dropbox.md](runbooks/dropbox.md) |
| `secrets:rekey` | Torna a xifrar totes les claus de dades amb la KEK vigent. És idempotent. Vegeu [runbooks/rotacio-kek.md](runbooks/rotacio-kek.md) |

Totes tenen `--dry-run` (el de `backup-target:dropbox` no contacta amb Dropbox) i queden a l'auditoria. La clau privada no surt mai a la sortida, als logs ni al disc.
