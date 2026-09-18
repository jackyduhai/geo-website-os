<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Banner;
use App\Models\Media;
use App\Models\PageBlock;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SiteIdPopulatedTablesTest extends TestCase
{
    use RefreshDatabase;

    private array $tables = ['page_blocks', 'media', 'banners', 'audit_logs'];

    /** @test */
    public function all_four_tables_have_site_id_column(): void
    {
        foreach ($this->tables as $table) {
            $cols = DB::select("PRAGMA table_info($table)");
            $siteCol = collect($cols)->firstWhere('name', 'site_id');
            $this->assertNotNull($siteCol, "$table missing site_id column");
        }
    }

    /** @test */
    public function site_id_is_not_null_on_all_four_tables(): void
    {
        foreach ($this->tables as $table) {
            $cols = DB::select("PRAGMA table_info($table)");
            $siteCol = collect($cols)->firstWhere('name', 'site_id');
            $this->assertEquals(1, $siteCol->notnull, "$table site_id should be NOT NULL");
        }
    }

    /** @test */
    public function foreign_key_site_id_to_sites_exists_on_all_tables(): void
    {
        foreach ($this->tables as $table) {
            $fks = DB::select("PRAGMA foreign_key_list($table)");
            $siteFk = collect($fks)->firstWhere('from', 'site_id');
            $this->assertNotNull($siteFk, "$table missing FK on site_id");
            $this->assertEquals('sites', $siteFk->table);
            $this->assertEquals('id', $siteFk->to);
        }
    }

    /** @test */
    public function seeded_data_belongs_to_default_site(): void
    {
        // PageBlock is created by StructureSeeder (16 rows)
        $this->assertEquals(16, PageBlock::count());
        $this->assertEquals(0, PageBlock::whereNull('site_id')->count());
        $this->assertEquals(16, PageBlock::withoutSiteScope()->where('site_id', 1)->count());

        // Media/Banner are not seeded (created via admin UI in real DB)
        // Verify they can be created with site_id and default to site 1
        $media = Media::create(['disk' => 'public', 'path' => 'test.jpg', 'mime' => 'image/jpeg', 'size' => 100]);
        $this->assertEquals(1, $media->site_id);

        $banner = Banner::create(['position' => 'home', 'sort' => 0, 'is_active' => true, 'target' => 0]);
        $this->assertEquals(1, $banner->site_id);
    }

    /** @test */
    public function invalid_site_id_is_rejected_by_fk(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('page_blocks')->insert([
            'site_id'   => 999999,
            'page'      => 'test',
            'type'      => 'hero',
            'limit'     => 0,
            'sort'      => 0,
            'is_active' => 1,
        ]);
    }

    /** @test */
    public function site_a_can_create_page_block(): void
    {
        $siteA = Site::create(['name' => 'Site A', 'slug' => 'site-a', 'status' => 'active']);
        $block = PageBlock::create([
            'site_id'   => $siteA->id,
            'page'      => 'home',
            'type'      => 'hero',
            'title'     => 'Test Block',
            'limit'     => 0,
            'sort'      => 0,
            'is_active' => true,
        ]);
        $this->assertEquals($siteA->id, $block->site_id);
        $this->assertEquals(1, PageBlock::withoutSiteScope()->where('site_id', $siteA->id)->count());
    }

    /** @test */
    public function site_b_can_create_media(): void
    {
        $siteB = Site::create(['name' => 'Site B', 'slug' => 'site-b', 'status' => 'active']);
        $media = Media::create([
            'site_id' => $siteB->id,
            'disk'    => 'public',
            'path'    => 'test.jpg',
            'mime'    => 'image/jpeg',
            'size'    => 1024,
        ]);
        $this->assertEquals($siteB->id, $media->site_id);
    }

    /** @test */
    public function data_is_isolated_by_site_id(): void
    {
        $siteA = Site::create(['name' => 'Site A', 'slug' => 'site-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'Site B', 'slug' => 'site-b', 'status' => 'active']);

        Banner::create(['site_id' => $siteA->id, 'position' => 'home', 'sort' => 0, 'is_active' => true, 'target' => 0]);
        Banner::create(['site_id' => $siteB->id, 'position' => 'home', 'sort' => 0, 'is_active' => true, 'target' => 0]);

        $this->assertEquals(1, Banner::withoutSiteScope()->where('site_id', $siteA->id)->count());
        $this->assertEquals(1, Banner::withoutSiteScope()->where('site_id', $siteB->id)->count());
    }

    /** @test */
    public function model_site_relation_works(): void
    {
        // PageBlock has seeded data
        $block = PageBlock::first();
        $this->assertNotNull($block->site);
        $this->assertEquals(1, $block->site->id);

        // Create test Media/Banner since they are not seeded
        $media = Media::create(['disk' => 'public', 'path' => 'rel.jpg', 'mime' => 'image/jpeg', 'size' => 200]);
        $this->assertNotNull($media->site);
        $this->assertEquals(1, $media->site->id);

        $banner = Banner::create(['position' => 'top', 'sort' => 0, 'is_active' => true, 'target' => 0]);
        $this->assertNotNull($banner->site);
        $this->assertEquals(1, $banner->site->id);
    }

    /** @test */
    public function creating_event_auto_fills_site_id(): void
    {
        $block = PageBlock::create([
            'page'      => 'test-auto',
            'type'      => 'hero',
            'limit'     => 0,
            'sort'      => 0,
            'is_active' => true,
        ]);
        $this->assertEquals(Site::defaultId(), $block->site_id);

        $log = AuditLog::create([
            'action'  => 'test',
            'summary' => 'test',
        ]);
        $this->assertEquals(Site::defaultId(), $log->site_id);
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
        $pageBlockIdx = DB::select("PRAGMA index_list(page_blocks)");
        $names = array_map(fn($i) => $i->name, $pageBlockIdx);
        $this->assertContains('page_blocks_page_is_active_sort_index', $names);
        $this->assertContains('page_blocks_site_id_index', $names);

        $mediaIdx = DB::select("PRAGMA index_list(media)");
        $names = array_map(fn($i) => $i->name, $mediaIdx);
        $this->assertContains('media_mime_index', $names);
        $this->assertContains('media_site_id_index', $names);

        $bannerIdx = DB::select("PRAGMA index_list(banners)");
        $names = array_map(fn($i) => $i->name, $bannerIdx);
        $this->assertContains('banners_position_is_active_sort_index', $names);
        $this->assertContains('banners_site_id_index', $names);

        $auditIdx = DB::select("PRAGMA index_list(audit_logs)");
        $names = array_map(fn($i) => $i->name, $auditIdx);
        $this->assertContains('audit_logs_created_at_index', $names);
        $this->assertContains('audit_logs_target_type_target_id_index', $names);
        $this->assertContains('audit_logs_site_id_index', $names);
    }

    /** @test */
    public function migration_preserves_existing_data(): void
    {
        // PageBlock is seeded by StructureSeeder - verify all 16 rows survived rebuild
        $this->assertEquals(16, PageBlock::count());
        $this->assertEquals(0, PageBlock::whereNull('site_id')->count());

        // Verify data integrity: spot-check a known page block
        $hero = PageBlock::withoutSiteScope()->where('page', 'home')->where('type', 'hero')->first();
        $this->assertNotNull($hero);
        $this->assertEquals(1, $hero->site_id);
    }
}
