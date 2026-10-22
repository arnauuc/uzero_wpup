# docker/

Entorn de desenvolupament (Docker Compose), llocs WordPress/WooCommerce de prova (fixtures) i imatges de staging.
El `compose.yaml` és a l'arrel del repositori; vegeu «Desenvolupament» al [README](../README.md).

| Carpeta | Contingut | Tasca |
| --- | --- | --- |
| `app/` | `Dockerfile` de l'orquestrador (serveis `app` i `horizon`) | UZEROWPUP-3 |
| `postgres/init/` | SQL d'inicialització: rols `wpup_owner` i `wpup_app`, BD `wpup` i `wpup_test` | UZEROWPUP-3 |
| `verifier/` | `Dockerfile` del verificador (imatge de Playwright) | UZEROWPUP-3 |
| `fixtures/` | Llocs WordPress/WooCommerce de prova (PHP 7.4, 8.1, 8.3), projecte Compose separat; vegeu [fixtures/README.md](fixtures/README.md) | UZEROWPUP-4 |
| imatges de staging | Staging | pendent |
