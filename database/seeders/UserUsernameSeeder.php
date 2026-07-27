<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\UsernameGenerator;
use Illuminate\Database\Seeder;

class UserUsernameSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::chunkById(100, function ($users) {
            foreach ($users as $user) {
                $user->update([
                    'username' => UsernameGenerator::generate($user->name, $user->id),
                ]);
            }
        });
    }
}
