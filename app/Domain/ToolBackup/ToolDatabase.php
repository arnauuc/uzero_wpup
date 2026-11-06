<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

use Illuminate\Database\Connection;
use SensitiveParameter;

/**
 * How pg_dump reaches the tool database: the application's own connection
 * settings (ADR-0004: a Unix socket directory and peer authentication in
 * production, so no password; a host in development). A password, if any,
 * only ever travels in PGPASSWORD, never on the command line.
 */
final readonly class ToolDatabase
{
    public function __construct(
        public string $database,
        public ?string $host,
        public ?string $port,
        public ?string $username,
        #[SensitiveParameter] public ?string $password,
        public ?string $sslmode,
    ) {}

    public static function fromConnection(Connection $connection): self
    {
        $value = static function (string $key) use ($connection): ?string {
            $v = $connection->getConfig($key);

            return is_scalar($v) && (string) $v !== '' ? (string) $v : null;
        };

        return new self(
            (string) $connection->getDatabaseName(),
            $value('host'),
            $value('port'),
            $value('username'),
            $value('password'),
            $value('sslmode'),
        );
    }

    /**
     * pg_dump arguments that select the database (no secrets).
     *
     * @return list<string>
     */
    public function arguments(): array
    {
        $args = [];
        if ($this->host !== null) {
            $args[] = '--host='.$this->host;
        }
        if ($this->port !== null) {
            $args[] = '--port='.$this->port;
        }
        if ($this->username !== null) {
            $args[] = '--username='.$this->username;
        }
        $args[] = '--dbname='.$this->database;

        return $args;
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        $env = ['PGAPPNAME' => 'wpup-tool-backup'];
        if ($this->password !== null) {
            $env['PGPASSWORD'] = $this->password;
        }
        if ($this->sslmode !== null) {
            $env['PGSSLMODE'] = $this->sslmode;
        }

        return $env;
    }
}
