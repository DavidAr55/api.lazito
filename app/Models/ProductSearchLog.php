<?php

namespace App\Models;

use Database\Factories\ProductSearchLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A call made to a paid product search provider and the credits it cost.
 */
class ProductSearchLog extends Model
{
    /** @use HasFactory<ProductSearchLogFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['provider', 'query', 'credits', 'result_count'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'result_count' => 'integer',
        ];
    }
}
