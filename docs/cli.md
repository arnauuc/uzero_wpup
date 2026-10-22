# CLI `wpup`: opcions comunes

Totes les comandes de l'operador s'executen amb `sudo -u wpup wpup <comanda>` (ADR-0002). El binari `wpup` només deixa passar les comandes de l'eina (`config/wpup.php`, `cli.allowed_commands`).

## Selecció de llocs

Les comandes que actuen sobre llocs accepten **una** d'aquestes formes:

| Forma | Exemple | Què selecciona |
| --- | --- | --- |
| Noms | `wpup update botiga-a botiga-b` | Aquests llocs, encara que estiguin inactius (la comanda decideix què fer-ne) |
| `--tag=<etiqueta>` (repetible) | `wpup update --tag=woo --tag=nit` | Els llocs **actius** que tenen qualsevol de les etiquetes |
| `--all` | `wpup inventory --all` | Tots els llocs actius |

Barrejar formes, no indicar-ne cap o escriure un nom desconegut és un error, i no es fa res.

## `--dry-run`

Totes les comandes que fan canvis tenen `--dry-run` (AGENTS.md, regla 9). Ho comprova un test sobre totes les comandes registrades que implementen `App\Console\ChangesState`. En una execució, el dry-run fa córrer només els passos de lectura (inventari, comprovacions) i descriu la resta.

## Execucions encuades

Les comandes llargues (inventari, actualització, còpia, verificació, rollback…) creen una execució per lloc, l'encuen i tornen de seguida amb el número:

```
Execució #42 encuada (update, botiga-a).
```

| Opció | Efecte |
| --- | --- |
| *(cap)* | Torna de seguida; consulta-la amb `wpup runs` |
| `--follow` | Mostra cada canvi de pas en directe i espera el final |
| `--wait` | Espera el final sense mostrar els passos |

Interrompre `--follow` o `--wait` (Ctrl-C) no atura l'execució, que continua al worker.

**Codi de sortida** amb `--follow` o `--wait` (preval el pitjor resultat):

| Codi | Significat |
| --- | --- |
| 0 | Totes han acabat bé (o s'han omès) |
| 1 | Alguna ha fallat |
| 2 | Alguna s'ha revertit (rollback correcte) |
| 3 | Alguna s'ha cancel·lat |

## `--json`

Per a scripts: la sortida és un sol document JSON a stdout, sense text per a l'operador. Per a execucions: `{"runs": [{"id", "site", "type", "status", "result"}]}`.
