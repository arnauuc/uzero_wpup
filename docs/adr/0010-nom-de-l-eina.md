# ADR-0010: Nom de l'eina: Uzero wpup

**Estat:** Acceptat (2026-10-21)
**Data:** 2026-10-21
**Decideixen:** Arnau
**Tasca:** UZEROWPUP-12

## Context

L'especificació feia servir `wpup` com a nom provisional i deixava oberta la decisió del nom definitiu. El nom afecta la documentació, els informes, el panell web, les notificacions i el binari de la CLI.

## Decisió

- **Nom de l'eina:** **Uzero wpup**. És el que surt a la documentació, al títol del panell web, als informes i a les notificacions.
- **Binari de la CLI:** `wpup`, sense canvis (`wpup update`, `wpup backup:restore`…). Un nom amb espai no pot ser una comanda, i `wpup` és curt i ja és el que fan servir tots els documents i les tasques.
- **Identificadors tècnics:** el repositori és `uzero_wpup`; el directori d'instal·lació, `/opt/uzero-wpup`; l'usuari de sistema, `wpup`; el projecte de Plane, `UZEROWPUP`.

## Conseqüències

- No cal canviar res del codi ni de les tasques ja planificades.
- A les cadenes per a l'operador (informes, panell, notificacions), el nom s'escriu «Uzero wpup» i sortirà d'un sol lloc de configuració (`config/app.php`).
