<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Group;
use App\Models\Participant;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $group = Group::factory()
            ->has(Category::factory()->count(3))
            ->has(Participant::factory()->count(6))
            ->create(['theme' => 'Pijamas']);

        $group->drawAssignments();
    }
}
