# Especificació: eina d'actualització segura de WordPress/WooCommerce

Oct 16, 2026 · @Arnau

## Objectiu i abast

L'eina (nom provisional: **uzero wpup**; binari de la CLI: `wpup`) actualitza de forma segura una flota de WordPress/WooCommerce: fa còpia de seguretat, comprova la compatibilitat amb el PHP del lloc, actualitza, verifica que el lloc es continua veient i comportant igual, i reverteix si no és així. Cada execució queda registrada i genera un informe.

**Fa:**

- Inventari de cada lloc: versions de core, plugins i temes, PHP real i actualitzacions pendents.
- Còpies de seguretat de fitxers i base de dades a destinacions configurables.
- Actualització de core, plugins, temes i traduccions, amb l'estratègia que tingui configurada cada lloc.
- Verificació posterior: diff visual, flux de compra i errors tècnics.
- Rollback automàtic o manual.
- Logs, informes i notificacions.

**No fa:**

- Canviar la versió de PHP ni la configuració del servidor. Això és responsabilitat de l'operador; l'eina només detecta i avisa.
- Pagaments reals al checkout.
- Gestionar llicències de plugins premium (fa servir les que ja hi ha registrades al lloc).

## Decisions d'arquitectura

Una aplicació Laravel en un servidor propi, operada per CLI (comandes Artisan), amb un worker Node + Playwright per a la verificació visual i funcional.

> _Diagrama: arquitectura · servidor de l'eina i connexions (vegeu el document original)._

Els operadors només parlen amb l'orquestrador; tota connexió amb els llocs, les còpies i els avisos surt del servidor de l'eina.

| Component | Tecnologia | Funció |
| --- | --- | --- |
| Orquestrador | Laravel (PHP 8.3+), comandes Artisan | Inventari, plans, execucions, rollback, informes |
| Cua de feines | Laravel Queues + Redis (Horizon) | Execucions en paral·lel amb límit global i per servidor d'hosting |
| Base de dades | PostgreSQL o MariaDB | Llocs, execucions, passos, logs, còpies, informes |
| Verificador | Node + Playwright, pixelmatch | Captures, diff, checkout, errors de consola i HTTP |
| Staging | Docker al servidor de l'eina | Contenidor amb el PHP i el MySQL/MariaDB del lloc |
| Emmagatzematge | Flysystem o rclone | Còpies a local, SFTP, SCP, S3, Dropbox i altres |
| Anàlisi estàtica | PHP\_CodeSniffer + PHPCompatibility | Detectar codi incompatible amb el PHP del lloc |

**Connexió amb els llocs:**

- **SSH:** WP-CLI executat amb el binari de PHP del domini; còpies amb `mysqldump`/`wp db export` i `tar`, i transferència amb rsync.
- **SFTP:** agent PHP temporal (vegeu la secció corresponent) que exposa les mateixes operacions per HTTPS.

L'orquestrador treballa contra una interfície `SiteDriver` amb dues implementacions (`SshDriver`, `AgentDriver`). La resta del flux no sap com es connecta a cada lloc.

## Model de dades

| Entitat | Camps principals |
| --- | --- |
| `sites` | nom, URL, tags, driver (ssh/agent), host, credencials xifrades, ruta WP, binari PHP, estratègia, finestra de manteniment, actiu |
| `site_profiles` | URLs a verificar, zones a emmascarar, llindar de diff, producte de prova per al checkout, plugins congelats o exclosos, canals d'avís |
| `site_snapshots` | versió de core, plugins i temes, PHP, MySQL, data de l'inventari |
| `backup_targets` | tipus (local-lloc, sftp, scp, s3, dropbox, rclone…), config xifrada, retenció |
| `runs` | lloc, operador, tipus (inventari, pla, update, backup, rollback), estratègia, estat, inici, final |
| `run_steps` | execució, pas, estat, durada, sortida resumida, sortida completa |
| `run_changes` | component, versió abans, versió després, resultat, motiu si s'ha omès |
| `backups` | lloc, execució, destinació, ruta, mida, checksum, caducitat |
| `verifications` | execució, URL, viewport, % diff, captures, errors de consola, HTTP, fatals PHP |
| `reports` | execució, HTML, resum, enviaments |
| `users` | nom, correu, rol, token CLI |
| `audit_log` | usuari, acció, objecte, data, IP; només inserció |

