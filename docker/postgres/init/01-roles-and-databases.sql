-- Development bootstrap (ADR-0004). Runs once, on first start of an empty data volume.
--
-- Dev auth is password-less (POSTGRES_HOST_AUTH_METHOD=trust in compose.yaml); the server
-- is reachable only from the compose network (its port is not published on the host).
-- In production PostgreSQL listens on a Unix socket only, with `peer` authentication and
-- the system user `wpup` mapped to these roles via pg_ident.conf.
--
-- wpup_owner: owns the schema, used only to run migrations.
-- wpup_app:   runtime role, DML only. (The audit_log REVOKE/trigger belongs to a later task.)

CREATE ROLE wpup_owner LOGIN;
CREATE ROLE wpup_app LOGIN;

CREATE DATABASE wpup OWNER wpup_owner;
CREATE DATABASE wpup_test OWNER wpup_owner;

\connect wpup
\ir 02-schema-privileges.inc

\connect wpup_test
\ir 02-schema-privileges.inc
