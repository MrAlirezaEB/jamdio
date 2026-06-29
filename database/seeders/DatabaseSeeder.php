<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Station defaults to "on".
        Setting::set('station_status', Setting::get('station_status', 'on'));
    }
}
