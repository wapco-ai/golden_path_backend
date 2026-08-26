<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\ResourceCollection;

class AdminCollection extends ResourceCollection
{
    public $collects = AdminResource::class;

    public function toArray($request): array
    {
        return [
            'items' => $this->collection,
            'meta' => [
                'page' => $this->currentPage(),
                'pageSize' => $this->perPage(),
                'totalItems' => $this->total(),
                'totalPages' => $this->lastPage(),
            ],
        ];
    }
}
