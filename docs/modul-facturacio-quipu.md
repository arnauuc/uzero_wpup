# Mòdul F9: clients i facturació (Quipu)

Estat: planificat; s'implementa després de F1–F8. Tasques a Plane: UZEROWPUP-110 a UZEROWPUP-121 (mòdul «F9 · Clients i facturació (Quipu)»).

## Objectiu

Vincular cada web amb un client de Quipu, el programa de facturació, i veure a la fitxa de la web si el manteniment està cobrat.

**Fa:**

- Desa la relació client ↔ webs (un client pot tenir diverses webs; la factura de manteniment és per client).
- Llegeix de Quipu les factures de manteniment de cada client i en calcula l'estat de cobrament.
- Mostra l'estat a `wpup site:show`, a `plan`, a `runs`, als informes i al visor.

**No fa:**

- No escriu mai a Quipu: no crea ni modifica factures, contactes ni etiquetes.
- No bloqueja ni omet actualitzacions per motius de cobrament. Només informa i avisa.

## Integració amb l'API de Quipu

Font: [especificació OpenAPI de l'API v1 de Quipu](https://github.com/quipuapp/api-v1-docs).

| Aspecte | Detall |
| --- | --- |
| Base | `https://getquipu.com/{owner_slug}/…`, format JSON:API |
| Autenticació | OAuth2 *client credentials* (`POST /oauth/token`, scope obligatori); token vàlid 2 h |
| Límit | 5 peticions cada 5 segons (si se supera, `429`) |
| Paginació | `meta.pagination_info` (`total_pages`, `current_page`, `total_results`) |
| Contactes | `GET /{owner_slug}/contacts`, filtre `filter[tax_id]` |
| Factures | `GET /{owner_slug}/invoices`, filtres `filter[contact_id]`, `filter[kind]`, `filter[period]` i `filter[payment_status]` (`paid`, `unpaid`, `due`, `pending`) |
| Camps útils | `number`, `issue_date`, `due_dates`, `paid_at`, `payment_status`, `total_amount`, `tags` i les línies (`include=`) |
| Webhooks | No n'hi ha; per això se sincronitza periòdicament |

## Identificació de les factures de manteniment

Encara està pendent de decidir (UZEROWPUP-110). Les opcions són:

1. **Per etiqueta.** Les factures de manteniment porten una etiqueta (p. ex. `manteniment`) al camp `tags` de Quipu, que es pot posar fins i tot en factures finalitzades. És el criteri més fiable. L'etiquetatge es fa a Quipu, mai des de l'eina.
2. **Per patró al concepte** de les línies (p. ex. «Manteniment web»). És més fràgil si el text varia.

El criteri és configurable a nivell global i es pot sobreescriure per client.

## Estat de cobrament

Un client està **al corrent** si no té cap factura de manteniment vençuda pendent de pagament i si existeix la factura del període actual segons la seva periodicitat (mensual, trimestral, semestral o anual).

| Estat | Significat |
| --- | --- |
| `al_corrent` | Sense factures vençudes i amb la factura del període emesa |
| `pendent` | Hi ha alguna factura no pagada que encara no ha vençut |
| `vençut` | Alguna factura ha passat el venciment sense pagar-se (amb els dies de retard i l'import) |
| `sense_factura_periode` | Falta la factura del període actual (passats els dies de gràcia) |
| `desconegut` | Sense vincle, sense sincronització recent (més de 48 h) o error de l'API |

## Sincronització

- Job diari i `wpup billing:sync [client|--all]` sota demanda.
- Les dades es desen a la BD de l'eina (`billing_invoices`, `client_billing_status`); la fitxa no consulta Quipu en temps real.
- Els canvis a `vençut` es notifiquen pels canals de F5 amb severitat d'avís.

## Permisos

- **Operadors:** només veuen l'estat.
- **Admin:** veu també els imports, els números de factura, les dates i les dades fiscals.
- Les credencials de Quipu (`app_id`, `secret`) es guarden xifrades i només les gestionen els `admin`.

## Model de dades

| Entitat | Camps principals |
| --- | --- |
| `clients` | nom, NIF, `quipu_contact_id`, periodicitat, criteri d'identificació propi, notes |
| `sites` | + `client_id` (nullable) |
| `billing_invoices` | id de Quipu, client, número, data d'emissió, venciments, import, `payment_status`, `paid_at`, `synced_at` |
| `client_billing_status` | client, estat, motiu, import pendent, dies de retard, `synced_at` |

## Comandes noves

| Comanda | Funció |
| --- | --- |
| `wpup client:link <lloc> --nif=…/--search=…` | Busca el contacte a Quipu i vincula la web al client |
| `wpup client:unlink <lloc>` | Desfà el vincle |
| `wpup client:list` / `client:edit` | Llistat amb les webs i l'estat; edició de la periodicitat i del criteri |
| `wpup billing:sync` | Sincronització sota demanda |
| `wpup site:show <lloc>` | Fitxa de la web, incloent-hi el client i l'estat de cobrament |
