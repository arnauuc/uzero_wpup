# Runbook: represa d'actualitzacions interrompudes

Què passa quan el worker que executa una actualització mor (caiguda del procés, de la VM, de Redis o de la xarxa) a mig camí, pas per pas (UZEROWPUP-62). La regla de l'especificació: una execució interrompuda es reprèn o es reverteix, però mai queda a mitges en silenci.

## Com es detecta i es reprèn

1. El worker té un *claim* de l'execució a Redis (`wpup.runs.claim_ttl_seconds`, 60 s) i el renova cada `heartbeat_seconds` (10 s), igual que el slot de concurrència i el lock del lloc i del compte.
2. El vigilant (`engine:recover`, cada minut) marca `interrupted` tota execució `running` o `rolling_back` sense claim (`audit_log`: `run.interrupted`) i la torna a encuar.
3. El motor la reprèn: agafa de nou el slot i els locks (si estan ocupats, espera com qualsevol altra), reconstrueix els passos a partir del que ha desat l'execució i continua **en la fase on era**: endavant (`running`) o el rollback (`rolling_back`).
   - El pas que estava en curs torna a `pending` i es repeteix (cada pas és idempotent). Compta un intent més.
   - Si ha mort entre dos passos, es continua amb el següent.
   - Si el pas ja s'ha interromput `wpup.runs.max_step_attempts` vegades (2), no es torna a provar: el pas falla i s'aplica la seva política (rollback o aturada).
   - Si el termini de l'execució (`wpup.runs.timeout_seconds`) ja ha passat, el pas següent d'endavant falla i s'aplica la seva política. El rollback no té termini.
   - **La finestra de manteniment no s'espera:** una execució que ja ha començat es reprèn o es reverteix ara, encara que la finestra s'hagi tancat. Només espera la finestra una execució que no ha començat.

Mentre no hi ha worker (fins al TTL del claim més el minut del vigilant, uns 2 minuts si la resta del sistema funciona), el lloc es queda tal com estava en el moment de la caiguda. Un `.maintenance` que hagi deixat l'eina continua tancant la botiga, però WordPress l'ignora quan té més de 10 minuts; en reprendre, les actualitzacions el tornen a escriure abans de cada instal·lació.

## Matriu: pas → què es fa si s'interromp

Pipeline de l'actualització in-place (`InPlaceUpdatePipeline`). «Política» és el que passa si el pas falla (també per massa interrupcions): **atura** = l'execució falla sense desfer res; **recupera** = s'executa la branca de rollback de sota, en ordre.

