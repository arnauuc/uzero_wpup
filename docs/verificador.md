# Verificador: protocol amb l'orquestrador

El verificador (`verifier/`) és un worker Node + TypeScript + Playwright que visita les pàgines públiques d'un lloc i en desa les captures i els errors. Aquest document en fixa el contracte amb l'orquestrador (UZEROWPUP-64). Les captures estables (UZEROWPUP-65) són a la secció «Captures estables». El diff (UZEROWPUP-68), el flux de compra (UZEROWPUP-69) i on entra al pipeline d'actualització (UZEROWPUP-72) es fan més endavant.

## Invocació: procés, no cua

L'orquestrador l'executa com a **procés fill** des del pas de l'execució (`VerifyStep` → `VerifierClient`), al mateix worker de Laravel:

```
setsid node verifier/dist/bin.js < entrada.json
```

- La comanda és un array d'arguments (`wpup.verifier.node_binary` i `wpup.verifier.script`), mai una cadena de shell. Si falta Node o el `dist/` compilat, el pas falla amb un missatge que ho diu (no amb un codi 127).
- L'entrada va per **stdin**. La sortida es desa a `<output_dir>/result.json` (0600, escrit en un fitxer temporal i reanomenat). El directori de sortida és privat (0700) i n'hi ha un per execució i per pas: `wpup.verifier.work_dir/run-<id>/<pas>/`.
- Límits de temps, de dins cap a fora:
  1. Cada navegació i cada captura tenen el seu límit (`timeouts.navigation_ms`, `timeouts.screenshot_ms`), retallat sempre al temps que queda fins a `timeouts.total_ms`.
  2. Quan s'acaba `total_ms`, el verificador para tot sol, anota les pàgines no visitades a `verifier_errors`, marca `partial: true` i escriu igualment el `result.json` amb el que ha fet.
  3. El `VerifierClient` atura el procés a `wpup.verifier.timeout_seconds`. La configuració obliga que `total_ms + navigation_ms + screenshot_ms` hi càpiga, i tot plegat ha de cabre dins del timeout del pas (`step_timeout_seconds`).
- **Aturada neta:** el verificador s'executa en una sessió i un grup de processos propis (`setsid`). En arribar al límit, el client envia `SIGTERM` al grup sencer, espera `stop_grace_seconds` i després envia `SIGKILL` al grup. Així no queda cap Chromium orfe. En rebre `SIGTERM`, el verificador tanca el navegador.
- **Sortida limitada:** el client no desa stderr (hi pot haver traces o contingut de pàgines). De stdout en guarda com a màxim `max_stdout_bytes`, i el buffer intern es buida a cada lectura. Si stdout o stderr superen el seu límit (`max_stdout_bytes`, `max_stderr_bytes`), el client atura el procés.

**Per què un procés i no una cua pròpia (Redis/BullMQ):** només hi ha una actualització alhora a tota l'eina (ADR-0006), així que una cua no aporta paral·lelisme. El pas ja té timeout, reintent per represa i registre a `run_steps`. Amb un procés no cal cap servei llarg nou ni cap canal autenticat entre Node i Laravel, i el verificador no comparteix Redis amb l'orquestrador. Si algun dia cal verificar en paral·lel o en una altra màquina, es pot posar el mateix contracte JSON darrere d'una cua sense canviar-lo.

## Sense credencials

- El procés rep un entorn **net**: `PATH` (`wpup.verifier.path`), `HOME` (el directori de sortida), `LANG` i, si està configurat, `PLAYWRIGHT_BROWSERS_PATH`. El `VerifierClient` esborra explícitament totes les variables heretades (`getenv()`, `$_ENV`, `$_SERVER`), perquè Symfony Process passaria l'entorn sencer del worker (`DB_*`, `APP_KEY`, `WPUP_DEV_KEK`, `CREDENTIALS_DIRECTORY`…). Ho comprova un test negatiu (`tests/Feature/Verification/VerifierClientTest.php`).
- L'entrada només conté dades públiques: el nom i l'URL pública del lloc i els camins de les pàgines. El verificador no parla mai amb el `SecretStore`, la base de dades ni els drivers.
- Els camins són relatius a `base_url` (comencen per `/` i no per `//`) i l'URL ha de ser `http` o `https`: el verificador només visita el lloc públic.
- Cada pàgina s'obre en un context de navegador nou, sense galetes, sense emmagatzematge i sense service workers. La petició `?wc-ajax=checkout` queda bloquejada per xarxa (regla 5 d'AGENTS.md).

