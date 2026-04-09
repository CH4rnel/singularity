<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Category::factory()->create(['name' => 'Website']);
        Category::factory()->create(['name' => 'Bittorrent']);
        Category::factory()->create(['name' => 'Proxy services']);
    }
}
