<?php

namespace App\Services\Seo;

class SeoResult
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $description,
        public readonly array $keywords,
        public readonly string $canonical,
        public readonly string $ogTitle,
        public readonly ?string $ogDescription,
        public readonly ?string $ogImage,
        public readonly string $ogType,
        public readonly string $twitterCard,
        public readonly bool $noindex,
        public readonly bool $nofollow,
        public readonly array $robots,
        public readonly ?string $schemaType
    ) {}

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'keywords' => $this->keywords,
            'canonical' => $this->canonical,
            'og_title' => $this->ogTitle,
            'og_description' => $this->ogDescription,
            'og_image' => $this->ogImage,
            'og_type' => $this->ogType,
            'twitter_card' => $this->twitterCard,
            'noindex' => $this->noindex,
            'nofollow' => $this->nofollow,
            'robots' => $this->robots,
            'schema_type' => $this->schemaType,
        ];
    }
}
