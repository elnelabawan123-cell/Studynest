<?php

namespace App\Traits;

use App\Models\User;

/**
 * Shared username generator: lastname.SDA, lastname.SDA1, lastname.SDA2, ...
 *
 * Extracted so both the single-create flow (StudentAccountController)
 * and the new bulk-create flow (StudentBulkController) produce
 * usernames the exact same way, with no duplicated logic to drift.
 */
trait GeneratesStudentUsername
{
    protected function generateStudentUsername(string $lastName): string
    {
        $clean    = strtolower(preg_replace('/[^a-zA-Z]/', '', $lastName));
        $base     = $clean . '.SDA';
        $username = $base;
        $i = 1;
        while (User::where('username', $username)->exists()) {
            $username = $base . $i++;
        }
        return $username;
    }
}