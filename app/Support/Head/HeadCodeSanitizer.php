<?php

namespace App\Support\Head;

/**
 * seo_head_code 安全解析器（P-STEP 18H-3 / TD-90）。
 *
 * V1 起 seo_head_code 不再是任意 HTML/JS 注入入口：从 parser 层
 * （DOMDocument，而非「过滤几个危险标签」）只允许 <meta> / <link> 元素，
 * 且属性走白名单、值拒绝危险 scheme。
 *
 * - <script> / <style> / <iframe> / <object> / 事件属性 / javascript: 一律剔除；
 * - 历史值若含上述非法元素 / 属性：返回 invalid=true，合法的 meta/link 仍输出，
 *   非法部分不运行，并由调用方在源码中可见地标记 legacy/invalid。
 */
class HeadCodeSanitizer
{
    private const ALLOWED = [
        'meta' => ['name', 'content', 'property', 'charset', 'itemprop'],
        'link' => ['rel', 'href', 'hreflang', 'type', 'sizes', 'crossorigin', 'media', 'title', 'as'],
    ];

    /**
     * @return array{html:string, invalid:bool}
     */
    public function sanitize(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return ['html' => '', 'invalid' => false];
        }

        $dom = new \DOMDocument();
        $libxml = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="gwos-head-root">' . $raw . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($libxml);

        $root = null;
        foreach ($dom->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('id') === 'gwos-head-root') {
                $root = $div;
                break;
            }
        }
        if (! $root) {
            return ['html' => '', 'invalid' => true];
        }

        $invalid = false;
        $html = '';

        foreach (iterator_to_array($root->childNodes) as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                if (trim($node->nodeValue) !== '') {
                    $invalid = true; // head 片段不允许裸文本
                }

                continue;
            }
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                $invalid = true;

                continue;
            }

            $tag = strtolower($node->nodeName);
            if (! array_key_exists($tag, self::ALLOWED)) {
                $invalid = true; // script / style / iframe / object / ... 全部拒绝

                continue;
            }

            $clean = $dom->createElement($tag);
            $rejected = false;

            foreach ($node->attributes as $attr) {
                $attrName = strtolower($attr->name);
                $attrValue = $attr->value;

                if (! in_array($attrName, self::ALLOWED[$tag], true)
                    || $this->isDangerousValue($attrName, $attrValue)) {
                    $rejected = true; // 事件属性 / 非白名单属性 / 危险 scheme

                    continue;
                }
                $clean->setAttribute($attrName, $attrValue);
            }

            if ($tag === 'link' && ! $clean->hasAttribute('rel')) {
                $invalid = true;

                continue;
            }
            if ($tag === 'meta' && ! ($clean->hasAttribute('name')
                || $clean->hasAttribute('property') || $clean->hasAttribute('charset'))) {
                $invalid = true;

                continue;
            }

            if ($rejected) {
                $invalid = true;
            }
            $html .= $dom->saveHTML($clean);
        }

        return ['html' => $html, 'invalid' => $invalid];
    }

    private function isDangerousValue(string $attrName, string $value): bool
    {
        if ($attrName !== 'href') {
            return false;
        }
        $v = trim($value);

        // 明确禁止的 scheme
        if (preg_match('/^\s*(javascript|vbscript|data|file)\s*:/i', $v)) {
            return true;
        }
        // 允许 http(s)://、协议相对 //、绝对路径 /、页内 #、无 scheme 相对路径；
        // 其余带 scheme（如 xxx:）一律拒绝。
        if (! preg_match('#^(https?:)?//#i', $v)
            && ! in_array($v[0] ?? '', ['/', '#'], true)
            && preg_match('/^[a-z][a-z0-9+.-]*:/i', $v)) {
            return true;
        }

        return false;
    }
}
