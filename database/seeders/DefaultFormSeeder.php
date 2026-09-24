<?php

namespace Database\Seeders;

use App\Models\Form;
use App\Models\FormField;
use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Database\Seeder;

/**
 * 出厂默认联系表单种子（P-STEP 18H-2，D3；18H-3 改为不覆盖）。
 * --------------------------------------------------
 * 作用于「当前站点」（SiteContext，CLI 下回退默认站点），幂等创建中性
 * Default Contact Form（slug=contact）：name / phone / email / message，
 * 不含任何 OEM / 原料采购 / 经销代理等制造业业务字段。
 *
 * 关键：默认 contact 表单一旦已存在即整体保留（字段 / 通知 / 启停 / 成功文案），
 * 重复执行或 geo:upgrade 绝不覆盖站点自定义。
 *
 *  - FormField 每 locale 一行（zh-CN / en），结构列一致、展示列翻译；
 *  - 通知默认关闭（notification_enabled=false），无收件人；
 *  - 是否在公开前台显示由现有公开 / 站点门禁决定，不因创建 Form 自动生成公开页面。
 */
class DefaultFormSeeder extends Seeder
{
    public function run(): void
    {
        $site = SiteContext::currentSite() ?: Site::default();
        if (! $site) {
            return;
        }

        // 已存在默认 contact 表单：保留站点全部自定义，不覆盖。
        $existing = Form::where('site_id', $site->id)
            ->where('slug', Form::DEFAULT_SLUG)->first();
        if ($existing) {
            return;
        }

        $form = Form::create([
            'site_id'                => $site->id,
            'slug'                   => Form::DEFAULT_SLUG,
            'name'                   => '联系表单 / Contact Form',
            'title'                  => null,
            'success_message'        => null,
            'status'                 => Form::STATUS_ENABLED,
            'consent_required'       => false,
            'honeypot_enabled'       => true,
            'notification_enabled'   => false,
            'notification_channels'  => 'email',
            'notification_recipients' => null,
        ]);

        $definitions = [
            'zh-CN' => [
                ['name' => 'name', 'type' => 'text', 'required' => true,
                    'label' => '称呼', 'placeholder' => '怎么称呼您'],
                ['name' => 'phone', 'type' => 'tel', 'required' => true,
                    'label' => '联系电话', 'placeholder' => '请输入手机号'],
                ['name' => 'email', 'type' => 'email', 'required' => false,
                    'label' => '邮箱', 'placeholder' => '选填，请输入邮箱'],
                ['name' => 'message', 'type' => 'textarea', 'required' => false,
                    'label' => '留言', 'placeholder' => '请描述您的需求'],
            ],
            'en' => [
                ['name' => 'name', 'type' => 'text', 'required' => true,
                    'label' => 'Name', 'placeholder' => 'Your name'],
                ['name' => 'phone', 'type' => 'tel', 'required' => true,
                    'label' => 'Phone', 'placeholder' => 'Your phone number'],
                ['name' => 'email', 'type' => 'email', 'required' => false,
                    'label' => 'Email', 'placeholder' => 'Optional, your email'],
                ['name' => 'message', 'type' => 'textarea', 'required' => false,
                    'label' => 'Message', 'placeholder' => 'How can we help?'],
            ],
        ];

        foreach ($definitions as $locale => $fields) {
            $sort = 0;
            foreach ($fields as $f) {
                FormField::create([
                    'form_id'     => $form->id,
                    'site_id'     => $site->id,
                    'name'        => $f['name'],
                    'type'        => $f['type'],
                    'required'    => $f['required'],
                    'label'       => $f['label'],
                    'placeholder' => $f['placeholder'],
                    'help_text'   => null,
                    'validation'  => null,
                    'options'     => null,
                    'sort_order'  => $sort,
                    'locale'      => $locale,
                ]);
                $sort++;
            }
        }
    }
}
