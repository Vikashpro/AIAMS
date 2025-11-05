<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Document;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Seeder;
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

        $admin = User::factory()->create([
            'name' => 'AI-AMS Admin',
            'email' => 'admin@example.com',
            'role' => User::ROLE_ADMIN,
        ]);

        $auditor = User::factory()->create([
            'name' => 'Education Auditor',
            'email' => 'auditor@example.com',
            'role' => User::ROLE_AUDITOR,
            'department_id' => optional($education)->id,
        ]);

        $officer = User::factory()->create([
            'name' => 'Health Officer',
            'email' => 'officer@example.com',
            'role' => User::ROLE_OFFICER,
            'department_id' => optional($health)->id,
        ]);

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
