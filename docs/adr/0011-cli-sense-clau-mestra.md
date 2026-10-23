# ADR-0011: La CLI no té mai la clau mestra

**Estat:** Proposat
**Data:** 2026-10-23
**Decideixen:** Arnau
**Tasca:** UZEROWPUP-137

Modifica l'ADR-0002 (punts 1, 2 i 7). La resta de l'ADR-0002 continua vigent.

## Context

- **Com es desxifra la KEK.** L'ADR-0002 segella la KEK amb el TPM (`systemd-creds --with-key=tpm2+host`). Només systemd (PID 1) la pot desxifrar, i només l'entrega a un servei que la declari amb `LoadCredentialEncrypted=`.
- **El problema amb sudo.** Els operadors executen `sudo -u wpup wpup …` (ADR-0002, punt 1). Un procés llançat així no és un servei de systemd i no rep la KEK. Per això l'ADR-0002 deixava la porta oberta a «un helper restringit».
- **Proposta descartada.** A UZEROWPUP-28 es va proposar aquest helper: una regla de sudoers cap a root que engegava la CLI com a servei transitori amb la KEK. Arnau la descarta (2026-10-23): no vol cap regla que faci passar els operadors per root cada vegada que fan servir l'eina.
- **Comandes afectades.** Sense la KEK, la CLI no pot ni desar ni llegir credencials, i diverses comandes la necessiten: `site:test`, `ssh-key:*`, `secrets:*` i, en general, tot el que es connecta a un lloc.

## Decisió

### 1. La clau mestra passa a ser un parell de claus

- La KEK passa a ser un **parell de claus X25519** (libsodium).
  - **Clau privada:** 32 bytes. Té la mateixa custòdia que la KEK de l'ADR-0002: segellada amb `systemd-creds --with-key=tpm2+host`, en brut al KeePassXC d'Arnau, i lliurada **només als serveis** (workers d'Horizon i scheduler) amb `LoadCredentialEncrypted=`.
  - **Clau pública:** no és secreta. Es desa a la configuració de l'eina, llegible per l'usuari `wpup` (`WPUP_SECRETS_PUBLIC_KEY_V<n>`), i es deriva de la privada en el desplegament.
- **Desar un secret** (CLI, panell web o workers):
  - es fa amb una DEK aleatòria (XChaCha20-Poly1305, amb l'id del secret com a dades associades, igual que ara);
  - la DEK es tanca amb `crypto_box_seal` i la clau pública de la versió vigent;
  - dins del sobre hi van la DEK, l'id del secret i la versió, i en obrir-lo es comprova que l'id i la versió coincideixen.

  Qui té la clau pública pot xifrar, però **no pot desxifrar res**.
- **Llegir un secret** només és possible en un servei amb la clau privada: els workers.

### 2. La CLI i el web demanen les operacions amb credencials als workers

- Els operadors continuen fent servir exactament `sudo -u wpup /opt/uzero-wpup/wpup …` (ADR-0002, punt 1), **sense cap regla cap a root**.
- **Operacions que necessiten un secret en clar.** Per exemple, connectar-se a un lloc (`site:test`, inventari, actualitzacions…), `ssh-key:rotate` o `secrets:rekey`.
  - La CLI no les executa: les encua i n'espera el resultat, com ja fan les execucions (`--follow` i `--wait`, UZEROWPUP-24).
  - El job porta l'operador que l'ha demanat, i el worker hi torna a aplicar la policy.
- **Operacions que només desen un secret** es fan a la CLI, perquè només cal la clau pública:
  - `ssh-key:generate`: genera la clau i la desa xifrada;
  - `secrets:set-password`: la contrasenya s'escriu sense eco i es xifra a la mateixa CLI;
  - qualsevol alta de credencials.

  El secret en clar **mai passa per la cua**: Redis i els jobs només porten identificadors.
- El panell web (PHP-FPM) segueix el mateix model: no té la clau privada.

### 3. Rotació de la clau mestra (substitueix l'ADR-0002, punt 7)

- **Qui crea el parell nou:** root. El segella i el carrega als serveis amb totes dues versions, i publica la clau pública nova. Ho detalla el runbook.
- **Qui torna a xifrar:** `secrets:rekey`, que s'executa **als workers**, perquè ha de desxifrar amb la versió antiga i tancar amb la nova.

## Opcions considerades

### Opció A: helper de root que engega la CLI com a servei (proposta d'UZEROWPUP-28)

| Dimensió | Valoració |
| --- | --- |
| Complexitat | Baixa |
| Seguretat | Bona, però hi ha un fitxer que corre com a root i s'ha d'auditar, i els operadors fan servir la KEK de manera indirecta |
| Operació | Simple |

**En contra:** Arnau no vol cap regla de sudoers cap a root. Descartada.

### Opció B: servei de systemd amb socket i SO_PEERCRED

La CLI parla per un socket Unix amb un servei que té la KEK, i el servei identifica l'operador amb `SO_PEERCRED`.

| Dimensió | Valoració |
| --- | --- |
| Complexitat | Alta: terminal interactiu, senyals, codis de sortida i streaming |
| Seguretat | Bona, i sense sudo cap a root |

**En contra:** reimplementa una part de la CLI per sobre d'un protocol propi, i el servei tindria la KEK sempre disponible per a qualsevol comanda.

### Opció C: KEK asimètrica i operacions als workers (triada)

| Dimensió | Valoració |
| --- | --- |
| Complexitat | Mitjana: canvia el format del sobre i fa passar algunes comandes per la cua |
| Seguretat | La millor: cap procés llançat per un operador pot desxifrar res, ni tan sols indirectament fora dels jobs auditats |
| Operació | Calen els workers i Redis en marxa per a les comandes que es connecten als llocs |

## Anàlisi de compromisos

L'opció C és la que redueix més el que poden fer els operadors: la CLI pot desar credencials però mai llegir-les. Encaixa amb el que l'eina ja fa amb les execucions (encuar i esperar). El cost és que `site:test` i les comandes de claus depenen del worker, i que el format del sobre canvia. Ara mateix no hi ha dades reals, així que no cal cap migració de producció.

## Conseqüències

- **Més fàcil:**
  - no hi ha cap regla de sudoers cap a root;
  - la KEK privada només existeix dins dels serveis;
  - un error a la CLI o al web no pot exposar cap secret.
- **Més difícil:**
  - `site:test` i `ssh-key:rotate` necessiten el worker en marxa;
  - les preguntes interactives d'aquestes comandes (per exemple, confirmar la host key) s'han de fer a la CLI abans d'encuar, o en dues fases.
- **Cal revisar:**
  - l'ADR-0002: el punt 1 es manté, el 2 canvia la KEK pel parell de claus i treu el helper, i el 7 queda substituït;
  - la tasca UZEROWPUP-28 (el desplegament sense helper);
  - `docs/runbooks/rotacio-kek.md`.

## Accions

1. [ ] UZEROWPUP-137: Arnau accepta aquest ADR.
2. [ ] UZEROWPUP-138: `SecretStore` amb sobre segellat (clau pública per escriure, privada només als workers), operacions amb credencials encuades des de la CLI, `secrets:rekey` als workers, i tests negatius que comprovin que la CLI no pot desxifrar res.
3. [ ] UZEROWPUP-28: treure el helper de root i tornar a `sudo -u wpup`; desplegar la clau pública.
