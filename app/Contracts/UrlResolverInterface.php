<?php

namespace App\Contracts;

interface UrlResolverInterface
{
    /**
     * Generate absolute canonical URL for given resource type
     */
    public function generateCanonical(string $type, array $params = []): string;
}
