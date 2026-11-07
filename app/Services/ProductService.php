<?php

namespace App\Services;

use App\Enums\Pagination;
use App\Enums\ProductOrder;
use App\Enums\ProductStatus;
use App\Models\Product\Product;
use App\Models\Product\ProductModel;
use App\Models\Product\WholesalePrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProductService
{
    private QueryService $queryService;

    public function __construct(QueryService $queryService)
    {
        $this->queryService = $queryService;
    }

    /**
     * Create the product.
     *
     * @param array $productData
     * @return int
     */
    public function create(array $productData)
    {
        return DB::transaction(fn () => $this->createProduct($productData), 3);
    }

    private function createProduct(array $productData)
    {
        $product = Product::create([
            'shop_id' => auth()->user()->id,
            'status_id' => $productData['is_published']
                ? ProductStatus::Published
                : ProductStatus::Delisted,
            ...Arr::only(
                $productData,
                [
                    'brand_id',
                    'name',
                    'description',
                    'weight',
                    'cost_price',
                    'unit',
                    'images',
                    'videos',
                    'variations',
                ]
            ),
        ]);

        $product->update(['product_code' => sprintf('PRD-%07d', $product->id)]);

        $this->syncModels($product, $productData['models'], true);

        $product->wholesalePrices()->saveMany(
            array_map(function ($price) use ($product) {
                $price['product_id'] = $product->id;

                return new WholesalePrice($price);
            }, $productData['wholesale_prices'] ?? []),
        );

        $product->attributes()->attach(collect($productData['attributes'] ?? [])->keyBy('attribute_id')->all());
        $product->categories()->attach($productData['category_path']);

        return $product->id;
    }

    /**
     * Add new product models.
     *
     * @param \App\Models\Product\Product $product
     * @param array $producyModelsData
     * @return void
     */
    private function attachModels($product, array $productModelsData)
    {
        $product->models()->saveMany(
            array_map(function ($model) {
                return new ProductModel($model);
            }, $productModelsData),
        );

        return;
    }

    /**
     * Synchronize product models.
     *
     * @param \App\Models\Product\Product $product
     * @param array $producyModelsData
     * @return void
     */
    private function syncModels($product, array $productModelsData)
    {
        $retainingModelIds = [];
        $createdModels = [];
        $updatedModels = [];

        foreach ($productModelsData as $model) {
            $model['product_id'] = $product->id;

            if (isset($model['id'])) {
                array_push($retainingModelIds, $model['id']);
                array_push($updatedModels, $model);
            } else {
                array_push($createdModels, $model);
            }
        }

        ProductModel::where('product_id', $product->id)
            ->whereNotIn('id', $retainingModelIds)
            ->delete();

        $this->attachModels($product, $createdModels);

        $this->queryService->updateMultipleRecords(
            'product_models',
            $updatedModels,
            'id',
            function ($model) {
                $model['variation_index'] = json_encode($model['variation_index']);

                return $model;
            },
        );

        return;
    }

    /**
     * Update the product
     *
     * @param int $id
     * @param array $productData
     * @return void
     */
    public function update(int $id, array $productData)
    {
        DB::transaction(fn () => $this->updateProduct($id, $productData), 3);
    }

    private function updateProduct(int $id, array $productData)
    {
        $product = Product::findOrFail($id);

        $product->update([
            'status_id' => $productData['is_published']
                ? ProductStatus::Published
                : ProductStatus::Delisted,
            ...Arr::only(
                $productData,
                [
                    'brand_id',
                    'name',
                    'description',
                    'weight',
                    'cost_price',
                    'unit',
                    'images',
                    'videos',
                    'variations',
                ],
            ),
        ]);

        $this->syncModels($product, $productData['models']);

        $product->wholesalePrices()->delete();
        $product->wholesalePrices()->saveMany(
            array_map(function ($price) use ($product) {
                $price['product_id'] = $product['id'];

                return new WholesalePrice($price);
            }, $productData['wholesale_prices'] ?? []),
        );

        $product->attributes()->sync(
            collect($productData['attributes'] ?? [])->keyBy('attribute_id'),
        );
        $product->categories()->sync($productData['category_path']);

        return;
    }

    /**
     * Get prices of the product models.
     *
     * @param array $productData
     * @return array
     */
    public function getPrices(array $productData)
    {
        $modelIds = [];

        foreach ($productData as $key => $product) {
            $modelIds[$key] = $product['product_model_id'];
        }

        if (empty($modelIds)) {
            return [];
        }

        $modelIds = array_map('intval', $modelIds);

        $models = ProductModel::whereIn('id', $modelIds)
            ->orderByRaw('FIELD(id, ' . join(',', $modelIds) . ')')
            ->with('wholesalePirces')
            ->get();

        $prices = $models->map(function ($model, $key) use ($productData) {
            $wholesalePrice = $model->wholesalePirces
                ->where('min', '<=', $productData[$key]['quantity'])
                ->firstWhere('max', '>=', $productData[$key]['quantity']);

            $pricePerOne = is_null($wholesalePrice)
                ? $model->price
                : $wholesalePrice->price;

            $price = $pricePerOne * $productData[$key]['quantity'];
            $finalPrice = $price;

            return [
                'product_id' => $model->product_id,
                'product_model_id' => $model->id,
                'is_wholesale_price_applied' => isset($wholesalePrice),
                'variation_index' => $model->variation_index,
                'total_price' => round($price, 2),
                'final_total_price' => round($finalPrice, 2),
            ];
        })
            ->toArray();

        return $prices;
    }

    /**
     * Search the products.
     *
     * @param array $productQuery
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function search(array $productQuery)
    {
        $products = $this->filterBy(
            isset($productQuery['keyword'])
                ? Product::whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%' . strtolower($productQuery['keyword'] ?? '') . '%'],
                )
                : Product::query()
            ,
            $productQuery['filter'] ?? [],
        );

        $products->where('status_id', ProductStatus::Published->value)
            ->orderByRaw("CASE WHEN JSON_VALID(images) THEN COALESCE(JSON_LENGTH(images), 0) > 0 ELSE 0 END DESC")
            ->orderByRaw("EXISTS (SELECT 1 FROM product_models WHERE product_models.product_id = products.id AND product_models.stock > 0) DESC");
        $products = $this->orderBy($products, $productQuery['order_by'] ?? ProductOrder::Newest->value)
            ->orderByDesc('products.id')
            ->with('models')
            ->paginate($productQuery['limit'] ?? Pagination::Default->value);

        return $products;
    }

    /**
     * Sort the products.
     *
     * @param \Illuminate\Database\Eloquent\Builder $product
     * @param int $sortId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function orderBy(Builder $product, int $sortId)
    {
        switch (ProductOrder::from($sortId)) {
            case ProductOrder::Newest:
                return $this->orderByNewest($product);

            case ProductOrder::HighToLow:
                return $this->orderByHighToLow($product);

            case ProductOrder::LowToHigh:
                return $this->orderByLowToHigh($product);

            case ProductOrder::BestSelling:
                return $product->orderByDesc('sold');

            default:
                return $product;
        }
    }

    /**
     * Sort from new to old.
     *
     * @param \Illuminate\Database\Eloquent\Builder $product
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function orderByNewest(Builder $product)
    {
        return $product->orderBy('created_at', 'desc');
    }

    /**
     * Sort from high price to low price.
     *
     * @param \Illuminate\Database\Eloquent\Builder $product
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function orderByHighToLow(Builder $product)
    {
        return $product->orderBy('avg_price', 'desc');
    }

    /**
     * Sort from low price to high price.
     *
     * @param \Illuminate\Database\Eloquent\Builder $product
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function orderByLowToHigh(Builder $product)
    {
        return $product->orderBy('avg_price', 'asc');
    }

    /**
     * Filter the products.
     *
     * @param \Illuminate\Database\Eloquent\Builder $product
     * @param array $filter
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function filterBy(Builder $product, array $filter)
    {
        if (isset($filter['category_ids'])) {
            $product = $this->filterByCategories($product, $filter['category_ids']);
        }

        return $product;
    }

    /**
     * Filter the products by categories.
     *
     * @param \Illuminate\Database\Eloquent\Builder $product
     * @param array $categoryIds
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function filterByCategories(Builder $product, array $categoryIds)
    {
        $children = \App\Models\Product\Category::get(['id', 'parent_id'])->groupBy('parent_id');
        $selected = [];
        while ($categoryIds) {
            $id = (int) array_pop($categoryIds);
            if (isset($selected[$id])) {
                continue;
            }
            $selected[$id] = true;
            foreach ($children->get($id, []) as $child) {
                $categoryIds[] = $child->id;
            }
        }

        return $product->whereHas('categories', fn (Builder $categories) =>
            $categories->whereIn('product_categories.id', array_keys($selected)));
    }
}