## Inventari i detecció de l'entorn

Cada execució comença amb un inventari fresc; mai es treballa sobre dades d'un inventari anterior.

- **PHP real del lloc:** és el que serveix el web, no el del CLI. A Plesk es llegeix el handler del domini (`plesk bin site --info` o la configuració de PHP-FPM) i es fa servir `/opt/plesk/php/X.Y/bin/php` per a WP-CLI. Com a verificació creuada, una petició HTTP a un probe temporal retorna `PHP_VERSION` i les extensions.
- **Versions:** core, plugins (actius i inactius), temes (actiu i pare), drop-ins i mu-plugins; versió de MySQL/MariaDB.
- **Actualitzacions pendents:** de l'API de WordPress.org i del transient `update_plugins`/`update_themes` del lloc, que inclou els plugins premium amb llicència registrada.
- **Estat previ:** WooCommerce > Estat (versió de la BD, plantilles sobreescrites), cron, mode manteniment, espai lliure al disc.
- **Prerequisits:** espai suficient per a la còpia, WP-CLI disponible (o l'instal·la en una ruta de l'eina), permisos d'escriptura.

Si un prerequisit falla, el lloc queda bloquejat per a l'actualització i es notifica el motiu.

## Compatibilitat amb PHP

Una actualització només s'aplica si supera les tres capes; si no, queda omesa i l'informe n'explica el motiu.

1. **Requisits declarats:** el `Requires PHP` i el `Requires at least` de cada paquet es comparen amb el PHP i el WordPress del lloc, incloses les dependències entre components (per exemple, una extensió de WooCommerce que exigeix una versió de WooCommerce que no es pot instal·lar).
2. **Anàlisi estàtica:** el paquet nou es descarrega al servidor de l'eina i es passa per PHPCompatibility amb `testVersion` igual al PHP del lloc. Un error bloqueja l'actualització; els avisos van a l'informe.
3. **Execució real:** després d'actualitzar, la verificació revisa el log d'errors de PHP i els fatals. Qualsevol fatal nou dispara el rollback.

Els paquets descarregats en el pas 2 són els mateixos que s'instal·len: es guarden com a artefactes de l'execució amb el seu checksum. Així el staging i la producció reben exactament el mateix codi.

Context: WordPress 7.0 exigeix PHP 7.4 com a mínim, i WooCommerce 11.6 (febrer de 2027) exigirà PHP 8.1. Els llocs per sota d'aquests mínims quedaran congelats en branques antigues, i l'informe ho ha de destacar.

