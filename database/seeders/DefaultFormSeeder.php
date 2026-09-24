<?php

namespace Database\Seeders;

use App\Models\Form;
use App\Models\FormField;
use App\Models\Site;
use Illuminate\Database\Seeder;

/**
 * 出厂默认联系表单种子（P-STEP 18H-2，D3）。
 * --------------------------------------------------
 * 幂等创建中性 Default Contact Form（slug=contact）：name / phone / email /
 * message，不含任何 OEM / 原料采购 / 经销代理等制造业业务字段。
 *
 *  - FormField 每 locale 一行（zh-CN / en），结构列一致、展示列翻译；
 *  - 通知默认关闭（notification_enabled=false），无收件人；
 *  - success_message 留空，前台回退语言包。
 *
 * 是否在公开前台显示由现有公开 / 站点门禁决定，不因创建 Form 自动生成公开页面。
 */
class DefaultFormSeeder extends Seeder
{
    public function run(): void
    {
        $site = Site::where('slug', Site::DEFAULT_SLUG)->first();
        if (! $site) {
            return;
        }

        $form = Form::updateOrCreate(
            ['site_id' => $site->id, 'slug' => Form::DEFAULT_SLUG],
            [
                'name'                   => '联系表单 / Contact Form',
                'title'                  => null,
                'success_message'        => null,
                'status'                 => Form::STATUS_ENABLED,
                'consent_required'       => false,
                'honeypot_enabled'       => true,
                'notification_enabled'   => false,
                'notification_channels'  => 'email',
                'notification_recipients' => null,
            ]
        );

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
                FormField::updateOrCreate(
                    ['form_id' => $form->id, 'name' => $f['name'], 'locale' => $locale],
                    [
                        'site_id'     => $site->id,
                        'type'        => $f['type'],
                        'label'       => $f['label'],
                        'placeholder' => $f['placeholder'],
                        'help_text'   => null,
                        'required'    => $f['required'],
                        'validation'  => null,
                        'options'     => null,
                        'sort_order'  => $sort,
                    ]
                );
                $sort++;
            }
        }
    }
}
