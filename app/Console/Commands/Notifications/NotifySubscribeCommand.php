<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Console\ChangesState;
use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Notifications\NotificationAdministration;
use App\Enums\NotificationThreshold;
use DomainException;

/**
 * An operator follows a notification destination from a minimum severity on,
 * or stops following it (UZEROWPUP-79). Each operator manages their own;
 * an admin may do it for someone else with --user (NotificationAdministration).
 */
final class NotifySubscribeCommand extends WpupCommand implements ChangesState
{
    protected $signature = 'notify:subscribe
        {destination : Nom de la destinació}
        {--min=all : Severitat mínima: all (tot), warnings (avisos i errors) o errors}
        {--user= : Usuari Unix de l\'operador (només admin; per defecte, tu)}
        {--remove : Deixa de seguir la destinació}
        '.self::DRY_RUN;

    protected $description = 'Segueix (o deixa de seguir) una destinació d\'avisos, amb la severitat mínima';

    protected function ability(): Ability
    {
        return Ability::ViewReports;
    }

    protected function perform(): int
    {
        $admin = app(NotificationAdministration::class);
        $min = NotificationThreshold::tryFrom((string) $this->stringOption('min'));
        if ($min === null) {
            $this->error('La severitat mínima ha de ser all, warnings o errors.');

            return self::FAILURE;
        }

        try {
            $user = $admin->subscriber($this->actor(), $this->operator, $this->stringOption('user'));
            $destination = $admin->destination($this->stringArgument('destination'));
            $remove = $this->option('remove') === true;

            if ($this->isDryRun()) {
                $this->info($remove
                    ? "[dry-run] «{$user->unix_username}» deixaria de seguir «{$destination->name}»."
                    : "[dry-run] «{$user->unix_username}» seguiria «{$destination->name}» ({$min->label()}).");

                return self::SUCCESS;
            }
            if ($remove) {
                $this->info($admin->unsubscribe($this->actor(), $user, $destination->name)
                    ? "«{$user->unix_username}» ja no segueix «{$destination->name}»."
                    : "«{$user->unix_username}» no seguia «{$destination->name}».");

                return self::SUCCESS;
            }
            $admin->subscribe($this->actor(), $user, $destination->name, $min);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("«{$user->unix_username}» segueix «{$destination->name}» ({$min->label()}).");

        return self::SUCCESS;
    }
}
