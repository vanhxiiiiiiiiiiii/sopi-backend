<?php

namespace App\Services;

use App\Models\Product\Category;
use Illuminate\Support\Facades\DB;

class ProductCategoryService
{
    private QueryService $queryService;

    public function __construct(QueryService $queryService)
    {
        $this->queryService = $queryService;
    }

    /**
     * Get all attributes of the categories.
     *
     * @param array $ids
     * @return array
     */
    public function getAttributes($ids)
    {
        $attributes = [];
        $categoriesWithAttributes = Category::whereIn('id', $ids)
            ->with('attributes')
            ->get()
            ;

        foreach ($categoriesWithAttributes as $categoryWithAttributes) {
            array_push($attributes, ...$categoryWithAttributes->attributes);
        }

        return collect($attributes)->sortByDesc('is_required')->unique('id')->values()->all();
    }

    /**
     * Create, update, or delete categories.
     *
     * @param array $categoryChanges
     * @return void
     */
    public function manage($categoryChanges)
    {
        DB::transaction(function () use ($categoryChanges) {
            if (isset($categoryChanges['create'])) {
                Category::insert($categoryChanges['create']);
            }

            if (isset($categoryChanges['update'])) {
                $this->queryService->updateMultipleRecords(
                    'product_categories',
                    $categoryChanges['update'],
                );
            }

            if (isset($categoryChanges['delete'])) {
                Category::destroy(array_map(function($val) {
                    return $val['id'];
                }, $categoryChanges['delete']));
            }

            $this->refreshChildrenCounts();
        });

        return;
    }

    /**
     * Refresh the denormalized child count without a self-updating trigger.
     *
     * @return void
     */
    public function refreshChildrenCounts()
    {
        Category::query()->update(['number_of_children' => 0]);

        Category::query()
            ->selectRaw('parent_id, COUNT(*) as children_count')
            ->whereNotNull('parent_id')
            ->groupBy('parent_id')
            ->get()
            ->each(function ($category) {
                Category::whereKey($category->parent_id)->update([
                    'number_of_children' => $category->children_count,
                ]);
            });
    }
}
