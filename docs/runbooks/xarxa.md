# Runbook: xarxa de la VM (Tailscale, nftables, sshd i Tailscale Serve)

Implementa l'[ADR-0003](../adr/0003-xarxa-i-acces.md) (UZEROWPUP-126). Tot és a `deploy/` i es pot tornar a executar sense risc. Les ACL de la tailnet són a [`docs/tailscale-acl.hujson`](../tailscale-acl.hujson).

## Què queda configurat

| Peça | Fitxer | On acaba |
| --- | --- | --- |
| Tailscale, node `tag:wpup-server` | `deploy/steps/40-tailscale.sh` | paquet oficial, `/etc/default/tailscaled` |
| nftables | `deploy/files/nftables/wpup.nft` (pas `41`) | `/etc/nftables.d/wpup.nft` i `/etc/nftables.conf` |
| Retenció del registre de sortida | `deploy/files/journald/wpup.conf` | `/etc/systemd/journald.conf.d/wpup.conf` |
| sshd | `deploy/files/sshd/00-wpup.conf` (pas `42`) | `/etc/ssh/sshd_config.d/00-wpup.conf` |
| Tailscale Serve | `deploy/steps/43-tailscale-serve.sh` | configuració de Serve de `tailscaled` |
| Docker sense ports cap enfora | `deploy/files/docker/daemon.json`, `wpup-docker-user.*` (pas `44`) | `/etc/docker/daemon.json`, unitat `wpup-docker-user.service` |
| Comprovacions | `deploy/checks/network.sh`, `reachability.sh` | |

## Noms dels grups

- **Grup de la tailnet** `group:wpup-operators`: les persones que poden arribar al node (operadors i admins). Es gestiona al panell de Tailscale.
- **Grups Unix** `wpup-operators` i `wpup-admins`: qui pot entrar per SSH (`AllowGroups`). Tots els admins són també al grup `wpup-admins`; els dos grups tenen el mateix nivell d'accés al sistema (cap no té `sudo` general) i la diferència és el rol a la taula `users` de l'eina. Si cal canviar-ne els noms, fes-ho a `deploy/config.env` (vegeu `config.env.example`).

## Primera instal·lació

