# ADR-0003: Xarxa i accés a la VM de l'eina

**Estat:** Proposat
**Data:** 2026-10-21
**Decideixen:** Arnau
**Tasca:** UZEROWPUP-125

## Context

La VM de l'eina (Proxmox, amb TPM virtual; vegeu l'ADR-0002) té les credencials de tota la flota. Ha de ser:

- **Inaccessible des de fora**, excepte per als operadors autoritzats.
- **Capaç de sortir** a Internet per fer la feina: SSH als servidors Plesk, HTTPS als llocs (verificació), a WordPress.org i als proveïdors de plugins premium (descàrrega de paquets), a Quipu i a les destinacions de còpia (S3, SFTP, Dropbox).

L'especificació deia «accessible només per VPN o amb una llista d'IPs permeses». Arnau ha decidit que la VPN és una tailnet de Tailscale. L'IP pública de sortida de la xarxa de Proxmox és fixa.

## Decisió

### 1. Entrada només per la tailnet

- La VM s'uneix a la tailnet de l'empresa amb l'etiqueta `tag:wpup-server`, amb una clau d'autenticació d'un sol ús. Com que és un node etiquetat, la clau del node no caduca.
- **nftables** amb política d'entrada `drop` a totes les interfícies. Només s'accepta tràfic de `lo`, connexions ja establertes i, a `tailscale0`, els ports TCP 22 (sshd) i 443 (Tailscale Serve). La LAN de Proxmox tampoc hi té accés.
- **Docker** manipula iptables i pot exposar ports encara que el tallafocs no ho permeti. Per això està prohibit publicar ports de contenidors a `0.0.0.0`: es lliguen sempre a `127.0.0.1`, i hi ha una regla de seguretat a la cadena `DOCKER-USER`.
- **ACL de la tailnet**, versionades a `docs/` i aplicades al panell de Tailscale: només `group:wpup-operators` (els operadors i els admin de l'eina) poden arribar a `tag:wpup-server:22,443`. El node de l'eina no pot iniciar connexions cap a altres nodes de la tailnet.
- **Accés d'emergència:** la consola de la VM al Proxmox. Si cau Tailscale, és l'única entrada.

### 2. Operadors per OpenSSH

- `sshd` només admet claus, sense contrasenya ni `keyboard-interactive`. `PermitRootLogin no` i `AllowGroups wpup-operators wpup-admins`.
- Cada operador té la seva clau al seu usuari Unix. Donar de baixa una persona vol dir treure-la del grup de la tailnet i esborrar el seu usuari Unix (amb la baixa corresponent a `users`).
- `sshd` escolta a totes les adreces, però el tallafocs només deixa passar el port 22 per `tailscale0`. Així s'evita dependre de l'ordre d'arrencada de Tailscale i de sshd: si sshd escoltés només a l'IP de la tailnet i arrenqués abans que Tailscale, no s'hi podria lligar.
- Un cop dins, la feina passa pel model de l'ADR-0002 (`sudo -u wpup`).

### 3. Serveis web amb Tailscale Serve

- El visor d'informes i el dashboard d'Horizon escolten només a `127.0.0.1`, i **Tailscale Serve** els publica a la tailnet en HTTPS, amb el certificat automàtic de MagicDNS (`https://<node>.<tailnet>.ts.net`).
- **Tailscale Funnel és prohibit:** no s'ha d'activar mai, perquè publicaria els serveis a Internet. El desplegament ho comprova.
- El visor continua tenint autenticació pròpia. Com a opció per a l'ADR del visor (UZEROWPUP-8): Tailscale Serve afegeix capçaleres d'identitat (`Tailscale-User-Login`) que podrien servir per identificar l'usuari, però només si es confia en peticions que arriben de `127.0.0.1` a través de Serve.
- Horizon només és accessible per als `admin` (Gate de Laravel).

### 4. Sortida oberta, sense *exit node* i amb registre

- La VM surt a Internet per la ruta per defecte de la xarxa de Proxmox, **mai per un *exit node*** de Tailscale. L'IP pública de sortida ha de ser la fixa, perquè les claus SSH dels servidors Plesk porten `from="<IP>"` i l'agent PHP pot filtrar per IP.
- Aquesta IP es desa a `config/wpup.php` (`egress_ip`). `wpup doctor` i `site:test` comproven que l'IP real de sortida hi coincideix, i avisen si no.
- **Cap llista de destinacions permeses** a la VM: els plugins premium es descarreguen de dominis massa variats per mantenir-ne una. Com a contrapartida, es registren a journald les connexions de sortida noves (nftables, amb un límit de ritme), amb una retenció de 90 dies.
- Els contenidors de staging sí que tenen la sortida restringida (UZEROWPUP-102).

## Opcions considerades

### Opció A: tailnet + OpenSSH + Tailscale Serve (triada)

| Dimensió | Valoració |
| --- | --- |
| Complexitat | Baixa–mitjana |
| Seguretat | Bona: no hi ha cap port exposat a Internet ni a la LAN |
| Operació | Altes i baixes centralitzades a la tailnet i als usuaris Unix |

### Opció B: Tailscale SSH

| Dimensió | Valoració |
| --- | --- |
| Complexitat | Baixa (sense claus SSH d'operador) |
| Seguretat | Molt bona (identitat SSO, mode de comprovació) |
| Operació | Depèn completament de Tailscale també per a la identitat |

**Descartada per decisió d'Arnau:** es prefereix OpenSSH, que és estàndard i independent de Tailscale un cop s'és a la xarxa. Es pot reconsiderar més endavant.

### Opció C: IP pública amb una llista d'IPs permeses

| Dimensió | Valoració |
| --- | --- |
| Complexitat | Baixa |
| Seguretat | Feble: el port queda exposat i depèn d'IPs dels operadors que canvien |

## Anàlisi de compromisos

Amb la tailnet, la VM no té cap superfície exposada i l'accés es gestiona per persona, no per IP. Mantenir OpenSSH evita lligar-se a Tailscale per a la identitat. Deixar la sortida oberta és una concessió pràctica, perquè els plugins premium es descarreguen de llocs molt diversos. La compensen el registre de connexions, la sortida per una IP fixa i coneguda, i el fet que els paquets s'analitzen i se'n comprova el checksum abans d'instal·lar-los.

## Conseqüències

- **Més fàcil:** donar i treure accés (grup de la tailnet i usuari Unix) i publicar el visor en HTTPS sense gestionar certificats.
- **Més difícil:** si cau Tailscale, només s'hi pot entrar per la consola de Proxmox. Les execucions programades continuen funcionant, perquè la sortida no depèn de Tailscale.
- **Cal vigilar:** que Docker no exposi ports, que ningú activi Funnel ni un *exit node*, i que l'IP de sortida no canviï sense actualitzar les claus (`from=`).

## Accions

1. [ ] UZEROWPUP-125 Revisar i acceptar aquest ADR
2. [ ] UZEROWPUP-126 Xarxa de la VM: Tailscale, nftables, sshd i Tailscale Serve
3. [ ] UZEROWPUP-28 Desplegament de la VM (secrets i serveis)