| Pas | Política | Si el worker mor durant el pas | Si mor just després |
| --- | --- | --- | --- |
| `inventory` | atura (no ha tocat res) | Es repeteix. La instantània de l'execució se substitueix (una per execució). El probe HTTP que hagi quedat al lloc caduca i s'esborra sol. | Es continua amb el pla. |
| `plan` (amb l'anàlisi estàtica) | atura | Es repeteix. Els artefactes ja publicats d'aquesta execució es reutilitzen (es tornen a comprovar amb el SHA-256 abans de fer-los servir); els temporals `.part` de l'intent mort s'esborren. Les files del pla a `run_changes` se substitueixen. | En reprendre es tornen a construir els passos a partir del pla desat (creixement del pipeline) i es continua. |
| `maintenance-check` | atura | Es repeteix (només llegeix). | — |
| `backup` | atura | Es repeteix: un punt de restauració complet d'aquesta execució (amb els bytes encara al seu lloc) es reutilitza; un d'incomplet o alterat es descarta i es refà. Si la destinació no respon, el pas falla sense descartar res. | — |
| `smoke:php-log-baseline` | atura | Es repeteix (només llegeix; encara no s'ha actualitzat res). | — |
| `restore-point-gate` | atura | Es repeteix (només llegeix la BD de l'eina). Cap actualització sense punt verificat (regla 3). | — |
| `maintenance-on` | atura | Es repeteix: l'estat d'abans es desa a `runs.flags` **abans** d'activar res, així que la repetició sap quin era. Si s'esgoten els intents, l'execució falla i el lloc pot quedar en manteniment (tancat, l'estat segur): cal revisar-lo. | — |
| `update:<tipus>:<slug>` | recupera | Es repeteix llegint primer la versió instal·lada: la del pla ⇒ s'instal·la (els paquets que hagi deixat l'intent mort s'esborren abans de pujar el nou); la nova **i** aquesta execució n'havia registrat la instal·lació acabada ⇒ no es toca; la nova **sense** aquest registre ⇒ **falla ⇒ rollback** (la capçalera pot ser nova i la resta de fitxers no: no es pot saber si el component és sencer); qualsevol altra ⇒ falla ⇒ rollback. | — |
| `post-update` | recupera | Es repeteix: cada ordre (`core update-db`, `wc update`, memòries cau) no fa res si ja està feta. | — |
| `maintenance-off` | recupera | Es repeteix (idempotent; només toca el que aquesta execució ha activat). | — |
| `smoke:check` | recupera | Es repeteix contra la mateixa línia base del log: torna a comprovar la portada i els fatals nous. | — |
| `maintenance-restore` | atura (no desfà una actualització verificada) | Es repeteix (idempotent). | L'execució acaba com a `succeeded`. |

Branca de rollback (passos `recovery`, en ordre), quan falla un pas de l'interval `update:*` … `smoke:check`:

| Pas | Si el worker mor durant el pas |
| --- | --- |
| `rollback-maintenance-on` | Es repeteix (idempotent). Marca a l'execució que el rollback vol el lloc tancat (`flags.maintenance_rollback`). |
| `rollback-files` | Es repeteix el mirall amb el mateix arxiu i es tornen a comprovar les versions. El mirall esborra `.maintenance`: si l'intent mort l'ha esborrat i el rollback el volia actiu, **es torna a activar abans del mirall**, de manera que la botiga continua tancada. |
| `rollback-database` | Es continua amb **la mateixa còpia**: la decisió («cap escriptura nova, importació de la còpia X començada») es desa a la fila del pas abans d'importar, i no es torna a comprovar contra una BD a mig importar. Si hi havia comandes o usuaris nous, no es restaura (regla 4). |
| `maintenance-restore` | Es repeteix (idempotent): deixa el mode com estava abans de l'execució. |

Si un pas de la branca falla (també per massa interrupcions), la branca s'atura: l'execució acaba `failed` amb el lloc en manteniment (si `rollback-maintenance-on` ha pogut activar-lo) i el punt de restauració protegit fins que algú en revisi l'execució (`wpup run:ack`). Cal intervenció manual.

## Casos transversals

| Cas | Què passa |
| --- | --- |
| Mort entre la fallada d'un pas i l'inici del rollback | En reprendre es veu el pas fallat i es comença la branca de rollback. |
| Mort a mig rollback | Es reprèn en fase `rolling_back`, pel pas de la branca on era. |
| Mort després de l'últim pas i abans de tancar l'execució | Es tanca com a `succeeded` sense repetir res. |
| S'esgoten els intents d'un pas d'endavant | El pas falla: rollback (passos `update:*` … `smoke:check`) o aturada (la resta). |
| S'esgoten els intents d'un pas del rollback | `failed`, lloc en manteniment, cal revisar-lo (vegeu més amunt). |
| La finestra de manteniment s'ha tancat | Es reprèn igualment. |
| El termini de l'execució ha passat mentre no hi havia worker | El pas següent d'endavant falla ⇒ la seva política (rollback si ja s'ha tocat el lloc). |
| El worker perd el claim o el lock a mig pas (Redis caigut, worker bloquejat més que el TTL) | El worker s'atura de seguida sense tocar l'execució; el vigilant la marca interrompuda i es reprèn com si hagués mort. |
| Desplegament que canvia els passos entre la caiguda i la represa | Endavant: si els passos registrats ja no coincideixen amb els nous, falla el pas següent ⇒ la seva política. Si no es pot ni reconstruir l'execució, falla amb el punt de restauració guardat. Durant el rollback: un pas registrat que ja no existeix fa fallar l'execució (abans es reintentava cada minut sense fi). |

**Risc residual.** Quan el worker mor, una ordre remota que estava en curs (una instal·lació, un mirall, una importació) pot continuar al servidor fins al seu propi timeout (`timeout` de coreutils). Si la represa arriba abans, el pas repetit es pot creuar amb l'ordre antiga. Els passos ho toleren en gran part (comproven l'estat abans d'actuar i després), però no es pot descartar del tot.

## Avís a l'operador

Cada represa queda registrada, sigui quin sigui el resultat final de l'execució:

- A l'execució, a `runs.interruptions` (una entrada per interrupció, només s'hi afegeix): quan, la fase (`running` o `rolling_back`), el pas i el seu tipus, els intents que ja havia fet i la decisió: `retry_step` (es repeteix el pas), `next_step` (es continua amb el següent), `give_up` (massa interrupcions: el pas falla i s'aplica la seva política) o `fail` (no s'ha pogut reconstruir: l'execució falla).
- A `audit_log`, com a `run.resumed` amb les mateixes dades (resultat `failure` per a `give_up` i `fail`), a més del `run.interrupted` del vigilant.
- A la sortida de `wpup update` (amb `--wait`/`--follow`) i de `wpup runs`: una línia d'avís per interrupció amb el pas i la decisió; al JSON, el camp `interruptions`.

Els canals de notificació (F5) faran servir aquestes mateixes dades.

## Què ha de fer l'operador

1. `wpup runs --site <lloc>`: mira l'estat final i els avisos d'interrupció.
2. `succeeded` o `rolled_back` amb interrupcions: l'eina ha acabat la feina; comprova el lloc com faries després de qualsevol rollback i revisa'n l'execució (`wpup run:ack`).
3. `failed` després d'una interrupció a mig rollback: el lloc pot estar en manteniment i amb els fitxers o la BD a mig restaurar. No el tornis a obrir sense revisar-lo: restaura'l a mà des del punt de restauració de l'execució (`wpup backup:restore`) i, si no n'hi ha prou, avisa.
