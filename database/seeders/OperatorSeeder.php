<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class OperatorSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'operator@tapehouse.dev'],
            [
                'name' => 'Operator',
                'password' => Hash::make('tapehouse'),
                'email_verified_at' => now(),
            ],
        );
    }
}
