<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Support\Copy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 前台在线留言提交
 * - 蜜罐字段 website 正常用户不可见，一旦填写视为机器，静默成功不落库
 * - 频率限制在路由层 throttle
 */
class InquiryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        // 话术与客户类型选项均来自可运营取数层（后台「站点设置 → 文案话术」），
        // 运营调整选项后，前端下拉与后端校验同步生效；同时兼容历史四类需求类型。
        $formCopy = Copy::form();
        $ff = $formCopy['fields'];

        // 蜜罐：命中则假装成功，避免机器人察觉
        if (filled($request->input('website'))) {
            return back()->with('lead_success', $formCopy['success']);
        }

        $customerOptions = (array) $ff['customerType']['options'];
        $allowedTypes = array_values(array_unique(array_merge($customerOptions, Inquiry::TYPES)));

        $data = $request->validate([
            'name'         => ['required', 'string', 'max:50'],
            'phone'        => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()wx微信,，]{6,30}$/'],
            'company'      => ['nullable', 'string', 'max:120'],
            'demand_type'  => ['required', 'string', Rule::in($allowedTypes)],
            'monthly_use'  => ['nullable', 'string', 'max:60'],
            'message'      => ['nullable', 'string', 'max:1000'],
            // 来源归因（隐藏字段，限制长度，防止被当作注入入口）
            'landing_url'  => ['nullable', 'string', 'max:255'],
            'referer'      => ['nullable', 'string', 'max:255'],
            'utm_source'   => ['nullable', 'string', 'max:64'],
            'utm_medium'   => ['nullable', 'string', 'max:64'],
            'utm_campaign' => ['nullable', 'string', 'max:96'],
            'utm_term'     => ['nullable', 'string', 'max:64'],
            'utm_content'  => ['nullable', 'string', 'max:64'],
        ], [
            'name.required'        => $ff['name']['error'],
            'phone.required'       => $ff['phone']['error'],
            'phone.regex'          => $ff['phone']['invalid'],
            'demand_type.required' => $ff['customerType']['error'],
            'demand_type.in'       => __('seo.inquiry_type_invalid'),
        ]);

        // 需求简述为选填；为空（含字段未提交）时用客户类型兜底，保证后台有可读内容。
        // 注意 message 为 nullable，未勾选/未填写时 $data 中可能不存在该键，必须 ?? ''，
        // 否则 Undefined array key 会导致 500（P-STEP 14 Bug Hunt）。
        if (trim((string) ($data['message'] ?? '')) === '') {
            $data['message'] = __('seo.inquiry_message_fallback', ['type' => $data['demand_type']]);
        }

        $attribution = array_intersect_key($data, array_flip([
            'landing_url', 'referer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        ]));

        Inquiry::create([
            ...array_diff_key($data, $attribution),
            ...$attribution,
            // 提交所在页（表单页），与首次落地页区分
            'source_page' => mb_substr((string) $request->headers->get('referer'), 0, 255),
            'device_type' => Inquiry::deviceFromUserAgent($request->userAgent()),
            'ip'          => $request->ip(),
            'user_agent'  => mb_substr((string) $request->userAgent(), 0, 255),
            'status'      => 'new',
        ]);

        return back()->with('lead_success', $formCopy['success']);
    }
}
