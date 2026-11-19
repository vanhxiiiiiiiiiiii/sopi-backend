<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShippingSchemaUpgradeMysqlTest extends TestCase
{
    private bool $prepared = false;
    private const CONNECTION = 'shipping_upgrade_test';
    private const PATHS = [
        'database/migrations/2026_11_19_000002_add_shipping_rates_and_coordinates.php',
        'database/migrations/2026_11_19_000003_support_two_level_addresses.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_MYSQL_CHECKOUT_TESTS') !== '1' || DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the local MySQL test environment.');
        }

        // DDL implicitly commits on MySQL. Use unique prefixed tables, never the
        // application tables, to exercise a real upgrade without touching data.
        $connection = config('database.connections.' . DB::getDefaultConnection());
        $connection['prefix'] = 'shipping_upgrade_' . strtolower(Str::random(10)) . '_';
        config(['database.connections.' . self::CONNECTION => $connection]);
        $this->prepared = true;
        $schema = Schema::connection(self::CONNECTION);
        $schema->create('addresses', function (Blueprint $table) {
            $table->id();
            $table->string('city', 50);
        });
        $schema->create('orders', fn (Blueprint $table) => $table->id());
        DB::connection(self::CONNECTION)->table('addresses')->insert(['id' => 1, 'city' => 'Historical district']);
    }

    protected function tearDown(): void
    {
        if ($this->prepared) {
            $schema = Schema::connection(self::CONNECTION);
            foreach (['shipping_rate_settings', 'orders', 'addresses', 'migrations'] as $table) {
                $schema->dropIfExists($table);
            }
            DB::purge(self::CONNECTION);
        }
        parent::tearDown();
    }

    public function test_render_startup_upgrades_an_imported_schema_and_keeps_existing_data(): void
    {
        $this->upgrade();
        $db = DB::connection(self::CONNECTION);
        $schema = Schema::connection(self::CONNECTION);
        $this->assertTrue($schema->hasColumns('addresses', ['latitude', 'longitude']));
        $this->assertTrue($schema->hasColumn('orders', 'shipping_snapshot'));
        $this->assertSame('Historical district', $db->table('addresses')->value('city'));
        $this->assertSame(1, $db->table('shipping_rate_settings')->count());
        $this->assertSame('Kho phân loại PTIT Hà Đông', $db->table('shipping_rate_settings')->value('hub_name'));
        $this->assertSame(2, $db->table('migrations')->count());
        $db->table('addresses')->insert(['id' => 2, 'city' => null]);
        $db->table('shipping_rate_settings')->where('id', 1)->update(['base_fee' => 7.25, 'version' => 9]);

        // The next container start must neither reset rates nor repeat migrations.
        $this->upgrade();
        $this->assertSame(7.25, (float) $db->table('shipping_rate_settings')->value('base_fee'));
        $this->assertSame(9, (int) $db->table('shipping_rate_settings')->value('version'));
        $this->assertSame(2, $db->table('addresses')->count());
        $this->assertSame(2, $db->table('migrations')->count());
    }

    public function test_partial_import_can_complete_migration_without_overwriting_rates_or_coordinates(): void
    {
        $this->upgrade();
        $db = DB::connection(self::CONNECTION);
        $schema = Schema::connection(self::CONNECTION);
        $db->table('shipping_rate_settings')->where('id', 1)->update(['base_fee' => 7.25, 'version' => 9]);
        $db->table('addresses')->where('id', 1)->update(['latitude' => 20.99]);
        $schema->table('addresses', fn (Blueprint $table) => $table->dropColumn('longitude'));
        // An SQL import can contain only part of the schema, without history.
        $schema->drop('migrations');

        $this->upgrade();
        $this->assertTrue($schema->hasColumn('addresses', 'longitude'));
        $this->assertSame(20.99, (float) $db->table('addresses')->value('latitude'));
        $this->assertSame(7.25, (float) $db->table('shipping_rate_settings')->value('base_fee'));
        $this->assertSame(9, (int) $db->table('shipping_rate_settings')->value('version'));
        $this->assertSame(1, $db->table('shipping_rate_settings')->count());
        $this->assertSame(2, $db->table('migrations')->count());
    }

    private function upgrade(): void
    {
        $status = Artisan::call('migrate', [
            '--database' => self::CONNECTION,
            '--path' => self::PATHS,
            '--force' => true,
            '--no-interaction' => true,
        ]);
        $this->assertSame(0, $status, Artisan::output());
    }
}
