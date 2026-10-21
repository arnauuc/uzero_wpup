# ADR-0009: Estratègia de còpies i retenció de dades

**Estat:** Acceptat (2026-10-21)
**Data:** 2026-10-21
**Decideixen:** Arnau
**Tasca:** UZEROWPUP-11

## Context

L'especificació plantejava l'eina també com un sistema de còpies: diverses destinacions per lloc (local, SFTP, SCP, S3, Dropbox, rclone) amb retenció pròpia. En realitat, **els llocs ja tenen còpies** fetes pel hosting (Plesk) i a Dropbox. Duplicar-les costaria espai i diners sense aportar gaire.

El que l'eina sí que necessita és poder **desfer les seves actualitzacions** de manera segura i ràpida, i saber si hi ha hagut comandes noves des de la còpia (regla de seguretat 4). A més, cal fixar quant temps es guarden els logs, les captures, els artefactes i la còpia de l'eina mateixa.

## Decisió

### 1. L'eina fa punts de restauració, no còpies de llarga durada

- Un **punt de restauració** és la còpia que l'eina fa just abans d'actualitzar un lloc, i només serveix per fer el rollback d'aquella actualització.
- **Contingut:** dump complet de la base de dades (totes les taules del prefix) i arxiu dels fitxers de codi (core, plugins, temes, mu-plugins i drop-ins). **Exclou `uploads` per defecte**, perquè les actualitzacions no hi toquen; un lloc concret el pot incloure des del perfil. També desa la marca d'aigua de comandes i usuaris (UZEROWPUP-58).
- **Integritat:** SHA-256 de cada arxiu i validació de les taules del dump. Sense un punt de restauració verificat, no hi ha actualització (regla de seguretat 3).
- **On:** al servidor del mateix lloc, dins del compte d'hosting i fora del docroot, amb permisos restrictius. El rollback és immediat i no passa per la xarxa.
- **Retenció:**
  - Actualització correcta: es conserva **7 dies**, per si un problema apareix tard.
  - **Com a màxim 2 punts per lloc.** Si se'n fa un de tercer, s'esborra el més antic, excepte si està protegit.
  - Actualització revertida o amb incidència pendent: el punt queda **protegit** fins que un operador marca l'execució com a revisada.
  - Abans de crear-ne un, es comprova que hi ha prou espai a la quota del compte d'hosting (UZEROWPUP-34).
- **Còpia externa opcional, per lloc** (desactivada per defecte): un lloc sense còpies pròpies pot pujar el punt de restauració a una destinació de l'eina (`BackupTarget`), amb la mateixa retenció.

### 2. Les còpies de llarga durada són les que ja teniu, però l'eina les vigila

- **Declarades a la fitxa del lloc:** tipus (Plesk, Dropbox, plugin, altre, cap) i notes. Les omple un `admin`.
- **Detecció a l'inventari:** plugins de còpia (UpdraftPlus, BackWPup, All-in-One WP Migration, Duplicator, BlogVault…), i la data de l'última còpia quan el plugin l'exposa.
- **Avís:** l'inventari setmanal i el tauler del panell destaquen els llocs **sense cap còpia externa coneguda** o amb l'última còpia detectada massa antiga (llindar configurable, per defecte 7 dies).

### 3. Còpia de l'eina mateixa: a Dropbox

- **Diària:** `pg_dump` de la BD de l'eina, la configuració no secreta i els informes. Els artefactes de paquets i les captures **no** s'inclouen, perquè es poden regenerar o tenen retenció pròpia.
- **Xifrada abans de sortir de la VM**, amb una clau de còpia (`age`), la part privada de la qual es guarda al KeePassXC d'Arnau, al costat de la KEK (ADR-0002). La BD ja té les credencials xifrades amb la KEK, però també conté dades de clients i de facturació.
- **Destinació:** Dropbox, fora de Proxmox. La credencial de Dropbox es guarda al `SecretStore`.
- **Retenció:** **30 còpies diàries i 12 de mensuals**, que són la primera de cada mes.
- La restauració es prova dins del procediment anual de recuperació (UZEROWPUP-28).

### 4. Retenció de dades de l'eina

| Dades | Retenció |
| --- | --- |
| Sortida completa dels passos (`run_steps`) d'execucions correctes | 90 dies |
| Sortida completa d'execucions fallides o revertides | 1 any |
| Resum de cada execució, canvis (`run_changes`), verificacions (sense imatges) i informes | Sempre |
| Captures sense diferències ni errors | 30 dies |
| Captures d'execucions amb avís o error | 180 dies |
| Captures de l'última execució de cada lloc | Sempre (fins que n'hi ha una de més nova) |
| Artefactes de paquets | Mentre existeixi el punt de restauració de l'execució, i com a mínim 30 dies |
| `audit_log` | **Mai no es purga** (només d'inserció, ADR-0004) |
| Registre de connexions de sortida (ADR-0003) | 90 dies |

- Un job diari de neteja aplica aquesta taula. Té `--dry-run`, i cada purga deixa un resum a `audit_log`.
- Tots els valors són a `config/wpup.php`.

## Opcions considerades

- **L'eina com a sistema de còpies complet** (com deia l'especificació): duplica les còpies que ja existeixen, ocupa espai a l'hosting i a les destinacions, i porta més feina. Descartada.
- **Fer servir les còpies existents per al rollback:** no se'n pot garantir l'estat ni la data, i entre aquella còpia i l'actualització hi pot haver comandes noves. Descartada.
- **Punt de restauració sempre a una destinació externa:** més segur davant d'una caiguda del servidor del lloc, però duplica les còpies existents. Queda com a opció per lloc.

## Conseqüències

- **Més fàcil:** menys espai i menys cost; rollbacks immediats; la responsabilitat de cada còpia queda clara.
- **Més difícil:** si el servidor del lloc es perd just després d'una actualització, el punt de restauració es perd amb ell. Aleshores cal recórrer a les còpies del hosting o de Dropbox. Per això l'eina vigila que existeixin.
- **Canvis al pla:** la destinació Dropbox passa a ser necessària aviat (per a la còpia de l'eina), mentre que SFTP i S3 queden com a opcionals.

## Accions

1. [ ] UZEROWPUP-39 Arxiu de codi sense `uploads` per defecte
2. [ ] UZEROWPUP-44 Retenció dels punts de restauració (7 dies, màxim 2, protecció)
3. [ ] UZEROWPUP-47 Còpia diària de l'eina xifrada amb `age` a Dropbox (30 diàries i 12 mensuals)
4. [ ] UZEROWPUP-107 Destinació Dropbox (passa a F2)
5. [ ] UZEROWPUP-133 Còpies externes conegudes: declaració, detecció i avís
6. [ ] UZEROWPUP-134 Job de neteja amb la taula de retenció
