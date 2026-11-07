# Verificador: protocol amb l'orquestrador

El verificador (`verifier/`) és un worker Node + TypeScript + Playwright que visita les pàgines públiques d'un lloc i en desa les captures i els errors. Aquest document en fixa el contracte amb l'orquestrador (UZEROWPUP-64). L'estabilitat de les captures (UZEROWPUP-65), el diff (UZEROWPUP-68), el flux de compra (UZEROWPUP-69) i on entra al pipeline d'actualització (UZEROWPUP-72) es fan més endavant.

## Invocació: procés, no cua

L'orquestrador l'executa com a **procés fill** des del pas de l'execució (`VerifyStep` → `VerifierClient`), al mateix worker de Laravel:

```
node verifier/dist/bin.js < entrada.json
```

- La comanda és un array d'arguments (`wpup.verifier.command`), mai una cadena de shell.
- L'entrada va per **stdin**. La sortida es desa a `<output_dir>/result.json` (0600, escrit en un fitxer temporal i reanomenat). El directori de sortida és privat (0700) i n'hi ha un per execució i per pas: `wpup.verifier.work_dir/run-<id>/<pas>/`.
- Hi ha dos límits de temps. El verificador deixa de visitar pàgines quan arriba a `timeouts.total_ms` (i ho anota a `verifier_errors`), i el `VerifierClient` mata el procés a `wpup.verifier.timeout_seconds`. Tots dos queden dins del timeout del pas (`step_timeout_seconds`).
- El `VerifierClient` no llegeix mai stderr (hi pot haver traces o contingut de pàgines) i, de stdout, només en llegeix com a màxim 64 KiB.

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
  "timeouts": { "navigation_ms": 60000, "total_ms": 1080000 }
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
    "timings": { "navigation_ms": 830 },
    "error": null
  }],
  "verifier_errors": []
}
```

- Un resultat per URL i viewport. `screenshot` és relatiu a `output_dir` (`<viewport>/<n>.png`); l'orquestrador rebutja qualsevol altre camí. `error` diu per què una pàgina no s'ha pogut carregar o capturar.
- Límits per pàgina: 50 errors de consola, 100 errors de recursos i 500 caràcters per missatge o URL. L'orquestrador rebutja un `result.json` més gran que `wpup.verifier.max_result_bytes`.
- `verifier_errors` recull els problemes del verificador i no del lloc (p. ex. pàgines que no s'han visitat per manca de temps).
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

Els `issues` diuen quin camp i quina regla han fallat, però mai el valor rebut. Els missatges no porten traces. El `VerifierClient` passa el missatge pel redactor de secrets i el converteix en una `VerifierFailed`. El pas falla, però el verificador no jutja el lloc: fins a UZEROWPUP-72, el `VerifyStep` només registra els errors HTTP i de consola.

## Navegadors

Playwright i els navegadors van fixats a la mateixa versió. La imatge de desenvolupament (`docker/verifier/Dockerfile`) és `mcr.microsoft.com/playwright:v<versió>-noble`, que ja porta Chromium a `/ms-playwright`, i la versió de la imatge ha de coincidir amb la de `playwright` a `verifier/package-lock.json`. Els tests del verificador (`make verifier`) arrenquen Chromium de debò contra un servidor HTTP local dins del contenidor, sense sortir a la xarxa.

En producció, el worker necessita Node (`wpup.verifier.command`, variable `WPUP_NODE_BINARY`), el `verifier/dist/` compilat (`npm ci && npm run build`) i els navegadors instal·lats amb la mateixa versió de Playwright (`npx playwright install --with-deps chromium`, amb `PLAYWRIGHT_BROWSERS_PATH` apuntant al directori). El provisionament (`deploy/`) encara no ho fa.
