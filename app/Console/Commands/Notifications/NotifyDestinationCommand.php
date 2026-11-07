<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Console\Commands\Credentials\CredentialCommand;
use App\Domain\Notifications\NotificationAdministration;
use App\Domain\Secrets\Secret;
use DomainException;

/**
 * Creates or updates a destination of a notification channel (UZEROWPUP-79).
 * Admin and CLI only (ADR-0008). The token or webhook is typed without echo,
 * never given as an argument (history and `ps`), and goes to the SecretStore.
 */
final class NotifyDestinationCommand extends CredentialCommand
{
    protected $signature = 'notify:destination
        {name : Nom de la destinació}
        {--channel= : Canal (en crear-la)}
        {--address= : Adreça que no és secreta: correu, xat, canal…}
        {--activate : Torna a enviar-hi avisos}
        {--deactivate : Deixa d\'enviar-hi avisos}
        '.self::DRY_RUN;

    protected $description = 'Crea o actualitza una destinació d\'avisos (el token o webhook s\'escriu sense eco)';

    protected function perform(): int
    {
        $admin = app(NotificationAdministration::class);
        $name = trim($this->stringArgument('name'));
        $address = $this->stringOption('address');
        $active = $this->option('activate') === true ? true : ($this->option('deactivate') === true ? false : null);

        try {
            if ($this->option('activate') === true && $this->option('deactivate') === true) {
                throw new DomainException('--activate i --deactivate no es poden combinar.');
            }
            $existing = $admin->checkDestination($name, $this->stringOption('channel'), $address);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $channel = $existing->channel ?? (string) $this->stringOption('channel');
        $asksSecret = $admin->needsSecret($channel);

        if ($this->isDryRun()) {
            $this->info(($existing === null ? "[dry-run] Es crearia la destinació «{$name}» del canal {$channel}" : "[dry-run] S'actualitzaria la destinació «{$name}» ({$channel})")
                .($asksSecret ? '; es demanaria el token o webhook sense eco.' : '.'));

            return self::SUCCESS;
        }

        $secret = null;
        if ($asksSecret) {
            if (! $this->input->isInteractive()) {
                $this->error('Cal un terminal interactiu per escriure el token o webhook sense eco.');

                return self::FAILURE;
            }
            $typed = $this->secret($existing === null ? 'Token o webhook' : 'Token o webhook nou (buit: es manté l\'actual)');
            $secret = is_string($typed) && trim($typed) !== '' ? new Secret(trim($typed)) : null;
        }

        try {
            $destination = $admin->setDestination($this->actor(), $name, $channel, $address, $secret, $active);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $secret?->wipe();
        }

        $this->info(($existing === null ? "Destinació «{$destination->label()}» creada" : "Destinació «{$destination->label()}» actualitzada")
            .($secret !== null ? '; el token o webhook s\'ha desat xifrat.' : '.'));

        return self::SUCCESS;
    }
}
