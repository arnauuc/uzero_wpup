# ADR-0008: Panell web (Laravel, Blade + Tailwind, login propi amb 2FA)

**Estat:** Acceptat (2026-10-21)
**Data:** 2026-10-21
**Decideixen:** Arnau
**Tasca:** UZEROWPUP-8

## Context

L'especificació preveia un «visor de només lectura» per als informes i les captures, i deixava oberta la tria entre una web dins de Laravel i fitxers HTML estàtics. Després hi han aparegut més necessitats:

- **Fitxa de cada lloc**, amb l'estat de cobrament de Quipu filtrat per rol (F9).
- **Accions des del web.** Arnau vol poder activar coses des del navegador, com ara forçar una actualització.
- **Accés només per la tailnet**, amb Tailscale Serve en HTTPS (ADR-0003).

## Decisió

### 1. Panell web dins de l'aplicació Laravel

- El panell forma part de la mateixa aplicació Laravel. El serveix PHP-FPM amb l'usuari `wpup` (ADR-0002), escoltant només a `127.0.0.1`, i Tailscale Serve el publica a la tailnet (ADR-0003).
- **Pàgines:** tauler de la flota (estat, pendents, bloquejats, cua d'execució actual), llistat d'execucions amb filtres, informe d'execució (canvis, còpies, verificació amb el comparador de captures d'abans, de després i del diff), fitxa del lloc (perfil, inventari, historial, client i cobrament de F9), cua i finestres, i auditoria (només `admin`).
- **Frontend:** Blade, Tailwind i Alpine.js (filtres, comparador de captures, consulta periòdica del progrés d'una execució). Sense SPA ni pila de JS pròpia. Els recursos es compilen amb Vite i se serveixen des de l'aplicació, sense CDN, perquè el panell no depengui de recursos externs.

### 2. Autenticació pròpia amb 2FA obligatori

- Els usuaris són els mateixos de la taula `users` que fa servir la CLI, amb els mateixos rols. Es dona d'alta l'accés web per CLI: `wpup user:web-enable <usuari>`, que genera una contrasenya inicial d'un sol ús.
- **Contrasenya** (hash Argon2id) i **TOTP obligatori** des del primer accés, amb codis de recuperació d'un sol ús. El secret TOTP es guarda al `SecretStore` (ADR-0002).
- **Sense recuperació per correu:** una contrasenya o un 2FA perduts els restableix un `admin` per CLI (`wpup user:web-reset`). Queda auditat.
- **Sessions:** cookies `Secure`, `HttpOnly` i `SameSite=Strict`, caducitat per inactivitat als 30 minuts i absoluta a les 12 hores, i tancament de les sessions d'un usuari quan se'n restableix l'accés.
- **Protecció del login:** límit d'intents per usuari i per IP, i bloqueig temporal. Tots els accessos, correctes i fallits, queden a l'auditoria.
- **Capçaleres:** CSP restrictiva (sense recursos externs), `X-Frame-Options: DENY` i HSTS. Les identitats de Tailscale **no** s'utilitzen per autenticar.

### 3. Accions des del web

- **Un sol camí de codi.** Cada acció del panell crida el mateix servei de domini que la comanda CLI equivalent, amb les mateixes policies per rol, les mateixes validacions, la mateixa cua d'execució i els mateixos límits de concurrència (ADR-0006). No hi ha lògica de negoci als controladors.
- **Accions disponibles** (segons el rol, igual que a la CLI): llançar `inventory`, `plan`, `verify`, `backup` o `update` d'un lloc o d'un tag (amb `--now` només per a admin); `rollback`; activar o desactivar `auto_update` i editar la finestra; desbloquejar un lloc bloquejat per fallades; `--allow-major` i `--skip-quarantine` (només admin); i `lock:release` (només admin).
- **Previsualització obligatòria:** les accions que fan canvis mostren primer el resultat del `--dry-run` (el pla) i demanen confirmació.
- **Reautenticació:** les accions destructives o sensibles (rollback, restauració, `--allow-major`, `--skip-quarantine`, `lock:release`) demanen tornar a introduir el codi TOTP.
- **Protecció CSRF** a tots els formularis; les accions no s'executen mai amb peticions GET.
- **Auditoria:** cada acció registra l'usuari, el canal (`web` o `cli`), l'IP de la tailnet i el resultat.
- **Mai pel web:** gestió de credencials i secrets (alta i edició de credencials de llocs, destinacions i Quipu), `key:generate`, `key:rotate`, `secrets:rekey`, gestió d'usuaris i rols, i qualsevol acció que mostri un secret. Això queda exclusivament per a la CLI d'un `admin`.

## Opcions considerades

- **HTML estàtic:** és simple, però no permet la fitxa amb dades vives, ni filtres per rol, ni accions. Descartada.
- **Identitat de Tailscale (capçaleres de Serve):** evita una contrasenya més, però fa dependre l'autenticació de la configuració de Serve i de la confiança en una capçalera. Arnau prefereix un login propi amb 2FA. Descartada.
- **Livewire o Inertia:** més interactivitat, però més complexitat. Amb Blade i Alpine n'hi ha prou per a pàgines de consulta i formularis d'acció. Es pot reconsiderar si el panell creix.

## Conseqüències

- **Més fàcil:** fer servir l'eina sense terminal per a les tasques habituals, i mostrar els informes amb les captures.
- **Més difícil:** el panell és una superfície d'atac nova. Per això les defenses s'acumulen: només tailnet, login amb 2FA, reautenticació per a les accions sensibles, cap secret al web i un sol camí de codi amb la CLI.
- **Cal vigilar:** que cap acció nova s'afegeixi al web sense passar per la mateixa policy i el mateix servei que la CLI. Els tests de policy han de cobrir els dos canals.

## Accions

1. [ ] UZEROWPUP-84 Panell web: estructura, layout i pàgines de consulta
2. [ ] UZEROWPUP-131 Autenticació web: login, TOTP, sessions i reautenticació
3. [ ] UZEROWPUP-132 Accions des del panell web
4. [ ] UZEROWPUP-120 Fitxa del lloc amb l'estat de cobrament (F9)
