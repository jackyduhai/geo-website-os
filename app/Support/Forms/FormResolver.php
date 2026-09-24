<?php

namespace App\Support\Forms;

use App\Models\Form;

/**
 * 表单解析器（P-STEP 18H-2）。
 * --------------------------------------------------
 * 在当前站点（BelongsToSite 全局作用域）按 slug / id 解析启用的表单；
 * disabled / 跨站 / 缺失返回 null。供前台 FormReference block 与旧 /inquiry
 * 兼容路由使用，不在多处重复查询逻辑。
 */
class FormResolver
{
    /** 按 slug 解析当前站点启用的表单。 */
    public function find(string $slug): ?Form
    {
        return Form::where('slug', $slug)
            ->where('status', Form::STATUS_ENABLED)
            ->first();
    }

    /** 按 id 解析（FormReference block 引用）；disabled 返回 null。 */
    public function findById(mixed $id): ?Form
    {
        if (! is_numeric($id) || (int) $id <= 0) {
            return null;
        }

        $form = Form::find((int) $id);

        return ($form && $form->isEnabled()) ? $form : null;
    }

    /**
     * 站点默认联系表单：优先 slug=contact；不存在则取当前站点首个启用表单。
     */
    public function defaultContact(): ?Form
    {
        return $this->find(Form::DEFAULT_SLUG)
            ?? Form::where('status', Form::STATUS_ENABLED)->orderBy('id')->first();
    }
}
