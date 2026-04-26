<?php

namespace App\Core;

class Controller
{
    protected function userId(): ?int
    {
        return Auth::id();
    }

    protected function user(): ?array
    {
        return Auth::user();
    }

    protected function userRole(): ?string
    {
        return Auth::role();
    }

    protected function requireRole(string ...$roles): void
    {
        Auth::requireRole(...$roles);
    }
}