Fonts: [WordPress — Dropping support for PHP 7.2 and 7.3](https://make.wordpress.org/core/2026/01/09/dropping-support-for-php-7-2-and-7-3/) · [WooCommerce — PHP 8.1+ per a la 11.6](https://developer.woocommerce.com/?p=8771554)

## Còpies de seguretat

Cap actualització comença sense una còpia completa i verificada del lloc.

- **Contingut:** dump de la base de dades (totes les taules del prefix) i arxiu de fitxers. La còpia de fitxers pot excloure `uploads` si el perfil ho indica, perquè les actualitzacions no hi toquen.
- **Destinacions per lloc, una o diverses:** el mateix servidor del lloc (ràpida, per al rollback immediat) i/o externes (SFTP, SCP, S3 compatible, Dropbox, i qualsevol backend d'rclone). Els drivers són connectables: afegir-ne un és implementar una interfície `BackupTarget`.
- **Integritat:** checksum SHA-256 de cada arxiu, verificat després de transferir-lo. El dump es valida comprovant que conté totes les taules esperades.
- **Retenció:** per destinació (per exemple, els últims N o els dels últims X dies), amb neteja automàtica.
- **Restauració:** `wpup backup:restore` a partir de qualsevol còpia registrada, al lloc original o a un staging.

Les credencials de les destinacions es guarden xifrades a la base de dades (Laravel encryption) i mai surten als logs.

## Estratègies d'actualització

Cada lloc té una estratègia per defecte, que es pot sobreescriure en cada execució amb `--strategy`.

> _Diagrama: flux d'una execució · dues estratègies, rollback automàtic (vegeu el document original)._

El staging és un pas previ opcional: si passa, producció segueix igualment el flux complet de còpia, actualització i verificació.

**In-place**

1. Inventari i pla: què s'actualitza, què s'omet i per què.
2. Còpia de seguretat i línia base de verificació (captures i errors abans de tocar res).
3. Mode manteniment, opcional per lloc.
4. Actualització en l'ordre core → WooCommerce → plugins → temes → traduccions, seguida de `wp wc update-db` i el buidatge de memòria cau.
5. Verificació contra la línia base.
6. Si la verificació falla, rollback; si passa, fi del manteniment i informe.

**Staging primer**

1. Còpia del lloc al servidor de l'eina i aixecament d'un contenidor Docker amb el mateix PHP i MySQL/MariaDB.
2. Neutralització del staging: `search-replace` de l'URL, correus capturats amb Mailpit, WP-Cron desactivat, webhooks i passarel·les de pagament en mode test, sortida de xarxa restringida i `noindex`.
3. Actualització i verificació al staging.
4. Si passa, s'aplica a producció el mateix conjunt d'artefactes amb el flux in-place, que torna a fer còpia i verificació.

El staging en Docker no és idèntic a l'hosting (extensions, configuració de PHP, memòria cau del servidor), així que la verificació a producció no se salta mai.

**Rollback i comandes noves**

Un rollback de fitxers és sempre segur. Restaurar la base de dades en una botiga, en canvi, pot fer perdre comandes, clients o pagaments entrats després de la còpia. Per això:

- Si no hi ha hagut escriptures noves a les taules de comandes i usuaris des de la còpia, es restaura la base de dades automàticament.
- Si n'hi ha hagut, només es restauren els fitxers, la base de dades no es toca i s'avisa l'operador amb el llistat de comandes afectades perquè decideixi.
- El mode manteniment durant l'actualització redueix aquest risc. És recomanable a les botigues amb trànsit.

## Verificació

La referència és sempre la línia base capturada en la mateixa execució just abans d'actualitzar, mai una captura antiga. Així els canvis de contingut normals del lloc no donen falsos positius.

**Diff visual**

- URLs per lloc al perfil, amb un conjunt per defecte: inici, categoria, producte, carretó, checkout, compte i una pàgina estàtica.
- Dos viewports: escriptori (1440 px) i mòbil (390 px), a pàgina completa.
- Abans de capturar: s'esperen les fonts i el `networkidle`, s'aturen les animacions i s'amaguen els bàners de cookies.
- Zones emmascarades per selector CSS (sliders, preus dinàmics, dates, xats).
- Llindar de diferència per URL; per sobre, l'informe inclou la captura d'abans, la de després i el diff destacat.

**Flux de compra (sense pagament)**

- Afegir al carretó el producte de prova del perfil (simple o variable), anar al carretó i carregar el checkout.
- Comprovar que el checkout mostra els mètodes d'enviament i de pagament esperats, i que l'AJAX d'`update_order_review` respon sense errors.
- No s'envia mai la comanda.

**Errors tècnics**

- Codis HTTP de totes les URLs i dels recursos que carreguen (404 i 5xx nous).
- Errors nous de la consola JS respecte de la línia base.
- Fatals i warnings nous al log de PHP (`debug.log` o el log d'errors del domini).

El resultat de cada comprovació és `ok`, `avís` o `error`. Un sol `error` fa fallar la verificació i dispara el rollback; els avisos només van a l'informe.

## Agent PHP temporal (llocs SFTP)

En els llocs sense SSH, l'eina puja per SFTP un script PHP de vida curta que exposa les mateixes operacions que el `SshDriver` per HTTPS, i l'esborra en acabar.

**Compatibilitat:** sintaxi de PHP 5.6 com a mínim (sense tipus escalars, `??=`, arrow functions, `match` ni nullsafe), provada en CI contra totes les versions des de la 5.6 fins a la 8.5. És un sol fitxer sense dependències.

**Seguretat:**

- Nom de fitxer aleatori dins d'un directori aleatori.
- Cada petició va signada amb HMAC-SHA256 (secret únic per execució, timestamp i nonce) per evitar repeticions.
- Opcionalment, només accepta peticions de la IP del servidor de l'eina.
- Caducitat dura: el mateix script es nega a respondre passat un TTL (per exemple, 2 hores) encara que no s'hagi esborrat.
- Esborrament garantit al final de l'execució, també quan falla; un job de neteja revisa els agents orfes.

**Operacions:**

| Operació | Implementació |
| --- | --- |
| `info` | Carrega WordPress (`wp-load.php`) i retorna versions, PHP, extensions i actualitzacions pendents |
| `db_dump` | `mysqldump` si `exec` està disponible; si no, dump en PHP per trossos |
| `files_archive` | `ZipArchive` per trossos, reprenible |
| `apply_update` | Classes `Plugin_Upgrader`, `Theme_Upgrader` i `Core_Upgrader` amb un paquet pujat per l'eina |
| `maintenance` | Crea o esborra `.maintenance` |
| `restore` | Restaura fitxers i base de dades des d'un arxiu |
| `php_log` | Retorna les línies noves del log d'errors |

Els hostings compartits tenen límits de temps i memòria: totes les operacions llargues van per trossos, són reprenibles i informen del progrés.

## Multiusuari, bloquejos i auditoria

Diverses persones fan servir l'eina al mateix servidor, i cada acció queda atribuïda a qui l'ha llançada.

- **Identitat:** cada operador té un usuari de sistema propi al servidor, mapat a un usuari de l'eina. La CLI identifica l'operador per l'usuari Unix; no hi ha comptes compartits.
- **Rols:** `admin` (gestiona llocs, credencials i usuaris) i `operador` (llança execucions i rollbacks, consulta informes).
- **Bloqueig per lloc:** un lock atòmic (Redis) impedeix dues execucions simultànies sobre el mateix lloc. Qui el troba ocupat veu qui el té, des de quan i quina execució és.
- **Límits de concurrència:** un màxim global i un màxim per servidor d'hosting, per no saturar un mateix Plesk.
- **Auditoria:** la taula `audit_log` és només d'inserció i registra qui, què, sobre quin lloc, quan i amb quin resultat. Inclou els canvis de configuració i l'accés a credencials.

## Logs, informes i notificacions

Cada execució genera un informe consultable des de l'eina i un resum que s'envia pels canals configurats.

**Logs**

- Estructurats (JSON) per pas, a `run_steps`, amb la sortida completa de cada comanda.
- Els secrets s'emmascaren abans d'escriure'ls.
- Retenció configurable; les execucions amb error es conserven més temps.

**Informe per execució**

- Capçalera: lloc, operador, estratègia, inici, final i resultat global.
- Taula de canvis: component, versió abans i després, i resultat (`actualitzat`, `omès` o `revertit`), amb el motiu de cada omissió (PHP incompatible, plugin congelat, sense llicència, dependència).
- Còpies: destinació, mida i checksum.
- Verificació: resultat per URL i viewport, amb les captures d'abans, després i diff quan hi ha diferència.
- Avisos de l'entorn: PHP o WordPress per sota del mínim de les properes versions, i components congelats.
- Format HTML desat a la base de dades i al disc, amb exportació a PDF.

**Consulta des de l'eina**

- `wpup runs` (llistat filtrable) i `wpup report <run>` (resum a la terminal o obertura de l'HTML).
- Visor web de només lectura amb autenticació per als informes i les captures (vegeu les decisions obertes).

**Notificacions**

| Canal | Contingut |
| --- | --- |
| Correu electrònic | Informe HTML complet |
| Telegram | Resum curt i enllaç a l'informe |
| Slack | Resum curt i enllaç a l'informe |

Els canals i la severitat mínima (tot, només avisos o només errors) es configuren per lloc i per operador. També hi ha un resum agregat quan s'executa per a diversos llocs.

## Interfície CLI

Totes les comandes accepten un lloc, un tag (`--tag=botigues`) o `--all`, i les que fan canvis tenen `--dry-run`.

| Comanda | Funció |
| --- | --- |
| `wpup site:add` / `site:import` | Alta d'un lloc, o importació en bloc des d'un CSV o YAML |
| `wpup site:edit` / `site:test` | Edició del perfil; prova de connexió i de prerequisits |
| `wpup inventory` | Inventari de versions, PHP i actualitzacions pendents |
| `wpup plan` | Què s'actualitzaria, què s'ometria i per què; no toca res |
| `wpup update` | Execució completa amb l'estratègia del lloc o `--strategy` |
| `wpup backup` / `backup:list` / `backup:restore` | Còpies manuals, llistat i restauració |
| `wpup verify` | Verificació sola, sense actualitzar, contra una línia base nova |
| `wpup rollback <run>` | Rollback manual d'una execució |
| `wpup runs` / `report <run>` | Historial i informes |
| `wpup lock:status` / `lock:release` | Bloquejos actius; alliberament forçat (només `admin`) |
| `wpup agent:cleanup` | Cerca i esborra agents orfes als llocs SFTP |

Les execucions llargues s'encuen i la comanda retorna l'identificador. `--follow` mostra el progrés en directe i `--wait` espera que acabi.

## Requisits no funcionals

- **Seguretat:** credencials xifrades en repòs i claus SSH dedicades per lloc o per servidor. El servidor de l'eina és accessible només per VPN o amb una llista d'IPs permeses.
- **Idempotència i represa:** cada pas es pot tornar a executar sense efectes duplicats. Una execució interrompuda (caiguda del worker o de la xarxa) es reprèn o es reverteix, però mai queda a mitges en silenci.
- **Timeouts:** per pas i per execució. Si es supera, el pas falla i entra el rollback.
- **Rendiment:** l'objectiu és actualitzar \~50 llocs en una finestra nocturna, amb una concurrència configurable (inicialment, 4 llocs alhora i 1 per servidor d'hosting).
- **Observabilitat:** Horizon per a les cues; mètriques de durada per pas i de taxa d'errors per lloc.
- **Tests:** tests d'integració contra llocs WordPress de prova en Docker, amb diverses versions de PHP, i matriu de CI per a l'agent (PHP 5.6–8.5).
- **Còpia de l'eina:** la base de dades de l'eina i els artefactes es copien diàriament fora del servidor.

## Fases d'implementació

Cada fase és utilitzable per si sola. El primer guany real arriba a la fase 2: inventari i còpies de tots els llocs.

1. **Base:** model de dades, usuaris, locks, auditoria, `SshDriver` i `wpup site:*`.
2. **Inventari i còpies:** detecció del PHP real, `inventory`, `plan`, còpies amb destinacions locals, SFTP i S3, i restauració.
3. **Actualització in-place:** filtre de compatibilitat (les tres capes), ordre d'actualització, mode manteniment i rollback de fitxers i de base de dades amb el control de comandes noves.
4. **Verificació:** worker de Playwright, línia base, diff visual, checkout i errors tècnics.
5. **Informes i notificacions:** HTML, correu, Telegram, Slack i visor de només lectura.
6. **Agent SFTP:** `AgentDriver`, seguretat, operacions per trossos i matriu de CI.
7. **Staging Docker:** clonatge, neutralització i promoció d'artefactes a producció.
8. **Destinacions addicionals:** Dropbox, rclone i les que calguin.

## Decisions obertes

- [ ] Versió de PHP i de WordPress més antiga en producció (cal l'inventari). Determina si l'agent es pot quedar en PHP 5.6 o ha de baixar més.
- [ ] Visor d'informes: web de només lectura dins de la mateixa aplicació Laravel, o només fitxers HTML servits estàticament.
- [ ] Execucions programades (per exemple, `plan` automàtic setmanal amb avís) o sempre manuals.
- [ ] Política d'actualitzacions majors (WooCommerce X.0, WordPress 7.x): automàtiques, només en mode staging o només amb aprovació explícita.
- [ ] Retenció per defecte de còpies, logs i captures.
- [ ] Base de dades de l'eina: PostgreSQL o MariaDB.
- [ ] Nom definitiu de l'eina.
