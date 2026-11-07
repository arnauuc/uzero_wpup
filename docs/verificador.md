# Verificador: protocol amb l'orquestrador

El verificador (`verifier/`) és un worker Node + TypeScript + Playwright que visita les pàgines públiques d'un lloc i en desa les captures i els errors. Aquest document en fixa el contracte amb l'orquestrador (UZEROWPUP-64). L'estabilitat de les captures (UZEROWPUP-65), el diff (UZEROWPUP-68), el flux de compra (UZEROWPUP-69) i on entra al pipeline d'actualització (UZEROWPUP-72) es fan més endavant.

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
  "thresholds": { "max_diff_ratio": 0.01 },
  "output_dir": "/var/lib/wpup/verifier/run-42/verify-baseline",
  "timeouts": { "navigation_ms": 60000, "screenshot_ms": 30000, "total_ms": 1080000 }
}
```

L'esquema és a `verifier/src/contract.ts` (zod, objectes estrictes: cap camp desconegut). Límits: com a màxim 50 URLs, 4 viewports (de 320 a 3840 px d'amplada) i 50 selectors de màscara, i 1 MiB d'entrada. `thresholds` es fa servir a partir del diff (UZEROWPUP-68). Els valors per defecte són a `config/wpup.php` (`verifier`).

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
