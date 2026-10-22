<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Models\User;

/**
 * Maps the Unix caller to an active operator (ADR-0002). There are no shared
 * accounts: every action is attributed to a person.
 */
final readonly class OperatorResolver
{
    public function __construct(
        private string $mode,
        private string $serviceUser,
        private string $environment,
    ) {}

    public function resolve(ProcessIdentity $identity): User
    {
        $unixUser = match ($this->mode) {
            'sudo' => $this->viaSudo($identity),
            'posix' => $this->viaPosix($identity),
            default => throw IdentityException::unknownMode($this->mode),
        };

        $user = User::query()->where('unix_username', $unixUser)->first()
            ?? throw IdentityException::unknownUser($unixUser);

        if (! $user->active) {
            throw IdentityException::inactiveUser($unixUser);
        }

        return $user;
    }

    private function viaSudo(ProcessIdentity $identity): string
    {
        if ($identity->effectiveUser !== $this->serviceUser) {
            throw IdentityException::wrongServiceUser($identity->effectiveUser, $this->serviceUser);
        }

        return $identity->sudoUser ?? throw IdentityException::notThroughSudo($this->serviceUser);
    }

    private function viaPosix(ProcessIdentity $identity): string
    {
        if ($this->environment === 'production') {
            throw IdentityException::posixModeInProduction();
        }

        return $identity->effectiveUser;
    }
}