## Entrada (versió 1)

```json
{
  "version": 1,
  "run_id": 42,
  "site": { "name": "botiga", "base_url": "https://botiga.example" },
  "urls": [{ "path": "/", "profile": "home" }, { "path": "/botiga/", "profile": "category" }],
  "viewports": [{ "name": "desktop", "width": 1440 }, { "name": "mobile", "width": 390 }],
  "masks": [".slider", ".price-live"],
  "hide_selectors": [".promo-popup"],
  "capture": { "prepare_ms": 30000, "max_height": 20000 },
  "thresholds": { "max_diff_ratio": 0.01 },
  "output_dir": "/var/lib/wpup/verifier/run-42/verify-baseline",
  "timeouts": { "navigation_ms": 60000, "screenshot_ms": 30000, "total_ms": 1080000 }
}
```

L'esquema és a `verifier/src/contract.ts` (zod, objectes estrictes: cap camp desconegut). Límits: com a màxim 50 URLs, 4 viewports (de 320 a 3840 px d'amplada) 50 selectors de màscara, 50 de `hide_selectors` i 1 MiB d'entrada. `hide_selectors` i `capture` (`prepare_ms`: pressupost de preparació per pàgina, 1–120 s; `max_height`: alçada on es retalla la captura, 1.000–100.000 px) són **opcionals**: afegits compatibles, així que el protocol continua a la versió 1. L'orquestrador els envia des de `config/wpup.php` (`verifier.hide_selectors`, `prepare_ms`, `max_page_height`) només si estan definits. Cada selector de `hide_selectors` es rebutja si podria sortir de la seva regla CSS (claus, `/* */`, `@`, `;`, cometes o parèntesis sense tancar). `thresholds` es fa servir a partir del diff (UZEROWPUP-68). Els valors per defecte són a `config/wpup.php` (`verifier`).

## Captures estables

Dos viewports per defecte, `desktop` (1440 px) i `mobile` (390 px), que l'orquestrador envia a l'entrada (`config/wpup.php`, `verifier.viewports`). Les captures són sempre de pàgina completa (`fullPage`) i la finestra fa 900 px d'alçada. Abans de cada captura (`verifier/src/capture.ts`, `preparePage`):

