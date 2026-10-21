# ADR-0006: Execucions programades: finestres per lloc i execució seqüencial

**Estat:** Acceptat (2026-10-21)
**Data:** 2026-10-21
**Decideixen:** Arnau
**Tasca:** UZEROWPUP-9

## Context

L'especificació deixava obert si les execucions serien programades o sempre manuals. Com a objectiu de rendiment, parlava d'uns 50 llocs per nit amb 4 llocs alhora.

Arnau ha decidit que hi haurà **actualitzacions programades**, normalment de nit, però **cada lloc ha de poder tenir la seva hora**: alguns es poden actualitzar de dia. La VM, però, no té capacitat per actualitzar diversos llocs alhora: cada staging aixeca contenidors amb PHP i MySQL/MariaDB, i les còpies i el verificador (Chromium) també són costosos. Per això les actualitzacions **han d'anar d'una en una**, i cada staging **s'ha d'esborrar** abans de començar el lloc següent.

Una estimació aproximada: un lloc in-place tarda 5–10 minuts i un amb staging, 20–30. En una finestra de 5 hores, doncs, no hi caben tots els llocs cada nit. Cal repartir la feina entre diverses finestres i aprofitar les de dia dels llocs que ho permeten.

## Decisió

### 1. Finestres de manteniment per lloc

- **Cada lloc té la seva finestra** (camp del perfil): hora d'inici, hora de final i dies de la setmana, a la zona Europe/Madrid. Pot ser de nit o de dia (p. ex. de dilluns a divendres de 10:00 a 13:00), i pot travessar la mitjanit.
- **Finestra per defecte** per als llocs que no en tenen cap de pròpia: cada dia de 01:00 a 06:00 (`config/wpup.php`).
- Un **planificador continu** (el scheduler de Laravel, cada minut) manté una única cua d'execució. Hi afegeix els llocs que tenen la finestra oberta i encara no s'han tractat en aquella finestra, i els executa **d'un en un**.
- Una finestra fixa *quan pot començar* un lloc, no *quan ha d'acabar el de tothom*: si s'obren diverses finestres alhora, els llocs comparteixen l'única cua amb l'ordre de prioritat del punt 3.
- **Només els llocs amb l'actualització automàtica activada** (`auto_update`, desactivat per defecte). S'activa lloc a lloc, normalment després d'una primera execució manual correcta.
- Les execucions programades apliquen la política de l'ADR-0005: quarantena, excepcions de seguretat i staging obligatori per als majors. Mai fa servir `--allow-major` ni `--skip-quarantine`, que són només per a execucions manuals d'un admin.
- **A l'auditoria i als informes**, l'operador de les execucions programades és `sistema (scheduler)`. Els avisos van als canals configurats per a cada lloc i per als admin.

### 2. Execució seqüencial i neteja

- **Concurrència = 1:** mai hi ha més d'una execució d'actualització alhora, sigui programada o manual. Les execucions manuals entren a la mateixa cua i esperen, excepte si un admin les llança amb `--now`, que és auditat.
- **Staging efímer:** el contenidor i les dades de staging d'un lloc s'esborren en acabar aquell lloc, tant si ha anat bé com si no, i abans de començar el següent. Si en queda algun d'orfe (per una caiguda), el planificador el neteja abans de començar el lloc següent.
- **Comprovació de recursos:** abans de cada lloc es comprova que hi ha prou disc i memòria lliures (llindars a `config/wpup.php`). Si no n'hi ha, el planificador s'atura i avisa, en lloc de començar un lloc que no podrà acabar.
- Els valors per defecte de concurrència de l'especificació (4 globals) canvien: **1 global**, 1 per servidor d'hosting i 1 per staging. Només s'haurien d'augmentar si la VM creix.

### 3. Ordre de la cua i repartiment entre finestres

- **Ordre de la cua:**
  1. Llocs amb una actualització de **seguretat** pendent (vulnerabilitat coneguda o core `x.y.z`).
  2. Llocs que fa més temps que no s'actualitzen correctament.
  3. La resta, per ordre alfabètic.
- **Llocs sense res a fer:** els que no tenen cap actualització aplicable (tot al dia, tot en quarantena o tot congelat) s'ometen sense consumir temps. Només es registra al resum diari.
- **Temps restant de la finestra *del lloc*:** abans de començar un lloc, s'estima la seva durada amb l'històric (mitjana de les últimes execucions, segons l'estratègia). Si no hi cap dins del que queda de la seva finestra (comptant l'espera a la cua), es deixa per a la seva finestra següent, amb prioritat. Mai es comença un lloc que acabaria fora de la seva finestra.
- **Llocs que han fallat:** si un lloc falla i es reverteix, no es torna a intentar dins de la mateixa finestra. A la finestra següent es torna a intentar, i després de 2 fallades seguides queda bloquejat per a l'automàtic, amb avís, fins que un operador el revisi.

### 4. Resum diari

- Cada matí (07:00, configurable) s'envia un **resum agregat** de les últimes 24 hores: llocs actualitzats, omesos (amb el motiu), revertits, bloquejats i pendents per a la seva finestra següent, i també els majors que esperen staging. Cada execució continua tenint el seu informe i les seves notificacions.

### 5. Inventari setmanal

- Un cop per setmana (dilluns a les 07:00) s'executa `inventory` i `plan` per a **tots** els llocs, també els que no tenen l'actualització automàtica activada. També és seqüencial, però molt més lleuger, perquè no fa còpies ni staging. Envia un resum de l'estat de la flota: versions, PHP sota els mínims futurs, vulnerabilitats i majors pendents.

### 6. No hi ha còpies programades separades

- Cada actualització ja fa la seva còpia completa i verificada. No es programen còpies independents per no carregar la VM. Es pot revisar per a llocs concrets (camp del perfil), si algun ho necessita.

## Opcions considerades

- **Sempre manual:** màxim control, però acumula llocs desactualitzats i depèn que algú se'n recordi. Descartada.
- **Paral·lel (4 alhora), com deia l'especificació:** la VM no ho suporta, sobretot amb staging. Descartada.
- **Només inventari i pla automàtics:** no resol l'objectiu d'actualitzar la flota. Queda com a part del punt 5.

## Conseqüències

- **Més fàcil:** la flota s'actualitza sola, prioritzant la seguretat, sense saturar la VM. Els llocs que ho permeten es poden actualitzar de dia, amb l'equip disponible, i alliberen la nit.
- **Més difícil:** un lloc concret pot trigar unes quantes finestres a actualitzar-se si hi ha molts majors en staging. L'ordre de la cua i el resum del matí ho fan visible.
- **Cal vigilar:** la durada real per lloc. Amb l'històric es podrà ajustar la finestra o decidir si la VM necessita més recursos.

## Accions

1. [ ] UZEROWPUP-129 Planificador: finestres per lloc, cua seqüencial, prioritats, estimació de durada i resum diari
2. [ ] UZEROWPUP-20 Límits de concurrència: valors per defecte 1 / 1 / 1
3. [ ] UZEROWPUP-100 Staging efímer: s'esborra en acabar cada lloc i es netegen els orfes en començar
4. [ ] UZEROWPUP-130 Inventari i pla setmanals de tota la flota
