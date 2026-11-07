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
 * never given as an argument (history and `ps`), and goes to the SecretStore:
 * it is asked for a new destination of a channel that needs one, or with
 * --rotate-secret. Any other edit works without a terminal.
 */
final class NotifyDestinationCommand extends CredentialCommand
{
    protected $signature = 'notify:destination
        {name : Nom de la destinació}
        {--channel= : Canal (en crear-la)}
        {--address= : Adreça que no és secreta: correu, xat, canal…}
        {--activate : Torna a enviar-hi avisos}
        {--deactivate : Deixa d\'enviar-hi avisos}
        {--rotate-secret : Demana un token o webhook nou}
        '.self::DRY_RUN;

    protected $description = 'Crea o actualitza una destinació d\'avisos (el token o webhook s\'escriu sense eco)';

    protected function perform(): int
    {
        $admin = app(NotificationAdministration::class);
        if ($this->option('activate') === true && $this->option('deactivate') === true) {
            $this->error('--activate i --deactivate no es poden combinar.');

            return self::FAILURE;
        }
        $active = $this->option('activate') === true ? true : ($this->option('deactivate') === true ? false : null);

        try {
            $plan = $admin->plan(trim($this->stringArgument('name')), $this->stringOption('channel'), $this->stringOption('address'), $active, $this->option('rotate-secret') === true);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($plan->changesNothing()) {
            $this->info("Destinació «{$plan->name}»: no hi ha canvis.");

            return self::SUCCESS;
        }
        if ($this->isDryRun()) {
            $this->info(($plan->creates() ? "[dry-run] Es crearia la destinació «{$plan->name}» del canal {$plan->channel}" : "[dry-run] S'actualitzaria la destinació «{$plan->name}» ({$plan->channel})")
                .($plan->needsSecret ? '; es demanaria el token o webhook sense eco.' : '.'));

            return self::SUCCESS;
        }

        $secret = null;
        if ($plan->needsSecret) {
            if (! $this->input->isInteractive()) {
                $this->error('Cal un terminal interactiu per escriure el token o webhook sense eco.');

                return self::FAILURE;
            }
            $typed = $this->secret('Token o webhook');
            $secret = new Secret(is_string($typed) ? trim($typed) : '');
        }

        try {
            $destination = $admin->apply($this->actor(), $plan, $secret);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $secret?->wipe();
        }

        $this->info(($plan->creates() ? "Destinació «{$destination->label()}» creada" : "Destinació «{$destination->label()}» actualitzada")
            .($plan->needsSecret ? '; el token o webhook s\'ha desat xifrat.' : '.'));

        return self::SUCCESS;
    }
}