1. **Xarxa i tipus de lletra:** `networkidle` en carregar, i després, un cop fet el recorregut i les imatges, una **finestra de quietud** pròpia (res en vol i cap petició nova en 500 ms; un segon `networkidle` no serviria, perquè Playwright ja l'ha donat per bo). Les imatges i els tipus de lletra (`document.fonts.ready`) s'esperen en paral·lel. Una pàgina que no arriba mai a estar quieta (polling, analítica) es captura tal com és: no és un error del lloc.
2. **Moviment aturat:** un CSS injectat porta animacions i transicions al seu estat final (durada ~0, una iteració, sense retard) i amaga el cursor de text; a més, Playwright captura amb `animations: 'disabled'` i `caret: 'hide'`. No s'eliminen les animacions (`animation: none`), perquè el contingut que apareix amb una animació quedaria amagat.
3. **Bàners de galetes amagats:** una llista de selectors coneguts (Cookiebot, CookieYes, Complianz, GDPR Cookie Consent, Borlabs, OneTrust, Cookie Notice, Moove, Didomi, Usercentrics, Iubenda…; `COOKIE_BANNER_SELECTORS`) més els `hide_selectors`. Cada selector es valida a la pàgina (`querySelector` dins d'un `try/catch`) i té el seu propi `<style>`: un selector invàlid s'ignora amb un avís i no inutilitza els altres. Els selectors de la llista no són amplis (cap `[class*="cookie"]`), perquè podrien amagar el `body`. **Amagar no és emmascarar:** les `masks` pinten un requadre sobre una zona que continua al disseny; els bàners s'eliminen del disseny.
4. **Bloquejos de desplaçament:** `html` i `body` passen a `overflow: visible`, perquè els bàners solen bloquejar el scroll i, sense això, ni la càrrega mandrosa ni la captura completa funcionarien. No es restaura: la captura es fa amb aquest estat, igual a la línia base i a la comprovació posterior.
5. **Imatges mandroses:** `loading="eager"` a imatges i iframes, còpia de `data-src`/`data-srcset` (o `data-lazy-*`) quan no hi ha `src`, recorregut fins al final i tornada a dalt (per als carregadors amb `IntersectionObserver`), i espera de les imatges amb límit.
6. Es torna a dalt de tot i es fa la captura.

**Pàgines infinites:** el recorregut s'atura al final del document, a `max_height` (20.000 px per defecte), a 100 passos o al límit de temps. Si el document fa més d'`max_height`, la captura es **retalla** a aquesta alçada (`clip`) i el resultat porta l'avís `capture cut at N px`, de manera que la línia base i la comprovació posterior són comparables encara que el lloc continuï creixent.

**Temps i errors:** tota la preparació comparteix un únic pressupost per pàgina (`capture.prepare_ms`, 30 s per defecte), retallat al temps que queda fins a `total_ms` menys 1 s reservat per a la captura, amb una sola regla de temps (`timeLeft`) compartida amb `verify.ts`. Cada `page.evaluate` té el seu límit (un script de la pàgina pot bloquejar-la). Qualsevol fallada de la preparació (un bloqueig, una navegació enmig, un selector) **no impedeix la captura**: es converteix en un avís i la pàgina es captura tal com és. Si el context s'ha destruït per una navegació, la preparació es reintenta una vegada. Els avisos van a `warnings` de cada resultat (afegit compatible a la sortida; l'orquestrador encara no els llegeix, ho farà el pipeline, UZEROWPUP-72).

**Content-Security-Policy:** els contextos es creen amb `bypassCSP: true`. Una CSP estricta bloquejaria el CSS neutre que s'hi injecta (animacions, bàners). El verificador només llegeix pàgines i mai envia formularis, de manera que la CSP no és una protecció que necessiti.

**Prova de no regressió:** `verifier/src/capture.test.ts` serveix pàgines locals amb animacions infinites, un tipus de lletra que arriba tard (si falta el fitxer de la lletra, el test falla), una imatge mandrosa, una `data-src` i un bàner que apareix tard. Fa dues captures de la mateixa pàgina en tots dos viewports i exigeix un diff pràcticament zero (< 0,01 % dels píxels), comparat amb un descodificador PNG mínim, sense cap dependència nova (el diff de debò, amb pixelmatch, és UZEROWPUP-68). També cobreix: la pàgina amb bàner igual que sense, una CSP estricta, un selector invàlid, un bloqueig de scroll, un scroll infinit retallat i una pàgina que es penja.

## Sortida (versió 1)

```json
{
  "version": 1,
  "run_id": 42,
  "results": [{
    "path": "/", "profile": "home", "viewport": "desktop",
    "http_status": 200,
    "screenshot": "desktop/0.png",
    "console_errors": ["Uncaught TypeError: …"],
    "resource_errors": [{ "url": "https://botiga.example/x.png", "status": 404, "failure": null }],
    "blocked_requests": [],
    "timings": { "navigation_ms": 830 },
    "warnings": [],
    "error": null
  }],
  "verifier_errors": [],
  "partial": false
}
```

- Un resultat per URL i viewport. `screenshot` és relatiu a `output_dir` (`<viewport>/<n>.png`); l'orquestrador rebutja qualsevol altre camí. `error` diu per què una pàgina no s'ha pogut carregar o capturar.
- `resource_errors` són els recursos de la pàgina (imatges, scripts, AJAX…) que responen 4xx/5xx o no carreguen. No hi entra el document principal, que és `http_status`, ni les peticions que bloqueja el mateix verificador. Aquestes últimes es mostren a `blocked_requests` (p. ex. `?wc-ajax=checkout`): són la regla de seguretat en acció, no un error del lloc.
- Límits per pàgina: 50 errors de consola, 100 errors de recursos, 20 peticions bloquejades i 500 caràcters per missatge o URL. L'orquestrador rebutja un `result.json` més gran que `wpup.verifier.max_result_bytes`.
- `verifier_errors` recull els problemes del verificador i no del lloc (p. ex. pàgines que no s'han visitat per manca de temps). `partial` és `true` si no s'han visitat totes les combinacions d'URL i viewport.
- L'orquestrador valida la sortida (`VerificationResult::fromProtocol`): una versió, un `run_id` o una forma diferents fan fallar el pas.

## Codis de sortida i errors

| Codi | Significat | stdout |
| --- | --- | --- |
| 0 | Verificació feta; el resultat és a `result.json` | (res) |
| 1 | Error intern (navegador, disc…) | una línia JSON, `code: "internal"` |
| 2 | Entrada invàlida; no s'ha fet res | una línia JSON, `code: "invalid_input"` |

```json
{"version":1,"error":{"code":"invalid_input","message":"input does not match protocol version 1","issues":["urls.0.path: invalid_format"]}}
```

Els `issues` diuen quin camp i quina regla han fallat, però mai el valor rebut. Els missatges no porten traces. El `VerifierClient` passa el missatge pel redactor de secrets i el converteix en una `VerifierFailed`. El `VerifierClient` primer redacta i després retalla.

El `VerifyStep` no jutja el lloc: fins a UZEROWPUP-72, només registra els errors HTTP i de consola. Sí que comprova la **cobertura**: desa a `data` el que s'esperava i el que s'ha obtingut per a cada URL i viewport. Si no hi ha cap captura, si en falta alguna o si el resultat és `partial`, el pas falla, perquè una línia base incompleta deixaria cega la comparació.

## Retenció de les captures

`ScreenshotCleanup` forma part de la neteja diària (ADR-0009) i treballa amb els directoris `work_dir/run-<id>/`:

- Les captures (`*.png`) es guarden 30 dies (`cleanup.screenshots_days`) si l'execució va acabar bé i cap `result.json` no té avisos ni errors. En cas contrari, es guarden 180 dies (`cleanup.screenshots_flagged_days`). Compten com a avís o error: una execució fallida o revertida, una pàgina amb error HTTP, de consola, de recursos o de càrrega, `verifier_errors`, un resultat `partial`, o un `result.json` absent o il·legible.
- Les de l'última execució de cada lloc que té captures no s'esborren mai. Tampoc les d'execucions que encara no han acabat.
- Només s'esborren les imatges: el `result.json` es queda. Cada neteja que esborra alguna cosa o que falla deixa un resum `cleanup.screenshots` a `audit_log`, excepte si és un `--dry-run`.

## Navegadors

Playwright i els navegadors van fixats a la mateixa versió. La imatge de desenvolupament (`docker/verifier/Dockerfile`) és `mcr.microsoft.com/playwright:v<versió>-noble`, que ja porta Chromium a `/ms-playwright`, i la versió de la imatge ha de coincidir amb la de `playwright` a `verifier/package-lock.json`. Els tests del verificador (`make verifier`) arrenquen Chromium de debò contra un servidor HTTP local dins del contenidor, sense sortir a la xarxa.

El build (`npm run build`, `tsconfig.build.json`) compila a `verifier/dist/` només el codi, sense els tests.

### Requisits per executar el verificador real

El `VerifierClient` llança el verificador al **mateix** host o contenidor que el worker de Laravel. Per tant, aquest entorn necessita:

- Node (`wpup.verifier.node_binary`, variable `WPUP_NODE_BINARY`) i `setsid` (util-linux).
- El `verifier/dist/` compilat (`npm ci && npm run build`).
- Els navegadors instal·lats amb la mateixa versió de Playwright (`npx playwright install --with-deps chromium`), amb `PLAYWRIGHT_BROWSERS_PATH` apuntant al directori.

**Estat actual:**

- **Desenvolupament:** el contenidor `app` (on corren l'orquestrador i Horizon) **no té Node**. El contenidor `verifier` té Node i Chromium, però no pot rebre crides de l'`app`. Per això els tests PHP fan servir un verificador fals (`tests/Support/fake-verifier.php`) i el verificador real es prova amb `make verifier` dins del seu contenidor. Si s'executa el `VerifyStep` a l'`app`, falla dient que no troba Node.
- **Producció:** el provisionament (`deploy/`) encara no instal·la Node, `dist/` ni els navegadors.

La integració real s'ha de fer a UZEROWPUP-72 (pipeline i entorn de desenvolupament) i el desplegament a UZEROWPUP-28.
