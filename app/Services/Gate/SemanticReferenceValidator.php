<?php

namespace App\Services\Gate;

use App\Models\Content;
use App\Services\Gate\Contracts\SemanticReferenceResolver;

/**
 * 语义引用校验器（WP-3）。
 * ------------------------------------------------------------------
 * 遍历 Content.entity_refs（显式声明的语义引用），经 SemanticReferenceResolver
 * 解析每个引用的身份状态，按 G-7 五分支聚合：
 *   VALID          → 无问题
 *   DRAFT / SOFT_DELETED / NOINDEX / KNOWN_DELETED
 *                  → 警告（不阻止发布）
 *   UNKNOWN_ID     → 错误（阻止发布：引用本身不存在）
 *
 * 本类只产出 errors / warnings，绝不修改 Content 业务状态（不拉 draft / noindex）；
 * 不解析自由文本、不合并 content_entity（结构引用）。
 */
class SemanticReferenceValidator
{
    public function __construct(protected SemanticReferenceResolver $resolver)
    {
    }

    /**
     * 校验内容的全部语义引用。
     *
     * @return array{0:array<int,string>,1:array<int,string>} [errors, warnings]
     */
    public function validate(Content $content): array
    {
        $errors = [];
        $warnings = [];

        foreach ($content->entity_refs ?: [] as $raw) {
            $entityId = (int) $raw;
            if ($entityId <= 0) {
                $errors[] = sprintf('语义引用包含非法实体 ID：%s', (string) $raw);

                continue;
            }

            $state = $this->resolver->resolve((int) $content->site_id, $entityId);

            switch ($state) {
                case SemanticReferenceResolver::UNKNOWN_ID:
                    // BLOCK 的原因是「引用本身不存在」，不是实体不可公开。
                    $errors[] = sprintf('语义引用了从未被系统确认存在的实体（#%d），属数据错误', $entityId);
                    break;

                case SemanticReferenceResolver::DRAFT:
                    $warnings[] = sprintf('语义引用了未公开（草稿）实体（#%d）', $entityId);
                    break;

                case SemanticReferenceResolver::SOFT_DELETED:
                    $warnings[] = sprintf('语义引用了已删除实体（#%d）', $entityId);
                    break;

                case SemanticReferenceResolver::KNOWN_DELETED:
                    $warnings[] = sprintf('语义引用了已永久删除实体（#%d，该实体不存在）', $entityId);
                    break;

                case SemanticReferenceResolver::NOINDEX:
                    $warnings[] = sprintf('语义引用了 noindex 实体（#%d），其知识不会进入 GEO', $entityId);
                    break;

                case SemanticReferenceResolver::VALID:
                default:
                    // 身份有效，无问题。
                    break;
            }
        }

        return [$errors, $warnings];
    }
}
