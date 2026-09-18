<?php

namespace App\Repositories;

use App\Models\Entity;
use App\Models\EntityRelation;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

class EntityRepository
{
    public function findByTypeAndSlug(string $type, string $slug): ?Entity
    {
        return Entity::query()
            ->where('type', $type)
            ->where('slug', $slug)
            ->first();
    }

    public function findByTypeAndSlugOrFail(string $type, string $slug): Entity
    {
        $entity = $this->findByTypeAndSlug($type, $slug);

        if (!$entity) {
            throw (new ModelNotFoundException())->setModel(Entity::class, [$type, $slug]);
        }

        return $entity;
    }

    public function findPublishedByTypeAndSlug(string $type, string $slug): ?Entity
    {
        return Entity::published()
            ->ofType($type)
            ->where('slug', $slug)
            ->first();
    }

    public function getByType(string $type, bool $publishedOnly = true): EloquentCollection
    {
        $query = Entity::query()->ofType($type);

        if ($publishedOnly) {
            $query->published();
        }

        return $query->orderBy('sort_order')->orderBy('name')->get();
    }

    public function getOrganizations(bool $publishedOnly = true): EloquentCollection
    {
        return $this->getByType('organization', $publishedOnly);
    }

    public function getProducts(bool $publishedOnly = true): EloquentCollection
    {
        return $this->getByType('product', $publishedOnly);
    }

    public function getServices(bool $publishedOnly = true): EloquentCollection
    {
        return $this->getByType('service', $publishedOnly);
    }

    public function getPersons(bool $publishedOnly = true): EloquentCollection
    {
        return $this->getByType('person', $publishedOnly);
    }

    public function getLocations(bool $publishedOnly = true): EloquentCollection
    {
        return $this->getByType('location', $publishedOnly);
    }

    public function getTopics(bool $publishedOnly = true): EloquentCollection
    {
        return $this->getByType('topic', $publishedOnly);
    }

    public function getRelatedEntities(Entity $entity, ?string $relationType = null): Collection
    {
        $relations = $entity->relationsFrom()
            ->when($relationType, fn ($q) => $q->where('relation_type', $relationType))
            ->with('toEntity')
            ->orderBy('sort_order')
            ->get();

        return $relations->pluck('toEntity')->filter()->values();
    }

    public function getIncomingRelations(Entity $entity, ?string $relationType = null): Collection
    {
        $relations = $entity->relationsTo()
            ->when($relationType, fn ($q) => $q->where('relation_type', $relationType))
            ->with('fromEntity')
            ->orderBy('sort_order')
            ->get();

        return $relations->pluck('fromEntity')->filter()->values();
    }

    public function getProductsForService(Entity $service): Collection
    {
        return $this->getRelatedEntities($service, 'uses');
    }

    public function getServicesForProduct(Entity $product): Collection
    {
        return $this->getIncomingRelations($product, 'uses');
    }

    public function getProductsForOrganization(Entity $organization): Collection
    {
        return $this->getRelatedEntities($organization, 'produces');
    }

    public function getServicesForOrganization(Entity $organization): Collection
    {
        return $this->getRelatedEntities($organization, 'offers');
    }

    public function createRelation(
        Entity $from,
        Entity $to,
        string $relationType,
        array $metadata = [],
        int $sortOrder = 0
    ): EntityRelation {
        return EntityRelation::create([
            'site_id' => $from->site_id,
            'from_entity_id' => $from->id,
            'to_entity_id' => $to->id,
            'relation_type' => $relationType,
            'metadata' => $metadata,
            'sort_order' => $sortOrder,
        ]);
    }

    public function search(string $term, ?string $type = null, int $limit = 20): EloquentCollection
    {
        $query = Entity::query()
            ->published()
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('summary', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });

        if ($type) {
            $query->ofType($type);
        }

        return $query->limit($limit)->get();
    }
}
