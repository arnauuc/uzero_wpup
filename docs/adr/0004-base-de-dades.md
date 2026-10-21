# ADR-0004: Base de dades de l'eina: PostgreSQL

**Estat:** Acceptat (2026-10-21)
**Data:** 2026-10-21
**Decideixen:** Arnau
**Tasca:** UZEROWPUP-6

## Context

L'eina necessita una base de dades pròpia per a llocs, comptes d'hosting, execucions, passos, canvis, còpies, verificacions, informes, usuaris, auditoria i secrets xifrats (ADR-0002). L'especificació deixava oberta la tria entre PostgreSQL i MariaDB.

Requisits que pesen en la decisió:

- **Auditoria només d'inserció**, garantida per la mateixa base de dades i no només per l'aplicació.
- **Dades semiestructurades**: sortida dels passos, resultats de verificació, snapshots d'inventari.
- **Migracions segures**: una migració que falla no pot deixar l'esquema a mitges.
- **Volum petit** (~50 llocs, unes quantes execucions per lloc i setmana), una sola instància a la mateixa VM. Ningú més que l'eina hi accedeix.

## Decisió

**PostgreSQL**, amb la versió estable més recent dels paquets oficials de PostgreSQL (PGDG) per a la distribució de la VM (com a mínim la 17). S'instal·la a la mateixa VM, escoltant només per socket Unix.

### Rols i accés

- No hi ha contrasenya de BD al `.env`. L'eina s'hi connecta per socket Unix amb autenticació `peer`: l'usuari de sistema `wpup` es mapa (`pg_ident.conf`) als rols de base de dades.
- **`wpup_owner`**: propietari de l'esquema; només s'utilitza per executar les migracions.
- **`wpup_app`**: el rol amb què corre l'aplicació. Té `SELECT`, `INSERT`, `UPDATE` i `DELETE` a les taules de negoci, però a `audit_log` **només `SELECT` i `INSERT`**.
- Cap operador no té accés a la BD (ADR-0002). Per consultar-la, cal passar per les comandes `wpup`.

### Auditoria blindada

- `REVOKE UPDATE, DELETE, TRUNCATE ON audit_log FROM wpup_app`.
- Un trigger `BEFORE UPDATE OR DELETE OR TRUNCATE` a `audit_log` que llança un error per a qualsevol rol, fins i tot `wpup_owner`. Només un superusuari pot desactivar-lo, i aquesta acció queda registrada als logs de PostgreSQL.
- Els tests d'integració comproven les dues proteccions.

### Ús de les característiques de PostgreSQL

- `jsonb` per a la sortida dels passos, els resultats de verificació i els snapshots, amb índexs GIN només quan una consulta real ho justifiqui.
- Migracions transaccionals; cada migració de Laravel s'executa dins d'una transacció.
- Restriccions a la BD (`CHECK`, claus foranes i índexs únics) com a segona línia de defensa, a més de la validació de l'aplicació.
- Enums de PHP desats com a `text` amb `CHECK`, no com a tipus `ENUM` de PostgreSQL, perquè siguin fàcils de migrar.

### Desenvolupament i tests

- L'entorn Docker de desenvolupament (UZEROWPUP-3) porta PostgreSQL amb la mateixa versió major que la de producció.
- **Els tests corren contra PostgreSQL, mai contra SQLite**: els triggers, els permisos i el `jsonb` s'han de provar de debò.

### Còpies

- `pg_dump` en format personalitzat dins de la còpia diària de l'eina (UZEROWPUP-47). Les credencials ja van xifrades amb la KEK (ADR-0002).
- La restauració es prova dins del procediment de recuperació anual (UZEROWPUP-28).

## Opcions considerades

### Opció A: PostgreSQL (triada)

| Dimensió | Valoració |
| --- | --- |
| Complexitat | Baixa (una instància local) |
| Seguretat i integritat | Alta: DDL transaccional, `REVOKE`, triggers |
| Dades semiestructurades | `jsonb` indexable |
| Familiaritat de l'equip | Menor que MariaDB |

### Opció B: MariaDB

| Dimensió | Valoració |
| --- | --- |
| Complexitat | Baixa |
| Seguretat i integritat | Bona, però sense DDL transaccional |
| Dades semiestructurades | `JSON` sobre text, consultes més limitades |
| Familiaritat de l'equip | Alta (és el motor dels llocs WordPress) |

## Anàlisi de compromisos

La familiaritat amb MariaDB és real, però la BD de l'eina és petita i només la toca l'eina mateixa. En canvi, el DDL transaccional i les garanties de l'auditoria afecten directament dues coses que l'eina no pot permetre's perdre: un esquema coherent i un registre fiable de qui ha fet què.

## Conseqüències

- **Més fàcil:** migracions sense estats intermedis, auditoria impossible de modificar des de l'aplicació, consultes sobre els resultats de verificació.
- **Més difícil:** l'equip ha d'aprendre a operar PostgreSQL (còpies, actualitzacions majors amb `pg_upgrade`). Cal un runbook.
- **Cal vigilar:** les actualitzacions majors de PostgreSQL. Se'n fa una com a molt un cop l'any, sempre després de provar la restauració.

## Accions

1. [ ] UZEROWPUP-3 Entorn Docker amb PostgreSQL
2. [ ] UZEROWPUP-13 i UZEROWPUP-14 Migracions i models (amb `jsonb` i `CHECK`)
3. [ ] UZEROWPUP-17 Auditoria: `REVOKE` i trigger, amb tests
4. [ ] UZEROWPUP-28 Instal·lació a la VM: rols, `peer` i `pg_ident`, socket Unix
5. [ ] UZEROWPUP-47 Còpia diària amb `pg_dump`
