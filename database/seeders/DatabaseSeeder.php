<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Document;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $departments = collect([
            'Education Department',
            'Health Department',
            'Planning & Development',
        ])->map(function (string $name) {
            return Department::firstOrCreate([
                'slug' => Str::slug($name),
            ], [
                'name' => $name,
            ]);
        });

        $education = $departments->firstWhere('slug', 'education-department');
        $health = $departments->firstWhere('slug', 'health-department');

        $admin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'AI-AMS Admin',
                'role' => User::ROLE_ADMIN,
                'department_id' => null,
                'password' => Hash::make('password'),
            ]
        );

        $auditor = User::updateOrCreate(
            ['email' => 'auditor@example.com'],
            [
                'name' => 'Education Auditor',
                'role' => User::ROLE_AUDITOR,
                'department_id' => optional($education)->id,
                'password' => Hash::make('password'),
            ]
        );

        $officer = User::updateOrCreate(
            ['email' => 'officer@example.com'],
            [
                'name' => 'Health Officer',
                'role' => User::ROLE_OFFICER,
                'department_id' => optional($health)->id,
                'password' => Hash::make('password'),
            ]
        );

        collect([$admin, $auditor, $officer])->each(function (User $user): void {
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
        });

        Listing::factory(5)->create([
            'by_user_id' => $admin->id,
        ]);

        Listing::factory(5)->create([
            'by_user_id' => $auditor->id,
        ]);

        Document::factory()->count(3)->create([
            'department_id' => optional($education)->id,
            'user_id' => $admin->id,
            'metadata' => [
                'tags' => ['budget', 'audit'],
            ],
        ]);

        Document::factory()->count(2)->create([
            'department_id' => optional($health)->id,
            'user_id' => $officer->id,
            'metadata' => [
                'tags' => ['health', 'compliance'],
            ],
        ]);
    }
}
