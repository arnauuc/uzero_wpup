<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Console\ChangesState;
use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Notifications\NotificationAdministration;
use App\Enums\NotificationThreshold;
use App\Models\User;
use DomainException;

/**
 * An operator follows a notification destination from a minimum severity on,
 * or stops following it (UZEROWPUP-79). Each operator manages their own;
 * an admin may do it for someone else with --user.
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
        $username = $this->stringOption('user');
        if ($username !== null && $username !== $this->operator->unix_username
            && ! $this->requireAbility(Ability::ManageUsers, ['user' => $username], 'Només un admin pot canviar els avisos d\'un altre operador.')) {
            return self::FAILURE;
        }
        $min = NotificationThreshold::tryFrom((string) $this->stringOption('min'));
        if ($min === null) {
            $this->error('La severitat mínima ha de ser all, warnings o errors.');

            return self::FAILURE;
        }

        try {
            $user = $username === null ? $this->operator : (User::query()->where('unix_username', $username)->first()
                ?? throw new DomainException("No hi ha cap operador «{$username}»."));
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
