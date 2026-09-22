<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Content;
use App\Models\Fact;
use App\Models\Group;
use App\Models\Setting;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SiteIdCoreTablesTest extends TestCase
{
    use RefreshDatabase;

    private array $tables = ['contents', 'categories', 'groups', 'facts', 'settings'];

    /** @test */
    public function all_five_tables_have_site_id_column(): void
    {
        foreach ($this->tables as $table) {
            $cols = DB::select("PRAGMA table_info($table)");
            $siteCol = collect($cols)->firstWhere('name', 'site_id');
            $this->assertNotNull($siteCol, "$table missing site_id column");
        }
    }

    /** @test */
    public function site_id_is_not_null_on_all_five_tables(): void
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
    public function groups_preserves_category_id_foreign_key(): void
    {
        $fks = DB::select('PRAGMA foreign_key_list(groups)');
        $catFk = collect($fks)->firstWhere('from', 'category_id');
        $this->assertNotNull($catFk, 'groups missing FK on category_id');
        $this->assertEquals('categories', $catFk->table);
        $this->assertEquals('id', $catFk->to);
        $this->assertEquals('CASCADE', $catFk->on_delete);
    }

    /** @test */
    public function seeded_data_belongs_to_default_site(): void
    {
        $this->seed();
        // StructureSeeder creates categories, groups; FactSeeder creates facts; SettingSeeder creates settings
        // ContentSeeder creates 3 knowledge articles
        $this->assertGreaterThan(0, Category::count());
        $this->assertGreaterThan(0, Group::count());
        $this->assertGreaterThan(0, Fact::count());
        $this->assertGreaterThan(0, Setting::count());

        foreach ($this->tables as $table) {
            $nullCount = DB::table($table)->whereNull('site_id')->count();
            $site1Count = DB::table($table)->where('site_id', 1)->count();
            $total = DB::table($table)->count();
            $this->assertEquals(0, $nullCount, "$table has NULL site_id");
            $this->assertEquals($total, $site1Count, "$table not all site_id=1");
        }
    }

    /** @test */
    public function unique_constraints_are_site_scoped(): void
    {
        // contents: unique(site_id, slug)
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'site-b', 'status' => 'active']);

        Content::create([
            'site_id' => $siteA->id, 'type' => 'article', 'title' => 'Test',
            'slug' => 'shared-slug', 'status' => 'draft',
        ]);
        // Same slug in different site should be allowed
        Content::create([
            'site_id' => $siteB->id, 'type' => 'article', 'title' => 'Test B',
            'slug' => 'shared-slug', 'status' => 'draft',
        ]);
        $this->assertEquals(2, DB::table('contents')->where('slug', 'shared-slug')->count());

        // categories: unique(site_id, slug)
        Category::create(['site_id' => $siteA->id, 'name' => 'Cat A', 'slug' => 'cat-shared', 'type' => 'list']);
        Category::create(['site_id' => $siteB->id, 'name' => 'Cat B', 'slug' => 'cat-shared', 'type' => 'list']);
        $this->assertEquals(2, DB::table('categories')->where('slug', 'cat-shared')->count());

        // facts: unique(site_id, key)
        Fact::create(['site_id' => $siteA->id, 'key' => 'fact-shared', 'label' => 'F', 'value' => 'V']);
        Fact::create(['site_id' => $siteB->id, 'key' => 'fact-shared', 'label' => 'F', 'value' => 'V']);
        $this->assertEquals(2, DB::table('facts')->where('key', 'fact-shared')->count());

        // settings: unique(site_id, key)
        Setting::create(['site_id' => $siteA->id, 'key' => 'set-shared', 'value' => 'V', 'group' => 'general', 'type' => 'text']);
        Setting::create(['site_id' => $siteB->id, 'key' => 'set-shared', 'value' => 'V', 'group' => 'general', 'type' => 'text']);
        $this->assertEquals(2, DB::table('settings')->where('key', 'set-shared')->count());
    }

    /** @test */
    public function duplicate_slug_in_same_site_is_rejected(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);
        Content::create([
            'site_id' => $siteA->id, 'type' => 'article', 'title' => 'T1',
            'slug' => 'dup-slug', 'status' => 'draft',
        ]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        Content::create([
            'site_id' => $siteA->id, 'type' => 'article', 'title' => 'T2',
            'slug' => 'dup-slug', 'status' => 'draft',
        ]);
    }

    /** @test */
    public function groups_category_id_must_be_same_site(): void
    {
        // Create Site A with Category A
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);
        $catA = Category::create(['site_id' => $siteA->id, 'name' => 'Cat A', 'slug' => 'cat-a', 'type' => 'list']);

        // Create Site B with Category B
        $siteB = Site::create(['name' => 'B', 'slug' => 'site-b', 'status' => 'active']);
        $catB = Category::create(['site_id' => $siteB->id, 'name' => 'Cat B', 'slug' => 'cat-b', 'type' => 'list']);

        // Group in Site A referencing Category A: OK
        $groupA = Group::create([
            'site_id' => $siteA->id, 'category_id' => $catA->id,
            'name' => 'Group A', 'slug' => 'group-a',
        ]);
        $this->assertEquals($siteA->id, $groupA->site_id);

        // Group in Site B referencing Category A (cross-site): MUST BE REJECTED
        $groupCountBefore = Group::count();
        try {
            Group::create([
                'site_id' => $siteB->id, 'category_id' => $catA->id,
                'name' => 'Cross Site', 'slug' => 'cross-site',
            ]);
            $this->fail('Cross-site Group B -> Category A should have been rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cross-site violation', $e->getMessage());
        }

        // Reverse: Group in Site A referencing Category B (cross-site): MUST BE REJECTED
        try {
            Group::create([
                'site_id' => $siteA->id, 'category_id' => $catB->id,
                'name' => 'Cross Site Rev', 'slug' => 'cross-site-rev',
            ]);
            $this->fail('Cross-site Group A -> Category B should have been rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cross-site violation', $e->getMessage());
        }

        // Verify no orphan/partial data after failed attempts
        $this->assertEquals($groupCountBefore, Group::count(), 'Failed cross-site attempt left partial data');

        // Verify no cross-site references exist in database
        $crossRefs = DB::select("
            SELECT g.id FROM groups g
            JOIN categories c ON g.category_id = c.id
            WHERE g.site_id != c.site_id
        ");
        $this->assertCount(0, $crossRefs, 'Cross-site reference exists in database');
    }

    /** @test */
    public function invalid_site_id_is_rejected_by_fk(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('categories')->insert([
            'site_id' => 999999, 'name' => 'Test', 'slug' => 'test', 'type' => 'list',
            'sort' => 0, 'is_nav' => 1, 'is_active' => 1,
        ]);
    }

    /** @test */
    public function model_site_relation_works(): void
    {
        $this->seed();
        $cat = Category::first();
        $this->assertNotNull($cat);
        $this->assertNotNull($cat->site);
        $this->assertEquals(1, $cat->site->id);

        $group = Group::first();
        $this->assertNotNull($group->site);

        $fact = Fact::first();
        $this->assertNotNull($fact->site);

        $setting = Setting::first();
        $this->assertNotNull($setting->site);
    }

    /** @test */
    public function creating_event_auto_fills_site_id(): void
    {
        $cat = Category::create(['name' => 'Auto', 'slug' => 'auto-cat', 'type' => 'list']);
        $this->assertEquals(Site::defaultId(), $cat->site_id);

        $fact = Fact::create(['key' => 'auto-key', 'label' => 'L', 'value' => 'V']);
        $this->assertEquals(Site::defaultId(), $fact->site_id);
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
        $contentIdx = DB::select('PRAGMA index_list(contents)');
        $names = array_map(fn($i) => $i->name, $contentIdx);
        $this->assertContains('contents_external_id_index', $names);
        $this->assertContains('contents_category_id_group_id_index', $names);
        $this->assertContains('contents_site_id_index', $names);

        $catIdx = DB::select('PRAGMA index_list(categories)');
        $names = array_map(fn($i) => $i->name, $catIdx);
        $this->assertContains('categories_is_nav_is_active_index', $names);
        $this->assertContains('categories_parent_id_sort_index', $names);
        $this->assertContains('categories_site_id_index', $names);

        $groupIdx = DB::select('PRAGMA index_list(groups)');
        $names = array_map(fn($i) => $i->name, $groupIdx);
        $this->assertContains('groups_category_id_sort_index', $names);
        $this->assertContains('groups_site_id_index', $names);

        $factIdx = DB::select('PRAGMA index_list(facts)');
        $names = array_map(fn($i) => $i->name, $factIdx);
        $this->assertContains('facts_review_due_index', $names);
        $this->assertContains('facts_group_sort_index', $names);
        $this->assertContains('facts_site_id_index', $names);

        $settingIdx = DB::select('PRAGMA index_list(settings)');
        $names = array_map(fn($i) => $i->name, $settingIdx);
        $this->assertContains('settings_group_sort_index', $names);
        $this->assertContains('settings_site_id_index', $names);
    }

    /** @test */
    public function migration_preserves_all_seeded_data(): void
    {
        $this->seed();
        // Verify all seeded rows have site_id set and no data loss
        foreach ($this->tables as $table) {
            $total = DB::table($table)->count();
            $site1 = DB::table($table)->where('site_id', 1)->count();
            $nullSite = DB::table($table)->whereNull('site_id')->count();
            $this->assertEquals($total, $site1, "$table: not all rows have site_id=1");
            $this->assertEquals(0, $nullSite, "$table: has NULL site_id");
        }
        // ContentSeeder creates 3 knowledge articles + 1 Demo-only about.profile narrative slot
        // (P-STEP 18B, type=page), StructureSeeder creates 2 categories + 6 groups,
        // FactSeeder creates 23 facts, SettingSeeder (DefaultSettingSeeder + demo overrides) creates 69 settings
        // (P-STEP 17F: RETIRE 4 consumer-less keys + add 2 newly-defined keys)
        $this->assertEquals(4, Content::withoutGlobalScopes()->count()); // 3 articles + 1 about.profile slot
        $this->assertEquals(2, Category::count());
        $this->assertEquals(6, Group::count());
        $this->assertEquals(23, Fact::count());
        $this->assertEquals(69, Setting::count());
    }

    /** @test */
    public function groups_unique_is_site_id_category_id_slug(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a2', 'status' => 'active']);
        $catA = Category::create(['site_id' => $siteA->id, 'name' => 'Cat', 'slug' => 'cat-unique', 'type' => 'list']);

        Group::create(['site_id' => $siteA->id, 'category_id' => $catA->id, 'name' => 'G1', 'slug' => 'group-unique']);

        // Same category_id + slug in same site should fail
        $this->expectException(\Illuminate\Database\QueryException::class);
        Group::create(['site_id' => $siteA->id, 'category_id' => $catA->id, 'name' => 'G2', 'slug' => 'group-unique']);
    }
}