Es fa des de la consola del Proxmox (és l'única entrada fins que la tailnet funcioni).

1. **ACL.** Copia les regles de `docs/tailscale-acl.hujson` al panell de Tailscale (Access controls), omple `group:wpup-operators` al panell i comprova que la secció `tests` passa.
2. **MagicDNS i certificats HTTPS.** Activa'ls a la tailnet (Tailscale Serve els necessita).
3. **Clau d'autenticació.** Al panell, crea una clau **d'un sol ús**, no reutilitzable, amb l'etiqueta `tag:wpup-server` i cadena curta (1 hora). No la desis enlloc.
4. **Operadors.** Crea almenys un usuari Unix al grup `wpup-operators` amb la seva clau pública a `~/.ssh/authorized_keys` (el pas `42` es nega a aplicar sshd si no n'hi ha cap, per no deixar a tothom fora).
5. **Tailscale.** Com a root, a la VM:

   ```bash
   cd /opt/uzero-wpup   # o on tinguis el repositori
   ./deploy/provision.sh --only 40
   ```

   Demana la clau sense eco (o pots passar `TS_AUTHKEY=... ` a la mateixa comanda). Es passa a `tailscale up` per un fitxer 0600 a la RAM que s'esborra al moment; no es desa mai al disc ni al repositori.
6. **Prova des d'un portàtil de la tailnet** que hi entres: `ssh <usuari>@<node>.<tailnet>.ts.net`. **No continuïs si no funciona.**
7. **Tallafocs, sshd, Serve i Docker:**

   ```bash
   ./deploy/provision.sh --only 41
   ./deploy/provision.sh --only 42
   ./deploy/provision.sh --only 43
   ./deploy/provision.sh --only 44
   ```

   El pas `41` es nega a córrer si la sessió SSH actual no ve de la tailnet o si Tailscale no funciona (`WPUP_FIREWALL_FORCE=1` ho salta; només des de la consola de Proxmox).
8. **IP de sortida.** Apunta l'IP pública fixa de sortida de la xarxa de Proxmox al `.env` de l'eina (`WPUP_EGRESS_IP=<IP_DE_SORTIDA>`, que llegeix `config('wpup.ssh.egress_ip')`). Aquest valor el posa l'administrador de la xarxa, no es versiona.
9. **Comprovació:** `sudo ./deploy/checks/network.sh` (vegeu més avall).

## Tallafocs (nftables)

- La política d'entrada és `drop` a totes les interfícies. S'accepta `lo`, el tràfic establert o relacionat, ICMP amb límit de ritme i, a `tailscale0`, només TCP 22 i 443 (connexions noves).
- Excepció: les respostes DHCP (UDP 67→68 i 547→546), perquè la VM pot rebre l'adreça per DHCP. No hi escolta cap servei. Si la VM té IP estàtica, es poden treure les dues línies.
- El fitxer només gestiona la taula `inet wpup`. **No fa `flush ruleset`** perquè Docker i Tailscale tenen les seves taules; s'inclou des de `/etc/nftables.conf` amb `include "/etc/nftables.d/*.nft"`.
- La cadena de sortida és oberta (ADR-0003, punt 4) però registra cada connexió nova amb el prefix `wpup-egress:` (amb l'uid que l'obre) i un límit de 30/s amb ràfega de 100.

### Registre de sortida i retenció

```bash
journalctl -k --grep 'wpup-egress:' --since '1 hour ago'
journalctl --disk-usage
```

`/etc/systemd/journald.conf.d/wpup.conf` fixa `MaxRetentionSec=90day` i `SystemMaxUse=4G`. La retenció és de **tot el journal**, no només d'aquestes línies. Si el límit de mida s'omple abans dels 90 dies, journald esborra primer les entrades més antigues: revisa `journalctl --disk-usage` de tant en tant i apuja `SystemMaxUse` en lloc d'abaixar la retenció.

### Docker i el tallafocs

Docker a Debian 13 fa servir `iptables-nft`: les seves regles són a taules nftables pròpies (`ip filter`, `ip nat`...), diferents de la nostra `inet wpup`. Els ports publicats d'un contenidor es redirigeixen (DNAT) abans de decidir la ruta, de manera que **no passen per la cadena `input`** i el nostre `drop` no els veu: passen per `FORWARD`. Per això hi ha tres defenses:

1. Regla de procés: mai es publica res a `0.0.0.0`; sempre `127.0.0.1:port:port`.
2. `"ip": "127.0.0.1"` a `/etc/docker/daemon.json`: si algú oblida l'adreça, Docker lliga a localhost.
3. `wpup-docker-user.service` omple la cadena `DOCKER-USER` (que Docker no buida mai) cada cop que arrenca Docker: accepta el tràfic establert i el que ve dels ponts de Docker, i **descarta la resta**, és a dir, qualsevol connexió nova que arribi per una interfície real o per `tailscale0` cap a un contenidor.

Si en el futur cal restringir la sortida dels contenidors de staging (UZEROWPUP-102), afegeix les regles **abans** del `DROP` final de `DOCKER-USER`, amb un altre fitxer que s'executi després d'aquest.

## sshd

`/etc/ssh/sshd_config.d/00-wpup.conf` (el prefix `00-` és important: sshd es queda amb el primer valor que llegeix):

- només claus (`PasswordAuthentication no`, `KbdInteractiveAuthentication no`, `AuthenticationMethods publickey`);
- `PermitRootLogin no`;
- `AllowGroups wpup-operators wpup-admins`;
- sense reenviament (`DisableForwarding yes`), `MaxAuthTries 3`, `LoginGraceTime 20`, `LogLevel VERBOSE`.

sshd escolta a totes les adreces per no dependre de l'ordre d'arrencada amb Tailscale. El tallafocs és qui deixa passar el 22 només per `tailscale0`.

Per afegir o treure persones: alta o baixa al grup de la tailnet i `usermod -aG wpup-operators <usuari>` o `userdel -r <usuari>` (i la baixa a `users` amb `wpup user:deactivate`).

## Tailscale Serve i Funnel

El pas `43` fa `tailscale serve reset` i `tailscale serve --bg --https=443 http://127.0.0.1:8080`. Una sola assignació serveix el panell i `/horizon` (és la mateixa aplicació). El port 8080 és el que escolta nginx **només a `127.0.0.1`** (UZEROWPUP-28).

**Funnel mai.** `network.sh` ho comprova (`tailscale serve status --json` i `tailscale funnel status`). Si falla: `tailscale funnel reset` i revisa qui ho ha activat.

## Sortida per la ruta per defecte, sense *exit node*

- `tailscale up ... --exit-node= --advertise-exit-node=false --accept-routes=false`: el node ni utilitza ni ofereix *exit node*.
- `network.sh` comprova que `ip route get 1.1.1.1` no surt per `tailscale0`, que `tailscale debug prefs` no té cap `ExitNodeID` i que l'IP pública real coincideix amb `WPUP_EGRESS_IP`.
- Si l'IP de sortida canvia, **abans** de res cal tornar a instal·lar les claus SSH amb el `from=` nou (`wpup ssh-key:rotate`).

## Comprovacions

### Dins de la VM

```bash
sudo ./deploy/checks/network.sh
```

Imprimeix una línia `OK`/`FAIL`/`SKIP` per comprovació i surt amb codi 1 si en falla alguna:

- Tailscale en marxa i amb l'etiqueta `tag:wpup-server`;
- Funnel apagat;
- cap *exit node*, i ruta per defecte que no passa per `tailscale0`;
- nftables amb `policy drop` i només 22/443 a `tailscale0`;
- sshd efectiu (claus, sense root, `AllowGroups`);
- cap socket escoltant a `0.0.0.0` o `::` fora dels permesos (`sshd` 22/tcp, `tailscaled` 41641/udp i el client DHCP; es canvia amb `WPUP_ALLOWED_WILDCARD`);
- cap contenidor publicant a `0.0.0.0` o `::`, i la cadena `DOCKER-USER` amb el `DROP`;
- l'IP real de sortida coincideix amb `WPUP_EGRESS_IP` (llegida del `.env` o de la variable d'entorn). El servei d'eco es canvia amb `WPUP_EGRESS_ECHO_URL` (per defecte `https://api.ipify.org`).

### Des de fora de la VM: el 22 no respon per la LAN ni per Internet

`deploy/checks/reachability.sh` prova els ports des d'una altra màquina. Cal fer-ho des de **tres llocs**:

1. **Des de la tailnet** (portàtil d'un membre de `group:wpup-operators`, amb Tailscale connectat): ha de respondre.

   ```bash
   ./deploy/checks/reachability.sh --open <node>.<tailnet>.ts.net
   ```

2. **Des de la LAN de Proxmox** (una altra màquina a la mateixa xarxa, **sense** passar per Tailscale; si la màquina té Tailscale, atura'l o prova l'adreça de la LAN, que no hauria de passar pel túnel): no ha de respondre.

   ```bash
   ./deploy/checks/reachability.sh --closed <IP_LAN_DE_LA_VM>
   ```

3. **Des d'Internet** (una màquina fora de l'oficina, com ara un mòbil amb dades o un servidor extern): no ha de respondre a l'IP pública de sortida de l'oficina (ni al 22 ni al 443).

   ```bash
   ./deploy/checks/reachability.sh --closed <IP_PUBLICA_DE_SORTIDA>
   ```

   (Que no respongui és el més probable encara que no hi hagués tallafocs, perquè l'oficina fa NAT; per això la prova de la LAN és la que compta.)

Es poden afegir ports amb `WPUP_TEST_PORTS="22 443 5432 6379 8080"` per comprovar que no n'hi ha cap més d'obert. Surt amb codi 1 si alguna prova no es compleix. Es fa en acabar el desplegament i després de qualsevol canvi al tallafocs.

## Accés d'emergència: la consola de Proxmox

Si Tailscale cau (el servei, el compte, la clau del node) o el tallafocs bloqueja l'accés, la sortida és **la consola de la VM al Proxmox** (`qm terminal <vmid>` o la consola noVNC). Cal un usuari amb contrasenya local o sudo per fer-hi login; defineix-ne un d'emergència a la custòdia de l'equip (la contrasenya de root no hauria de servir a SSH, però sí a la consola).

Un cop dins:

```bash
systemctl status tailscaled          # està en marxa?
tailscale status                     # és a la tailnet?
journalctl -u tailscaled -n 100
tailscale up --ssh=false --advertise-tags=tag:wpup-server   # reconnecta (amb clau nova si cal)
nft list ruleset | less              # què s'aplica ara mateix
```

Si el problema és el tallafocs i cal obrir temporalment:

```bash
nft delete table inet wpup           # obre tota l'entrada: només per diagnosticar!
# ...corregeix...
nft -f /etc/nftables.d/wpup.nft      # torna a posar-lo
```

Mentre Tailscale és caigut, les execucions programades continuen (la sortida no hi depèn, ADR-0003), però ningú no pot veure el panell ni entrar per SSH.

## Problemes habituals

| Símptoma | Causa | Què fer |
| --- | --- | --- |
| `network.sh`: «Funnel is ON» | algú ha executat `tailscale funnel` | `tailscale funnel reset` i pas `43` |
| `network.sh`: socket a `0.0.0.0` | servei nou (Redis, PostgreSQL, un contenidor) | Lligar-lo a `127.0.0.1` o al socket Unix |
| `network.sh`: l'IP de sortida no coincideix | canvi a la xarxa de Proxmox | Actualitzar `WPUP_EGRESS_IP` i rotar les claus SSH |
| El pas `42` es nega a aplicar sshd | cap operador amb `authorized_keys` | Crear l'operador abans |
| Després de reiniciar, els contenidors són accessibles | `wpup-docker-user.service` no ha corregut | `systemctl status wpup-docker-user`; `systemctl restart wpup-docker-user` |
