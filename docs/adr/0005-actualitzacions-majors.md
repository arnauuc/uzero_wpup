# ADR-0005: Política d'actualitzacions majors i quarantena

**Estat:** Acceptat (2026-10-21)
**Data:** 2026-10-21
**Decideixen:** Arnau
**Tasca:** UZEROWPUP-10

## Context

Les actualitzacions majors són les que més probablement trenquen un lloc: canvis d'API, plantilles de WooCommerce, requisits de PHP. A més, les versions noves solen tenir una `.1` correctiva als pocs dies. Cal decidir quan i com s'apliquen, sense frenar els pegats de seguretat.

Cada tipus de component numera les versions a la seva manera:

| Component | Esquema de versions | Què és «major» en aquest ADR |
| --- | --- | --- |
| WordPress core | Cada `x.y` és una versió major; `x.y.z` són manteniment i seguretat | Canvi a `x.y` (6.9 → 7.0, 7.0 → 7.1) |
| WooCommerce | Una versió `x.y` cada mes; `X.0` és la que ve després de `X-1.9` | Canvi a `X` (10.x → 11.0). Les `x.y` mensuals **no** són majors |
| Plugins i temes | Semver, si fa no fa | Canvi al primer número (2.x → 3.0) |

## Decisió

### 1. Política per tipus

| Canvi | Política |
| --- | --- |
| Core `x.y.z` (manteniment i seguretat) | Automàtic, amb l'estratègia del lloc |
| Core `x.y` (major) | Automàtic, però **amb staging primer obligatori** |
| WooCommerce `x.y` mensual | Automàtic, amb l'estratègia del lloc (després de la quarantena) |
| WooCommerce `X.0` | Automàtic, però **amb staging primer obligatori** |
| Plugins i temes amb canvi al primer número | Automàtic, però **amb staging primer obligatori**, i **avís a l'operador** perquè revisi el changelog i el lloc |
| Plugins i temes menors i pegats | Automàtic, amb l'estratègia del lloc |

- **Staging primer obligatori:** si el pla d'un lloc conté almenys un canvi que l'exigeix, *tota* l'execució d'aquell lloc fa servir l'estratègia de staging primer. Així es proven tots els components junts, amb els mateixos artefactes. Un lloc configurat com a in-place no ho evita.
- **Avís per als majors de plugins i temes:** a l'informe hi ha la secció «Majors aplicades: cal revisar», amb l'enllaç al changelog. També s'envia una notificació amb severitat d'avís, encara que la verificació hagi passat.
- **Les altres capes no canvien:** compatibilitat, verificació i rollback s'apliquen igual a totes les actualitzacions.

### 2. Mentre no existeixi el staging (abans de F7)

- Els canvis que exigeixen staging primer **s'ometen** amb el motiu «major pendent: cal staging (F7)» i s'avisa l'operador.
- Un `admin` pot forçar-los in-place en una execució concreta amb `--allow-major`. Això queda a `audit_log` i a l'informe.
- Quan F7 estigui disponible, aquest comportament es retira i s'aplica la política del punt 1.

### 3. Quarantena de 7 dies

- Una versió nova només es considera candidata quan fa **7 dies** que existeix (`config/wpup.php`: `quarantine_days`).
- **Data de referència:** la data de publicació, si l'API de WordPress.org la dona, i si no (plugins premium), la data en què l'eina va veure aquella versió per primer cop. Per això l'inventari desa la primera aparició de cada versió de cada component (`package_versions.first_seen_at`).
- **Si dins de la quarantena surt una versió correctiva** (p. ex. una 3.0.1 al cap de 2 dies de la 3.0.0), el comptador de quarantena el marca la versió nova.
- **Excepcions, que s'apliquen sense esperar:**
  - Core `x.y.z` dins de la mateixa branca `x.y` (manteniment i seguretat).
  - Qualsevol actualització d'un component la **versió instal·lada del qual té una vulnerabilitat coneguda**, segons una font de vulnerabilitats de WordPress (Wordfence Intelligence, WPScan o Patchstack; es tria a UZEROWPUP-128). S'aplica la primera versió que la corregeix, amb les mateixes regles de staging si és major.
- `wpup plan` mostra les versions en quarantena amb els dies que falten, i quines s'apliquen per l'excepció de seguretat.

### 4. Sobreescriptures

- **Per lloc** (perfil): els components congelats i exclosos continuen tenint prioritat sobre aquesta política.
- **Per execució:** `--skip-quarantine` (només `admin`, auditat), per a casos urgents no detectats per la font de vulnerabilitats.

## Opcions considerades

- **Majors automàtiques sense tractament especial:** depenen només de la verificació i del rollback. Descartada: a les botigues, un rollback de BD pot no ser possible si hi ha comandes noves (vegeu l'especificació), i val més detectar el problema al staging.
- **Majors només amb aprovació explícita:** més control, però acumula feina manual i deixa llocs enrere. Descartada per a core i WooCommerce; per als plugins es va triar «staging i avís» en lloc d'aprovació.
- **Quarantena de 3 dies o sense quarantena:** més ràpid, però perd el marge en què solen sortir les `.1` correctives.

## Conseqüències

- **Més fàcil:** els pegats de seguretat no esperen; les majors arriben a producció provades.
- **Més difícil:** fins a F7 les majors s'acumulen omeses o requereixen `--allow-major`. Per això cal la font de vulnerabilitats i el registre de primera aparició de cada versió.
- **Cal vigilar:** els plugins que no respecten semver (majors encobertes). La verificació i el rollback continuen sent la xarxa de seguretat.

## Accions

1. [ ] UZEROWPUP-127 Política de versions: classificació major/menor, quarantena, staging obligatori i `--allow-major`
2. [ ] UZEROWPUP-128 Font de vulnerabilitats per a l'excepció de seguretat
3. [ ] UZEROWPUP-48 Capa 1 de compatibilitat (aplica la política dins del pla)
4. [ ] UZEROWPUP-105 Estratègia de staging primer (aplica el staging obligatori)
