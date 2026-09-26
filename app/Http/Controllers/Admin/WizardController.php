<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Setting;
use App\Support\SiteContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 18S Capability 1：首次运行 Setup Wizard。
 * 纯编排层：复用 Setting / Entity(Organization,Product,Service) / EntityRelation。
 * 状态走 session，进度写 Setting::set('wizard_completed', true) 完成标记。
 * 不新建模型/表/Renderer/Builder。
 */
class WizardController extends Controller
{
    public const STEPS = [
        1 => '基础信息',
        2 => '品牌信息',
        3 => '产品/服务',
        4 => '行业/场景关系',
        5 => '模板选择',
        6 => '发布检查',
    ];

    public function index(Request $request): View|RedirectResponse
    {
        $step = (int) $request->query('step', session('wizard_step', 1));
        if ($step < 1 || $step > 6) {
            $step = 1;
        }
        session(['wizard_step' => $step]);

        return view('admin.wizard.step', [
            'step'    => $step,
            'steps'   => self::STEPS,
            'isDone'  => (bool) Setting::get('wizard_completed'),
        ]);
    }

    public function save(Request $request, int $step): RedirectResponse
    {
        $siteId = SiteContext::currentSite()?->id;
        switch ($step) {
            case 1:
                Setting::set('site_name', $request->input('company_name', ''));
                Setting::set('contact_email', $request->input('email', ''));
                Setting::set('contact_phone', $request->input('phone', ''));
                Setting::set('contact_address', $request->input('address', ''));
                // 创建站点 Organization 实体（如尚无）
                if (! Entity::where('type', Entity::TYPE_ORGANIZATION)->where('site_id', $siteId)->exists()) {
                    Entity::create([
                        'site_id' => $siteId, 'type' => Entity::TYPE_ORGANIZATION,
                        'slug' => 'site-organization', 'name' => $request->input('company_name', '本站组织'),
                        'status' => Entity::STATUS_PUBLISHED, 'locale' => 'zh-CN',
                        'metadata' => ['is_site_organization' => true],
                    ]);
                }
                break;
            case 2:
                Setting::set('brand_color', $request->input('brand_color', ''));
                Setting::set('brand_slogan', $request->input('slogan', ''));
                break;
            case 3:
                foreach ((array) $request->input('products', []) as $p) {
                    if (empty($p['name'])) continue;
                    Entity::create([
                        'site_id' => $siteId, 'type' => Entity::TYPE_PRODUCT,
                        'slug' => \Illuminate\Support\Str::slug($p['name']),
                        'name' => $p['name'], 'summary' => $p['summary'] ?? '',
                        'status' => Entity::STATUS_DRAFT, 'locale' => 'zh-CN',
                        'metadata' => ['core' => true],
                    ]);
                }
                break;
            case 4:
                $org = Entity::where('type', Entity::TYPE_ORGANIZATION)->where('site_id', $siteId)->first();
                $prods = Entity::where('type', Entity::TYPE_PRODUCT)->where('site_id', $siteId)->get();
                if ($org) {
                    foreach ($prods as $prod) {
                        EntityRelation::firstOrCreate(
                            ['from_entity_id' => $org->id, 'to_entity_id' => $prod->id, 'relation_type' => 'produces'],
                            ['site_id' => $siteId]
                        );
                    }
                }
                break;
            case 5:
                // 模板选择：仅记录偏好，实际 apply 复用现有 Template 页面（向导内不强行切换）
                Setting::set('wizard_template', $request->input('template', 'default'));
                break;
            case 6:
                // 发布：把 draft 的 product 置 published
                Entity::where('type', Entity::TYPE_PRODUCT)
                    ->where('site_id', $siteId)
                    ->where('status', Entity::STATUS_DRAFT)
                    ->update(['status' => Entity::STATUS_PUBLISHED]);
                Setting::set('wizard_completed', 'true');
                session(['wizard_step' => 1]);
                Setting::flush();
                return redirect()->route('admin.dashboard')->with('status', '引导完成');
        }
        Setting::flush();
        $next = min(6, $step + 1);
        session(['wizard_step' => $next]);
        return redirect()->route('admin.wizard', ['step' => $next]);
    }
}
