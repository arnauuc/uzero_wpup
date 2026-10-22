-- Roles and database of the tool (ADR-0004). Idempotent: run as the postgres
-- superuser by deploy/steps/50-postgres.sh. Mirrors docker/postgres/init for
-- development. The REVOKE and the trigger on audit_log are created by the
-- application migrations (run as wpup_owner), not here.

SELECT 'CREATE ROLE wpup_owner LOGIN'
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'wpup_owner')\gexec

SELECT 'CREATE ROLE wpup_app LOGIN'
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'wpup_app')\gexec

SELECT 'CREATE DATABASE wpup OWNER wpup_owner'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'wpup')\gexec

REVOKE ALL ON DATABASE wpup FROM PUBLIC;
GRANT CONNECT ON DATABASE wpup TO wpup_owner, wpup_app;

\connect wpup

ALTER SCHEMA public OWNER TO wpup_owner;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO wpup_app;

ALTER DEFAULT PRIVILEGES FOR ROLE wpup_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO wpup_app;
ALTER DEFAULT PRIVILEGES FOR ROLE wpup_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO wpup_app;
