# Importació de llocs (`site:import`)

Només `admin`. Dona d'alta o actualitza molts llocs d'un sol cop des d'un fitxer CSV o YAML:

```bash
sudo -u wpup wpup site:import llocs.yaml --dry-run
sudo -u wpup wpup site:import llocs.csv
sudo -u wpup wpup site:import llocs.txt --format=csv --json
```

El format surt de l'extensió (`.csv`, `.yaml`, `.yml`) o de `--format=csv|yaml`. Exemples sense dades reals: [llocs.yaml](examples/llocs.yaml) i [llocs.csv](examples/llocs.csv) (descriuen els mateixos tres llocs).

## Garanties

- **Tot o res.** Primer es llegeix i es valida tot el fitxer, amb les mateixes regles que `site:add` i `site:edit`, contra les dades existents i contra la resta de files (noms repetits, servidors i comptes definits de forma contradictòria). Es mostren **tots** els errors, cadascun amb el número i el nom del lloc. Només si no n'hi ha cap s'escriu, tot en una sola transacció.
- **Idempotent.** Un nom que ja existeix s'**actualitza**; un de nou es **crea**. Tornar a importar el mateix fitxer no duplica res ni escriu res: la sortida diu «sense canvis» i no queda cap entrada a l'auditoria.
- **`--dry-run`** valida i mostra una taula amb Lloc, Acció (`crear`, `actualitzar`, `sense canvis`) i Canvis, sense escriure ni auditar.
- **Auditoria.** Cada lloc deixa les entrades habituals (`site.add`, `site.edit`) i, a més, una entrada `site.import` amb el nom del fitxer i els recomptes.
- **Mai credencials.** El fitxer no pot contenir contrasenyes, claus ni tokens. Qualsevol clau que ho sembli (`password`, `pass`, `key`, `secret`, `token`, `private`, `credential`, `auth`…) és un error, i també qualsevol clau desconeguda. Les credencials es gestionen a part (`ssh-key:*`, `secrets:*`) i la host key es fixa amb `site:test`. La importació no els toca mai (`pinned_host_key`, `credential_ref`).

## Camps

Els camps són els de `site:add` (vegeu [cli.md](cli.md)). `name` és obligatori. Un lloc **nou** necessita també `url`, `wp_path`, `server` i `account`, i `hostname` si el servidor és nou.

| Camp | Valor |
| --- | --- |
| `name` | Nom del lloc (clau de la importació) |
| `url`, `wp_path`, `php_binary` | URL http/https, camí absolut de WordPress i del PHP |
| `tags` | Llista d'etiquetes |
| `strategy` | `in_place` o `staging_first` |
| `window` | `days` (`1-5,7`, 1 = dilluns), `start`, `end` (HH:MM) |
| `auto_update`, `include_uploads` | `true`/`false` (també `yes`/`no`, `sí`/`no`, `1`/`0`) |
| `verify_urls`, `mask`, `diff_threshold` | URLs a verificar, selectors a emmascarar, llindar 0–100 |
| `checkout_product`, `freeze`, `exclude` | Producte de prova, components congelats i exclosos |
| `notify` | `email`, `telegram`, `slack` |
| `external_backup` | `plesk`, `dropbox`, `plugin`, `other`, `none` |
| `server` | `name`, `hostname`, `ssh_port`, `panel` (`plesk` o `other`) |
| `account` | `system_user`, `driver` (`ssh` o `agent`) |

Un servidor o compte que ja existeix es reutilitza. Si el fitxer en repeteix el host, el port o el tauler, han de coincidir amb els desats (aquí no es poden canviar). Diversos llocs poden compartir un servidor nou: n'hi ha prou amb definir-lo en un. Un lloc existent no es pot moure a un altre servidor ni compte.

## YAML

Una llista de llocs (o una clau `sites:` amb la llista). `window`, `server` i `account` són objectes; les llistes, llistes YAML.

```yaml
- name: botiga-exemple
  url: https://botiga-exemple.example.test
  wp_path: /var/www/vhosts/botiga-exemple/httpdocs
  tags: [woo, nit]
  window: { days: "1-5", start: "01:00", end: "05:00" }
  server: { name: plesk-exemple, hostname: plesk-exemple.example.test }
  account: { system_user: botiga_exemple, driver: ssh }
```

## CSV

Una fila per lloc, amb capçalera. Les columnes són planes i el nom és el del camp; els camps imbricats del YAML s'hi aplanen:

`name, url, wp_path, php_binary, tags, strategy, window_days, window_start, window_end, auto_update, verify_urls, mask, diff_threshold, checkout_product, freeze, exclude, notify, include_uploads, external_backup, server, hostname, ssh_port, panel, account, driver`

- **Separador de columnes:** coma; si la capçalera fa servir `;` (Excel en català) es detecta sola. UTF-8, amb o sense BOM.
- **Separador de llistes dins d'una cel·la:** la barra vertical `|` (p. ex. `/|/cart|/shop`), perquè una URL o un selector CSS pot portar comes i punts i coma.
- Les columnes que no calen es poden ometre senceres.

## Camps absents i camps buits

| Al fitxer | Efecte en un lloc existent |
| --- | --- |
| El camp **no hi és** (clau absent o columna omesa) | No es toca |
| El camp hi és i té **valor** | Substitueix el valor (les llistes, sencers) |
| Camp que es pot esborrar, **buit** (`tags: []`, `~`, cel·la buida) | S'esborra |
| Qualsevol altre camp, buit | S'ignora |

Es poden esborrar: `tags`, `php_binary`, `window`, `verify_urls`, `mask`, `checkout_product`, `freeze`, `exclude`, `notify` i `external_backup`. La finestra s'esborra quan hi són `window_days`, `window_start` i `window_end` (o `window:` al YAML) i tots són buits.

**Compte amb el CSV:** una columna present amb la cel·la buida **esborra** el camp dels llocs existents. Si no vols tocar una columna, treu-la del fitxer. En un lloc nou, els buits no fan res.
