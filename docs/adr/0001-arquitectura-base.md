# ADR-0001: Arquitectura base de wpup

**Estat:** Proposat
**Data:** 2026-10-20
**Decideixen:** Arnau
**Tasca:** UZEROWPUP-5

## Context

Hem d'actualitzar uns 50 llocs WordPress/WooCommerce en una finestra nocturna, de forma segura i auditable, amb diverses persones operant l'eina. Els llocs viuen majoritàriament en servidors Plesk amb PHP diferents per domini. Alguns només són accessibles per SFTP, en hostings compartits amb límits de temps i memòria. Cal fer còpies verificades, comprovar la compatibilitat amb el PHP real, verificar visualment i funcionalment el lloc després d'actualitzar, i revertir si cal. Les botigues no poden perdre comandes.

Restriccions:

- Equip amb experiència en PHP/Laravel i WordPress.
- Sense CI de GitHub: la qualitat es garanteix amb una CI local (`make ci`).
- Operació per CLI. No hi ha cap requisit d'interfície web, excepte un visor d'informes (pendent de UZEROWPUP-8).

## Decisió

1. **Orquestrador en Laravel 13 (PHP 8.3+)**, operat amb comandes Artisan darrere d'un binari `wpup`, amb Laravel Queues + Redis (Horizon) per a les execucions en paral·lel i un lock atòmic per lloc.
2. **Verificador separat en Node + TypeScript + Playwright + pixelmatch**, invocat per l'orquestrador amb un contracte JSON versionat. No té credencials dels llocs.
3. **Accés als llocs a través de la interfície `SiteDriver`**, amb dues implementacions:
   - `SshDriver`: WP-CLI amb el binari de PHP del domini, `mysqldump`/`tar` i rsync.
   - `AgentDriver`: un agent PHP temporal d'un sol fitxer (sintaxi PHP 5.6+), pujat per SFTP, amb HMAC, TTL i esborrament garantit.
4. **Còpies a través de la interfície `BackupTarget`** (local al lloc, SFTP/SCP, S3, i més endavant Dropbox i rclone), amb SHA-256 verificat a cada destinació.
5. **Staging opcional en Docker** al servidor de l'eina, amb el PHP i el MySQL/MariaDB del lloc, neutralitzat abans d'arrencar. La producció sempre torna a fer còpia i verificació.
6. **Artefactes per execució:** els paquets s'analitzen (PHPCompatibility) i s'instal·len des del servidor de l'eina amb el seu checksum, de manera que staging i producció reben els mateixos bytes.
7. **Monorepo:** `app/` (orquestrador), `verifier/`, `agent/`, `docker/` i `docs/`.

## Opcions considerades

### Opció A: Laravel + worker Node (triada)

| Dimensió | Valoració |
| --- | --- |
| Complexitat | Mitjana (dos runtimes) |
| Cost | Un servidor propi |
| Escalabilitat | Suficient per a desenes o centenars de llocs; concurrència configurable |
| Familiaritat de l'equip | Alta en Laravel; Playwright és l'estàndard per a captures |

**A favor:** ecosistema de cues, xifratge, notificacions i comandes ja resolt; Playwright és l'eina més fiable per a captures i fluxos de navegador; l'agent i l'orquestrador comparteixen llenguatge.
**En contra:** dos runtimes a mantenir; cal definir bé el contracte entre l'orquestrador i el verificador.

### Opció B: tot en Node/TypeScript

| Dimensió | Valoració |
| --- | --- |
| Complexitat | Mitjana |
| Cost | Igual |
| Escalabilitat | Similar |
| Familiaritat de l'equip | Menor |

**A favor:** un sol runtime.
**En contra:** caldria reconstruir cues, xifratge, auditoria i CLI; s'allunya del coneixement de l'equip i de l'ecosistema PHP (PHPCompatibility, WP-CLI).

### Opció C: scripts sobre eines existents (WP-CLI + shell + un servei SaaS de regressió visual)

| Dimensió | Valoració |
| --- | --- |
| Complexitat | Baixa al principi, alta al final |
| Cost | Subscripció SaaS |
| Escalabilitat | Feble (sense locks, represa ni auditoria) |
| Familiaritat de l'equip | Alta |

**A favor:** arrencada ràpida.
**En contra:** no cobreix la idempotència, la represa, el multiusuari ni la protecció de comandes; les captures de botigues surten cap a tercers.

## Anàlisi de compromisos

La complexitat del problema és en l'orquestració: passos idempotents, represa, locks, auditoria, xifratge i notificacions. Laravel ja ho resol. El navegador headless és l'únic àmbit on PHP no és competitiu, i aïllar-lo en un worker amb un contracte JSON limita el cost dels dos runtimes. Les interfícies `SiteDriver` i `BackupTarget` concentren la variabilitat (SSH o SFTP, destinacions diverses) i permeten provar-ho tot amb una mateixa suite de contracte.

## Conseqüències

- **Més fàcil:** afegir destinacions de còpia o tipus de connexió nous; provar la lògica de domini amb `FakeDriver`; operar en multiusuari amb auditoria.
- **Més difícil:** mantenir l'agent compatible amb PHP 5.6–8.5 (cal una matriu local en Docker); sincronitzar les versions del contracte del verificador.
- **Caldrà revisar:** el PHP mínim de l'agent (UZEROWPUP-7), la BD de l'eina (UZEROWPUP-6) i el visor d'informes (UZEROWPUP-8).

## Accions

1. [ ] UZEROWPUP-1 Inicialitzar el projecte Laravel 13 i l'estructura del monorepo
2. [ ] UZEROWPUP-2 CI local amb `make ci` i el hook pre-push
3. [ ] UZEROWPUP-22 Interfície `SiteDriver` i suite de contracte
4. [ ] UZEROWPUP-37 Interfície `BackupTarget` i registre de drivers
5. [ ] UZEROWPUP-64 Esquelet del verificador i protocol amb l'orquestrador
