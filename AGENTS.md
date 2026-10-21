# AGENTS.md — uzero_wpup

Instruccions per a qualsevol agent de codi (Claude Code, Codex…) i per a les persones que treballen en aquest repositori. Llegeix-lo sencer abans de començar una tasca.

> **No afegeixis cap `CLAUDE.md`, `.claude/CLAUDE.md` ni `CLAUDE.local.md` al repositori.** Claude Code (v2.1.277 o posterior) llegeix aquest `AGENTS.md` directament, però deixa de fer-ho si troba qualsevol d'aquests fitxers. Les instruccions personals van a `~/.claude/CLAUDE.md`.

## Què és

**uzero wpup** (nom provisional; el binari de la CLI és `wpup`) actualitza de forma segura una flota de WordPress/WooCommerce. Fa còpia de seguretat, comprova la compatibilitat amb el PHP real del lloc, actualitza, verifica que el lloc es veu i es comporta igual, i reverteix si no és així. Cada execució queda registrada i genera un informe.

- Especificació completa: [docs/especificacio.md](docs/especificacio.md)
- Mòdul F9, clients i facturació amb Quipu (s'implementa al final): [docs/modul-facturacio-quipu.md](docs/modul-facturacio-quipu.md)
- Decisions d'arquitectura: [docs/adr/](docs/adr/)
- Tasques: Plane, projecte **UZEROWPUP** (identificadors `UZEROWPUP-<n>`)

**Ordre de precedència** si hi ha contradiccions: ADR acceptat > especificació > aquest fitxer > descripció de la tasca a Plane. Si trobes una contradicció, no la resolguis pel teu compte: comenta-la a la tasca de Plane i pregunta.

## Abast: el que l'eina no fa mai

- Canviar la versió de PHP ni la configuració del servidor dels llocs. Només ho detecta i avisa.
- Fer pagaments reals ni enviar comandes al checkout.
- Gestionar llicències de plugins premium. Fa servir les que ja hi ha registrades al lloc.

Si una tasca sembla demanar alguna d'aquestes coses, atura't i pregunta.

## Estructura del repositori

```
app/            Orquestrador Laravel (comandes Artisan, dominis, drivers, feines)
config/wpup.php Configuració pròpia de l'eina (concurrència, timeouts, mínims de PHP/WP…)
verifier/       Worker Node + TypeScript + Playwright (captures, diff, checkout, errors)
agent/          Agent PHP temporal per a llocs SFTP: UN sol fitxer, sintaxi PHP 5.6
docker/         Entorn de desenvolupament, llocs WordPress de prova i imatges de staging
tests/          Pest: Unit/, Feature/, Integration/ (contra els llocs de prova de Docker)
docs/           Especificació, ADRs, runbooks i esquemes
wpup            Binari d'entrada (delega a php artisan)
Makefile        Totes les comandes de desenvolupament i la CI local
```

Fins que es facin les tasques UZEROWPUP-1 a UZEROWPUP-4, part d'aquesta estructura encara no existeix. No en creïs cap peça fora de la tasca que li correspon.

## Stack

| Peça | Versió / eina |
| --- | --- |
| Orquestrador | PHP 8.3+, Laravel 13, Horizon, Redis |
| BD de l'eina | PostgreSQL (≥ 17), socket Unix i autenticació `peer` ([ADR-0004](docs/adr/0004-base-de-dades.md)); els tests corren contra PostgreSQL, mai SQLite |
| Secrets | `SecretStore` amb xifratge en sobre (libsodium) i clau mestra segellada amb el TPM 2.0 virtual de la VM via `systemd-creds` (ADR-0002) |
| Tests PHP | Pest (unitaris, de funcionalitat i d'integració) |
| Anàlisi estàtica | Larastan (PHPStan) al nivell `max`, sense baseline nova |
| Estil PHP | Pint, preset `laravel` |
| Verificador | Node LTS, TypeScript `strict`, Playwright, pixelmatch, ESLint, Vitest |
| Agent | PHP 5.6–8.5, un sol fitxer i sense dependències (mínim pendent de UZEROWPUP-7) |
| Compatibilitat | PHP_CodeSniffer + PHPCompatibility |

No afegeixis dependències noves (Composer o npm) sense justificar-les a la descripció del PR. Si una dependència condiciona l'arquitectura, cal un ADR.

## Comandes

La CI de GitHub **no es fa servir**. Tota la CI corre en local i `make ci` és l'única porta de qualitat.

```bash
make up              # aixeca l'entorn de desenvolupament (Docker Compose)
make fixtures-up     # aixeca els llocs WordPress/WooCommerce de prova
make fixtures-reset  # torna els llocs de prova a l'estat conegut
make lint            # Pint --test + ESLint
make stan            # Larastan + tsc --noEmit
make test            # Pest (unitaris + funcionalitat + integració)
make verifier        # tests del verificador (Vitest)
make agent-matrix    # tests de l'agent contra PHP 5.6 … 8.5
make ci              # tot l'anterior; ha de sortir amb codi 0
make hooks           # instal·la el hook pre-push (.githooks/)
```

- `make ci` ha de passar **abans de cada push**. El hook pre-push l'executa. No el saltis mai (`--no-verify` està prohibit).
- Si `make ci` falla per una cosa que no has tocat, no la tapis: informa'n a la tasca de Plane.
- Les execucions llargues (la matriu de l'agent, la integració) es poden llançar en segon pla, però el resultat s'ha de llegir abans de fer push.

## Flux de treball amb Plane

Estats: `Backlog` → `Todo` → `In Progress` → `In Review` → `Done` (o `Cancelled`).

1. **Tria la tasca.** Treballa només en tasques en estat `Todo` o en les que t'assigni explícitament una persona. Abans de començar, comprova les relacions *blocked by* de la tasca: si encara hi ha bloquejos oberts, no la comencis i avisa.
2. **Comença.** Passa la tasca a `In Progress` i crea la branca (vegeu Git).
3. **Abast.** Fes el que diuen els criteris d'acceptació, ni més ni menys. Si descobreixes feina nova, no l'afegeixis al PR: proposa-la com a comentari a la tasca (o crea una tasca nova a `Backlog` si te l'han demanat).
4. **Obre el PR.** Passa la tasca a `In Review` i comenta-hi l'enllaç del PR i el resum de `make ci`.
5. **Fusió.** Una persona revisa i fusiona el PR. No fusionis mai el teu propi PR si no t'ho demanen explícitament.
6. **Tanca.** Quan el PR s'ha fusionat (comprova-ho amb `gh pr view <n> --json state`), passa la tasca a `Done` amb un comentari breu del que s'ha lliurat.

Els comentaris a Plane s'escriuen en català i són breus: què s'ha fet, què queda pendent i qualsevol decisió que la persona revisora hagi de conèixer.

## Quin model fa cada feina

Per defecte, la feina la fa **Sonnet** (`claude-sonnet-5-5`, àlies `sonnet`). **Opus** (`claude-opus-5-5`, àlies `opus`) es reserva per a planificar, verificar i resoldre tasques complexes.

| Feina | Model |
| --- | --- |
| Implementar una tasca ben definida (criteris d'acceptació clars, un sol component) | Sonnet |
| Tests, refactors locals, documentació, comandes CLI, migracions senzilles, canals de notificació, destinacions de còpia | Sonnet |
| Cerques i exploració del codi, lectura de logs, execució de `make ci` | Sonnet (o Haiku per a cerques mecàniques) |
| **Planificar**: desglossar una tasca, dissenyar l'enfocament, redactar ADRs | Opus |
| **Verificar**: revisar el diff abans d'obrir el PR i comprovar els criteris d'acceptació | Opus |
| **Tasques complexes** (vegeu els criteris de sota) | Opus |

Una tasca és **complexa** si té l'etiqueta `opus` a Plane, o bé si compleix qualsevol d'aquests criteris:

- Toca una de les regles innegociables de seguretat (credencials i claus, sudo i permisos del servidor, HMAC, rollback de BD, checkout, neutralització del staging, dades de facturació).
- Defineix o canvia una interfície compartida (`SiteDriver`, `BackupTarget`, el contracte del verificador) o el motor d'execucions.
- Afecta més d'un component (orquestrador + agent, orquestrador + verificador…).
- Té ambigüitats o contradiccions que s'han de resoldre abans d'implementar.

Com s'aplica:

1. **Opus planifica** la tasca: llegeix la tasca, l'especificació i els ADRs, i escriu el pla a la tasca de Plane com a comentari.
2. **Sonnet implementa** el pla en subagents (`model: sonnet`), llevat que la tasca sigui complexa; aleshores implementa Opus.
3. **Opus verifica** el resultat (diff, tests, criteris d'acceptació i regles de seguretat) abans de passar la tasca a `In Review`.

Si una sessió s'executa amb Opus, delega a subagents Sonnet tot el que sigui implementació no complexa. Si una sessió amb Sonnet troba que la tasca és complexa, atura't i demana-ho a la persona responsable en lloc de continuar.

## Git i PRs

- Repositori: `github.com/arnauuc/uzero_wpup`, branca principal `main`. No facis push directe a `main` ni force-push a branques compartides.
- Una branca i un PR per tasca: `UZEROWPUP-<n>-descripcio-curta` (p. ex. `UZEROWPUP-21-motor-execucions`).
- Commits amb [Conventional Commits](https://www.conventionalcommits.org/), en català, amb l'identificador de Plane al peu:

  ```
  feat(orquestrador): afegeix el lock atòmic per lloc

  Refs: UZEROWPUP-19
  ```

  Tipus: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`, `build`. Àmbits: `orquestrador`, `verificador`, `agent`, `staging`, `copies`, `informes`, `infra`, `docs`, `adr`.
- Títol del PR: `UZEROWPUP-<n>: <títol de la tasca>`. Cos del PR: què canvia i per què, com s'ha provat, el resum de `make ci` i qualsevol risc o decisió pendent.
- PRs petits i revisables. Si una tasca necessita més de ~600 línies de canvi (sense comptar fitxers generats), proposa dividir-la abans de continuar.

## Llengua

| Què | Llengua |
| --- | --- |
| Identificadors, noms de fitxer, comentaris al codi i missatges de log | Anglès |
| Documentació (`docs/`, ADRs, README, aquest fitxer) | Català |
| Commits, títols i cossos de PR, comentaris a Plane | Català |
| Sortida de la CLI i informes per a l'operador | Català, amb cadenes traduïbles (`lang/ca`) |

## Regles innegociables de seguretat

Una fallada en aquesta eina pot tombar una botiga en producció o fer perdre comandes. Aquestes regles passen per davant de la velocitat:

1. **Mai contra llocs reals des del desenvolupament.** Els tests i les proves manuals es fan només contra els llocs de prova de Docker. Cap credencial real dins del repositori, als fixtures ni als tests.
2. **Secrets** ([ADR-0002](docs/adr/0002-gestio-de-secrets.md)). Les credencials es guarden només a través del `SecretStore`, amb xifratge en sobre i la clau mestra segellada amb el TPM (`systemd-creds`, `tpm2+host`); mai amb l'`APP_KEY` ni en fitxers en clar. Les claus SSH es fan servir en memòria o en un `ssh-agent` efímer, i no s'escriuen mai al disc. Les credencials no surten mai en clar als logs, a les excepcions, a la sortida de la CLI, als informes ni a `run_steps`. Tota sortida remota passa pel redactor de secrets. Mai facis `dd()`, `dump()` ni `var_dump()` d'objectes que continguin credencials.
3. **Cap actualització sense una còpia completa i verificada** (SHA-256 comprovat a cada destinació).
4. **Base de dades d'una botiga:** si hi ha hagut escriptures noves a les comandes o als usuaris des de la còpia, mai es restaura automàticament. Només es restauren els fitxers i s'avisa amb el llistat de comandes afectades.
5. **Checkout:** el verificador no prem mai el botó de fer la comanda i bloqueja per xarxa la petició `?wc-ajax=checkout`.
6. **Staging neutralitzat abans d'arrencar:** sense correus reals, sense cron, passarel·les i webhooks en mode test, sortida de xarxa restringida i `noindex`.
7. **Agent PHP:** signatura HMAC-SHA256 amb nonce i timestamp, TTL dur, nom i directori aleatoris, i esborrament garantit (`finally`) més el job de neteja. Les peticions no autenticades reben un 404 genèric.
8. **Comandes de shell remotes:** arguments sempre escapats. Mai concatenis entrada no validada dins d'una comanda.
9. **Comandes que fan canvis:** totes tenen `--dry-run`. Les destructives (restauració, alliberament de locks, esborrats) demanen confirmació explícita i queden a `audit_log`.
10. **`audit_log` és només d'inserció.** Cap codi no hi fa `update` ni `delete`.
11. **Quipu és només de lectura.** L'eina no crea, no modifica i no esborra res a Quipu (ni factures, ni contactes, ni etiquetes). Les dades de facturació no surten mai fora de l'eina, i els operadors només en veuen l'estat; els imports i les factures només els veuen els `admin`.
12. **Accés als servidors.** Tota l'eina corre amb l'usuari de sistema `wpup` i els operadors l'executen via `sudo`; cap operador pot llegir el `.env`, la clau mestra ni la BD. Als servidors Plesk s'entra amb l'usuari de la subscripció (mai `root`), amb la clau restringida a l'IP de l'eina i amb la host key fixada.
13. **Xarxa de la VM** ([ADR-0003](docs/adr/0003-xarxa-i-acces.md)). Només s'hi entra per la tailnet (Tailscale): cap port exposat a Internet ni a la LAN, cap contenidor publicat a `0.0.0.0`, Tailscale Funnel prohibit i sortida sempre per l'IP pública fixa (mai per un *exit node*).

## Regles d'arquitectura i de codi

**Arquitectura**

- L'orquestrador només parla amb els llocs a través de `SiteDriver` (`SshDriver`, `AgentDriver`), i amb les destinacions de còpia a través de `BackupTarget`. Cap codi de domini no sap si el lloc va per SSH o per agent.
- Totes les implementacions d'una interfície han de passar la mateixa suite de tests de contracte.
- **Execucions:** cada pas és idempotent, té timeout i desa la durada, la sortida resumida i la sortida completa emmascarada. Una execució interrompuda es reprèn o es reverteix, però mai queda a mitges en silenci.
- **Dades fresques:** cada execució comença amb un inventari nou, i la línia base de verificació es captura dins de la mateixa execució, just abans d'actualitzar.
- **Artefactes:** els paquets s'analitzen i s'instal·len des del servidor de l'eina amb el seu checksum. Staging i producció reben exactament els mateixos bytes. Mai es descarrega un paquet directament des del lloc.
- **Ordre d'actualització:** core → WooCommerce → plugins → temes → traduccions, i després `wp wc update-db` i el buidatge de memòria cau.
- **Compatibilitat en tres capes:** requisits declarats, anàlisi estàtica amb PHPCompatibility i execució real (fatals nous ⇒ rollback). Cada component omès porta el seu motiu.
- **PHP del lloc:** sempre el que serveix el web (handler del domini a Plesk i probe HTTP), mai el del CLI.
- **Concurrència:** lock atòmic per lloc a Redis i límits globals i per servidor d'hosting. Cap feina no se'ls salta.

**Codi PHP (orquestrador)**

- `declare(strict_types=1);` a tots els fitxers. Tipus a tots els paràmetres, retorns i propietats.
- Enums per als estats, els tipus i els resultats; DTOs o value objects immutables (`readonly`) entre capes, en lloc d'arrays associatius.
- La lògica de domini va a `app/Domain/…`. Les comandes Artisan són primes: validen l'entrada, criden un servei i formategen la sortida.
- Res de valors màgics al codi: els mínims de PHP/WP, els timeouts, els llindars i els límits van a `config/wpup.php`.
- Excepcions de domini específiques; no s'empassa cap excepció sense registrar-la.

**Agent (`agent/`)**

- Sintaxi compatible amb PHP 5.6 (o amb el mínim que fixi UZEROWPUP-7): sense tipus escalars, tipus de retorn, `??=`, arrow functions, `match`, nullsafe, `readonly`, enums ni arguments amb nom.
- Un sol fitxer, sense dependències. Totes les operacions llargues van per trossos, són reprenibles i informen del progrés.
- Qualsevol canvi a `agent/` ha de passar `make agent-matrix`.

**Verificador (`verifier/`)**

- TypeScript `strict`; entrada i sortida JSON versionades i validades.
- No té accés a les credencials dels llocs.

## Tests

- Tota funcionalitat nova porta tests, i cada correcció d'error porta un test que reprodueix l'error.
- Unitaris per a la lògica pura (comparació de versions, resolució de dependències, classificació ok/avís/error).
- Integració contra els llocs de prova de Docker per a drivers, còpies, actualització, rollback i verificació.
- Cada camí de seguretat té un test negatiu: secrets que no apareixen als logs, HMAC invàlid, repetició, TTL, BD amb comandes noves que no es restaura, checkout que no s'envia.
- No facis mock de la peça que estàs provant. Per als llocs, fes servir `FakeDriver` als unitaris i els llocs de prova a la integració.

## Decisions i ADRs

- Escriu un ADR a `docs/adr/NNNN-titol.md` (a partir de la [plantilla](docs/adr/0000-plantilla.md)) quan una decisió és difícil de revertir, afecta més d'un component o tria entre tecnologies.
- Un ADR comença com a `Proposat`. Només una persona el passa a `Acceptat`. No implementis res que depengui d'un ADR que encara no està acceptat.
- Decisions obertes (tasques a Plane amb l'etiqueta `adr`):

  | Tasca | Decisió | Bloqueja |
  | --- | --- | --- |
  | UZEROWPUP-7 | PHP/WP mínims en producció i PHP mínim de l'agent | F6 (agent) |
  | UZEROWPUP-8 | Visor d'informes: web Laravel o HTML estàtic | Visor de F5 |
  | UZEROWPUP-9 | Execucions programades o sempre manuals | — |
  | UZEROWPUP-10 | Política d'actualitzacions majors | Capa 1 de compatibilitat (F3) |
  | UZEROWPUP-11 | Retenció per defecte de còpies, logs i captures | Neteja de F2, logs de F5 |
  | UZEROWPUP-12 | Nom definitiu de l'eina | — |
  | UZEROWPUP-122 | Acceptar l'ADR-0002 de gestió de secrets i accés als servidors | SecretStore, identitat via sudo, SshDriver, desplegament i comandes de claus |
  | UZEROWPUP-125 | Acceptar l'ADR-0003 de xarxa i accés a la VM (tailnet) | Xarxa de la VM i desplegament |
  | UZEROWPUP-110 | Criteri per identificar les factures de manteniment a Quipu (etiqueta o concepte) | Classificador i sincronització de F9 |

## Quan has de parar i preguntar

- La tasca contradiu l'especificació o un ADR, o no té prou criteris d'acceptació.
- Cal tocar credencials, claus o configuració del servidor de l'eina.
- Cal una dependència nova que condiciona l'arquitectura, o un canvi d'esquema que afecta dades existents.
- `make ci` falla per una causa aliena a la teva tasca.
- Qualsevol acció sobre un lloc real, en qualsevol moment.
