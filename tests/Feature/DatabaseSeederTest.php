<?php

namespace Tests\Feature;

use App\Models\ShopItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_adds_catalog_but_does_not_create_staff_accounts(): void
    {
        (new DatabaseSeeder())->run();

        $this->assertSame(0, User::count());
        $this->assertGreaterThan(0, ShopItem::count());
    }
}
