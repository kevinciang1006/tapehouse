<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AlertRule;
use App\Models\User;

class AlertRulePolicy
{
    public function view(User $user, AlertRule $alertRule): bool
    {
        return $user->id === $alertRule->user_id;
    }

    public function update(User $user, AlertRule $alertRule): bool
    {
        return $user->id === $alertRule->user_id;
    }

    public function delete(User $user, AlertRule $alertRule): bool
    {
        return $user->id === $alertRule->user_id;
    }
}
