<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    protected $model = File::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word().'.'.fake()->fileExtension(),
            'folder_id' => Folder::factory(),
            'owner_id' => User::factory(),
            'size_bytes' => fake()->numberBetween(1024, 10485760),
            'mime_type' => fake()->mimeType(),
            'r2_object_key' => Str::uuid()->toString().'/'.fake()->word(),
        ];
    }
}
