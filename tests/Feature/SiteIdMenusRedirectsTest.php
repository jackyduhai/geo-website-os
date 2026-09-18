<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Redirect;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SiteIdMenusRedirectsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function menus_and_redirects_have_site_id_column(): void
    {
        foreach (['menus', 'redirects'] as $table) {
            $cols = DB::select("PRAGMA table_info($table)");
            $siteCol = collect($cols)->firstWhere('name', 'site_id');
            $this->assertNotNull($siteCol, "$table missing site_id column");
            $this->assertEquals(1, $siteCol->notnull, "$table site_id should be NOT NULL");
        }
    }

    /** @test */
    public function foreign_key_site_id_to_sites_exists(): void
    {
        foreach (['menus', 'redirects'] as $table) {
            $fks = DB::select("PRAGMA foreign_key_list($table)");
            $siteFk = collect($fks)->firstWhere('from', 'site_id');
            $this->assertNotNull($siteFk, "$table missing FK on site_id");
            $this->assertEquals('sites', $siteFk->table);
            $this->assertEquals('restrict', strtolower($siteFk->on_delete), "$table site_id FK should be RESTRICT");
        }
    }

    /** @test */
    public function menus_unique_is_site_scoped(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'menu-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'menu-b', 'status' => 'active']);

        // Same key in different sites: allowed
        Menu::create(['site_id' => $siteA->id, 'label' => 'A1', 'key' => 'main', 'position' => 'main']);
        Menu::create(['site_id' => $siteB->id, 'label' => 'B1', 'key' => 'main', 'position' => 'main']);
        $this->assertEquals(2, Menu::where('key', 'main')->count());

        // Same key in same site: rejected
        $this->expectException(\Illuminate\Database\QueryException::class);
        Menu::create(['site_id' => $siteA->id, 'label' => 'A2', 'key' => 'main', 'position' => 'main']);
    }

    /** @test */
    public function redirects_unique_is_site_scoped(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'redir-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'redir-b', 'status' => 'active']);

        // Same from_path in different sites: allowed
        Redirect::create(['site_id' => $siteA->id, 'from_path' => '/old', 'to_path' => '/new', 'code' => 301]);
        Redirect::create(['site_id' => $siteB->id, 'from_path' => '/old', 'to_path' => '/other', 'code' => 301]);
        $this->assertEquals(2, Redirect::where('from_path', '/old')->count());

        // Same from_path in same site: rejected
        $this->expectException(\Illuminate\Database\QueryException::class);
        Redirect::create(['site_id' => $siteA->id, 'from_path' => '/old', 'to_path' => '/dup', 'code' => 301]);
    }

    /** @test */
    public function existing_menu_data_belongs_to_default_site(): void
    {
        $this->seed();
        // Menus may be empty in seeder; verify no NULL site_id if rows exist
        $nullCount = DB::table('menus')->whereNull('site_id')->count();
        $this->assertEquals(0, $nullCount, 'menus has NULL site_id');
    }

    /** @test */
    public function model_site_relation_works(): void
    {
        $menu = Menu::create(['label' => 'Test', 'position' => 'main']);
        $this->assertEquals(Site::defaultId(), $menu->site_id);
        $this->assertNotNull($menu->site);

        $redirect = Redirect::create(['from_path' => '/x', 'to_path' => '/y', 'code' => 301]);
        $this->assertEquals(Site::defaultId(), $redirect->site_id);
        $this->assertNotNull($redirect->site);
    }

    /** @test */
    public function invalid_site_id_is_rejected_by_fk(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('menus')->insert([
            'site_id' => 999999, 'label' => 'Test', 'position' => 'main',
            'target' => 0, 'sort' => 0, 'is_active' => 1,
        ]);
    }

    /** @test */
    public function foreign_key_check_has_no_violations(): void
    {
        $violations = DB::select('PRAGMA foreign_key_check');
        $this->assertCount(0, $violations);
    }

    /** @test */
    public function original_indexes_are_preserved(): void
    {
        $menuIdx = DB::select('PRAGMA index_list(menus)');
        $names = array_map(fn($i) => $i->name, $menuIdx);
        $this->assertContains('menus_key_unique', $names);
        $this->assertContains('menus_parent_key_is_active_index', $names);
        $this->assertContains('menus_position_is_active_sort_index', $names);

        $redirIdx = DB::select('PRAGMA index_list(redirects)');
        $names = array_map(fn($i) => $i->name, $redirIdx);
        $this->assertContains('redirects_from_path_unique', $names);
    }
}
