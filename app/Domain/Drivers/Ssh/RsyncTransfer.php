<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Ssh;

use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Secrets\Secret;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * rsync over the system ssh client (ADR-0002). Each transfer starts its own
 * ssh-agent with the socket in a private 0700 directory, loads the key from
 * stdin with a TTL and kills the agent afterwards. No key file is ever written;
 * only the pinned (public) host key goes to a known_hosts file in that directory.
 */
final readonly class RsyncTransfer
{
    public function __construct(
        private SshTarget $target,
        private Secret $key,
        private int $timeoutSeconds,
    ) {}

    public function download(string $remotePath, string $localPath): void
    {
        $this->rsync($this->remote($remotePath), $localPath);
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $this->rsync($localPath, $this->remote($remotePath));
    }

    private function rsync(string $from, string $to): void
    {
        $dir = sys_get_temp_dir().'/wpup-ssh-'.bin2hex(random_bytes(8));
        if (! mkdir($dir, 0700)) {
            throw new DriverException("No s'ha pogut crear el directori privat de l'agent SSH.");
        }

        $socket = $dir.'/agent.sock';
        $agent = new Process(['ssh-agent', '-D', '-a', $socket]);

        try {
            $agent->start();
            $this->waitForSocket($socket, $agent);

            $add = new Process(['ssh-add', '-q', '-t', (string) ($this->timeoutSeconds + 60), '-'], null, ['SSH_AUTH_SOCK' => $socket], $this->key->reveal()."\n", 30);
            if ($add->run() !== 0) {
                throw new DriverException("No s'ha pogut carregar la clau a l'agent SSH efímer.");
            }

            file_put_contents($dir.'/known_hosts', $this->target->knownHostsLine()."\n");
            $ssh = implode(' ', [
                'ssh', '-F', '/dev/null', '-p', (string) $this->target->port,
                '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
                '-o', 'UserKnownHostsFile='.$dir.'/known_hosts', '-o', 'GlobalKnownHostsFile=/dev/null',
                '-o', 'ConnectTimeout=15',
            ]);

            $rsync = new Process(['rsync', '--protect-args', '-e', $ssh, $from, $to], null, ['SSH_AUTH_SOCK' => $socket], null, $this->timeoutSeconds);
            if ($rsync->run() !== 0) {
                $reason = trim(mb_substr($rsync->getErrorOutput(), 0, 500));

                throw new RemoteCommandFailed("La transferència rsync ha fallat (codi {$rsync->getExitCode()}): {$reason}");
            }
        } catch (ProcessTimedOutException) {
            throw new RemoteCommandFailed("La transferència ha superat el temps màxim ({$this->timeoutSeconds} s).");
        } finally {
            $agent->stop(1);
            File::deleteDirectory($dir);
        }
    }

    private function waitForSocket(string $socket, Process $agent): void
    {
        for ($i = 0; $i < 100 && ! file_exists($socket); $i++) {
            if (! $agent->isRunning()) {
                break;
            }
            usleep(20_000);
        }

        if (! file_exists($socket)) {
            throw new DriverException("No s'ha pogut arrencar l'agent SSH efímer.");
        }
    }

    private function remote(string $path): string
    {
        return "{$this->target->user}@{$this->target->host}:{$path}";
    }
}
