<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Ssh;

use App\Domain\Drivers\Data\CommandResult;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Secrets\Secret;
use Illuminate\Support\Sleep;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * SSH commands through phpseclib, with the private key only in memory
 * (ADR-0002). The host key must be pinned and must match, or nothing is sent.
 */
final class SshConnection
{
    private const RETRY_PAUSES_SECONDS = [2, 6];

    private ?SSH2 $ssh = null;

    public function __construct(
        private readonly SshTarget $target,
        private readonly Secret $key,
        private readonly int $connectTimeoutSeconds,
    ) {}

    /**
     * Runs a shell command (already escaped by the caller). A non-zero exit code
     * is returned, not thrown.
     *
     * @throws ConnectionFailed
     * @throws RemoteCommandFailed when it times out
     */
    public function run(string $command, int $timeoutSeconds): CommandResult
    {
        $ssh = $this->session();
        $ssh->setTimeout($timeoutSeconds);
        $start = hrtime(true);

        try {
            $stdout = $ssh->exec($command);
        } catch (Throwable $e) {
            $this->close();

            throw new ConnectionFailed("S'ha perdut la connexió amb {$this->target->label()}: {$e->getMessage()}");
        }

        if ($ssh->isTimeout()) {
            // The remote process may still be running: dropping the session hangs it up.
            $this->close();

            throw new RemoteCommandFailed("La comanda remota ha superat el temps màxim ({$timeoutSeconds} s).");
        }

        $exit = $ssh->getExitStatus();
        $stderr = $ssh->getStdError();

        return new CommandResult(
            is_int($exit) ? $exit : 255,
            is_string($stdout) ? $stdout : '',
            is_string($stderr) ? $stderr : '',
            intdiv(hrtime(true) - $start, 1_000_000),
        );
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        $this->ssh?->disconnect();
        $this->ssh = null;
    }

    /**
     * The server's host key, to pin it on first contact (`site:test`, with the
     * admin's confirmation). Never used to skip the check.
     */
    public static function fetchHostKey(string $host, int $port, int $timeoutSeconds): string
    {
        $ssh = null;
        try {
            $ssh = new SSH2($host, $port, $timeoutSeconds);
            $key = $ssh->getServerPublicHostKey();
        } catch (Throwable $e) {
            throw new ConnectionFailed("No s'ha pogut connectar a {$host}:{$port}: {$e->getMessage()}");
        } finally {
            // Unauthenticated connections left open count against sshd's MaxStartups.
            $ssh?->disconnect();
        }

        return is_string($key) ? $key : throw new ConnectionFailed("El servidor {$host}:{$port} no ha signat correctament la seva host key.");
    }

    private function session(): SSH2
    {
        if ($this->ssh !== null && $this->ssh->isConnected() && $this->ssh->isAuthenticated()) {
            return $this->ssh;
        }

        $pinned = $this->target->pinnedHostKey
            ?? throw new ConnectionFailed("La host key de {$this->target->host} no està fixada: fixa-la amb site:test abans de connectar.");

        // A server may drop a new connection right away (OpenSSH >= 9.8 penalises
        // sources for a few seconds after a failed login): retry with a pause.
        $attempt = 0;
        while (true) {
            try {
                $ssh = new SSH2($this->target->host, $this->target->port, $this->connectTimeoutSeconds);
                $hostKey = $ssh->getServerPublicHostKey();

                break;
            } catch (Throwable $e) {
                if (++$attempt >= count(self::RETRY_PAUSES_SECONDS) + 1) {
                    throw new ConnectionFailed("No s'ha pogut connectar a {$this->target->label()}: {$e->getMessage()}");
                }
                Sleep::sleep(self::RETRY_PAUSES_SECONDS[$attempt - 1]);
            }
        }

        if (! is_string($hostKey) || SshTarget::normaliseHostKey($hostKey) !== SshTarget::normaliseHostKey($pinned)) {
            $ssh->disconnect();

            throw new ConnectionFailed("La host key de {$this->target->host} no coincideix amb la fixada. No es continua: pot ser un servidor reinstal·lat o un atac.");
        }

        try {
            $privateKey = PublicKeyLoader::loadPrivateKey($this->key->reveal());
        } catch (Throwable) {
            // The loader's message is not shown: it is about the key material.
            throw new ConnectionFailed("La clau privada del compte {$this->target->user} no és vàlida.");
        }

        if (! $ssh->login($this->target->user, $privateKey)) {
            $ssh->disconnect();

            throw new ConnectionFailed("El servidor ha rebutjat la clau de {$this->target->label()}.");
        }

        $ssh->enableQuietMode();

        return $this->ssh = $ssh;
    }
}
