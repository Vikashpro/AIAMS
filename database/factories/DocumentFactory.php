<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        $title = ucfirst($this->faker->words(4, true));

        return [
            'title' => $title,
            'original_filename' => Str::slug($title) . '.pdf',
            'file_path' => 'documents/' . Str::random(12) . '.pdf',
            'status' => Document::STATUS_MANUAL,
            'fiscal_year' => (string) $this->faker->numberBetween(2015, 2024),
            'metadata' => [
                'tags' => $this->faker->words(3),
            ],
            'document_text' => $this->faker->paragraphs(3, true),
            'summary' => $this->faker->sentences(2, true),
            'department_id' => Department::query()->inRandomOrder()->value('id') ?? Department::factory(),
            'user_id' => User::query()->inRandomOrder()->value('id') ?? User::factory(),
        ];
    }
}
