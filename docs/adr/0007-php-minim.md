# ADR-0007: PHP 7.4 com a versió mínima de la flota i de l'agent

**Estat:** Acceptat (2026-10-21)
**Data:** 2026-10-21
**Decideixen:** Arnau
**Tasca:** UZEROWPUP-7

## Context

L'especificació demanava que l'agent PHP temporal (llocs SFTP) fos compatible amb PHP 5.6 o anterior, provat contra totes les versions de la 5.6 a la 8.5. Deixava oberta la decisió fins a tenir l'inventari de la flota.

Escriure i provar un agent amb sintaxi PHP 5.6 és car: sense tipus escalars ni tipus de retorn, sense `??`, i amb una matriu de proves de 10 versions. A més, WordPress 7.0 ja exigeix PHP 7.4, de manera que un lloc amb PHP inferior no pot rebre actualitzacions de core.

Arnau ha decidit fixar **PHP 7.4 com a mínim**, sense esperar l'inventari.

## Decisió

### 1. Mínim de la flota

- L'eina **només gestiona llocs amb PHP 7.4 o superior**. Es tracta del PHP real que serveix el web (handler de Plesk i probe HTTP), no del CLI.
- Un lloc amb PHP inferior a 7.4 es detecta a l'inventari o a `site:test` i queda **bloquejat** amb el motiu «PHP < 7.4: no suportat». No s'hi executen actualitzacions, i surt destacat a l'inventari setmanal i al resum diari perquè l'operador en planifiqui el canvi de PHP (fora de l'abast de l'eina).
- El mínim és configurable (`config/wpup.php`: `min_site_php`), però no es pot baixar de 7.4 sense modificar l'agent i aquest ADR.

### 2. Agent PHP temporal

- **Sintaxi PHP 7.4.** Es poden fer servir tipus escalars, tipus de retorn, propietats tipades, `??`, `??=`, arrow functions i l'operador spread.
- **No es pot fer servir res de PHP 8.0 o posterior:** `match`, nullsafe (`?->`), arguments amb nom, tipus unió, `mixed`, promoció al constructor, enums, `readonly` i `never`.
- Continua sent **un sol fitxer, sense dependències**, i continua treballant per trossos i amb represa.
- **Lint:** PHPCompatibility amb `testVersion` `7.4-` a `make ci`.
- **Matriu de tests:** PHP **7.4, 8.0, 8.1, 8.2, 8.3, 8.4 i 8.5** en Docker local (`make agent-matrix`), en lloc de 5.6–8.5.

### 3. Altres peces que s'executen al lloc

- El **probe HTTP temporal** (UZEROWPUP-30) és l'única excepció a la sintaxi 7.4: ha de respondre fins i tot en llocs per sota del mínim, perquè l'eina els pugui detectar i bloquejar. Per això és un script mínim amb sintaxi compatible amb PHP 5.x (sense tipus, sense `??`, sense closures modernes), que només retorna `PHP_VERSION` i `get_loaded_extensions()` i comprova el token. El lint de `make ci` el valida amb `testVersion` `5.4-`.

## Opcions considerades

- **Mantenir PHP 5.6:** cobreix llocs molt antics, però encareix l'agent i la matriu. Aquests llocs, a més, no poden actualitzar el core a les versions actuals. Descartada.
- **Esperar l'inventari:** la decisió seria més informada, però endarrereix F6 sense gaire benefici. Si l'inventari troba llocs per sota de 7.4, es tracten com a excepcions (bloquejats amb avís).
- **PHP 8.1 com a mínim** (el de WooCommerce 11.6, al febrer de 2027): més net, però deixaria fora llocs vigents que encara poden actualitzar plugins i el core.

## Conseqüències

- **Més fàcil:** un agent amb tipus i sintaxi moderna, una matriu de 7 versions en lloc de 10, i menys casos límit.
- **Més difícil:** els llocs per sota de 7.4 queden fora de l'eina fins que se'ls canviï el PHP.
- **Cal vigilar:** quan WooCommerce 11.6 exigeixi PHP 8.1, els llocs amb 7.4–8.0 quedaran congelats en WooCommerce 11.5 (avís de l'entorn, UZEROWPUP-52). Quan pràcticament tota la flota estigui en 8.1, es pot plantejar pujar el mínim.

## Accions

1. [ ] UZEROWPUP-86 Esquelet de l'agent amb sintaxi PHP 7.4
2. [ ] UZEROWPUP-87 Matriu de tests de l'agent: 7.4–8.5
3. [ ] UZEROWPUP-30 Probe HTTP compatible amb qualsevol versió de PHP
4. [ ] UZEROWPUP-34 Bloqueig dels llocs amb PHP < 7.4
