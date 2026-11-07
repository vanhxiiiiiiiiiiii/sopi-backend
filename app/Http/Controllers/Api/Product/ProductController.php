<?php

namespace App\Http\Controllers\Api\Product;

use App\Enums\Pagination;
use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Product\CreateProductRequest;
use App\Http\Requests\Product\DeleteProductRequest;
use App\Http\Requests\Product\GetProductRequest;
use App\Http\Requests\Product\SearchProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Product\Product;
use App\Services\ProductService;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ProductController extends Controller
{
    private ProductService $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;

        $this->middleware('auth:sanctum')->except([
            'get',
            'feature',
            'search',
            'publishedBelongToShop',
        ]);
    }

    /**
     * Get the product by id.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function get(int $id)
    {
        $product = Product::where([
            ['id', $id],
            ['status_id', ProductStatus::Published->value],
        ])
            ->with([
                'attributes',
                'models',
                'wholesalePrices',
                'shop',
                'categories',
            ])
            ->withCount('reviews')
            ->firstOrFail();

        return response()->json([
            'status' => true,
            'data' => $product,
        ]);
    }

    /**
     * Get featured products.
     *
     * @param \App\Http\Requests\Product\GetProductRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function feature(GetProductRequest $request)
    {
        $featuredProducts = Product::where('status_id', ProductStatus::Published->value)
            ->with('models')
            ->orderByRaw("CASE WHEN JSON_VALID(images) THEN COALESCE(JSON_LENGTH(images), 0) > 0 ELSE 0 END DESC")
            ->orderByRaw("EXISTS (SELECT 1 FROM product_models WHERE product_models.product_id = products.id AND product_models.stock > 0) DESC")
            ->orderByRaw("CASE WHEN id <= 33 THEN 1 ELSE 0 END DESC")
            ->orderByDesc('id')
            ->paginate($request->input('limit') ?? Pagination::Default->value);

        return response()->json([
            'status' => true,
            'data' => $featuredProducts,
        ]);
    }

    /**
     * Search the products by text.
     *
     * @param \App\Http\Requests\Product\SearchProductRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(SearchProductRequest $request)
    {
        $products = $this->productService->search(
            $request->validated(),
        );

        return response()->json([
            'status' => true,
            'data' => $products,
        ]);
    }

    /**
     * Get published products of the shop.
     *
     * @param \App\Http\Requests\Product\GetProductRequest $request
     * @param int $shopId
     * @return \Illuminate\Http\JsonResponse
     */
    public function publishedBelongToShop(GetProductRequest $request, int $shopId)
    {
        $publishedProducts = Product::where([
            ['shop_id', $shopId],
            ['status_id', ProductStatus::Published],
        ])
            ->with('models')
            ->orderByDesc('id')
            ->paginate($request->input('limit') ?? Pagination::Default->value);

        return response()->json([
            'status' => true,
            'data' => $publishedProducts,
        ]);
    }

    /**
     * Get products of the current shop.
     *
     * @param \App\Http\Requests\Product\GetProductRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function belongToShop(GetProductRequest $request)
    {
        abort_unless($request->user()->shop, 403);
        $filters = $request->validate([
            'keyword' => 'nullable|string|max:120',
            'status' => 'sometimes|in:all,online,out-of-stock,hidden',
        ]);

        $query = Product::where('shop_id', $request->user()->id)
            ->where('status_id', '<>', ProductStatus::Deleted->value);

        if ($keyword = $filters['keyword'] ?? null) {
            $pattern = '%' . $keyword . '%';
            $query->where(fn ($products) => $products->where('name', 'like', $pattern)
                ->orWhere('product_code', 'like', $pattern)
                ->orWhereHas('models', fn ($models) => $models->where('sku', 'like', $pattern)));
        }

        $status = $filters['status'] ?? 'all';
        if ($status === 'hidden') {
            $query->where('status_id', ProductStatus::Delisted->value);
        } elseif ($status === 'online') {
            $query->where('status_id', ProductStatus::Published->value);
        } elseif ($status === 'out-of-stock') {
            $query->whereDoesntHave('models', fn ($models) => $models->where('stock', '>', 0));
        }

        $products = $query->with(['models', 'categories'])->orderByDesc('id')
            ->paginate($request->input('limit') ?? Pagination::Default->value);

        return response()->json([
            'status' => true,
            'data' => $products,
        ]);
    }

    public function updateListing(Request $request, Product $product)
    {
        abort_unless(
            $request->user()->shop && $product->shop_id === $request->user()->id
            && $product->status_id !== ProductStatus::Deleted->value,
            403,
        );

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string|max:10000',
            'weight' => 'sometimes|required|numeric|min:0.1|max:999999',
            'is_published' => 'sometimes|required|boolean',
            'model_prices' => 'sometimes|array',
            'model_prices.*.id' => 'required|integer|min:1|distinct',
            'model_prices.*.price' => 'required|numeric|min:0.5|max:999999.99',
        ]);
        if (array_key_exists('description', $data)) {
            $data['description'] ??= '';
        }
        if (array_key_exists('is_published', $data)) {
            $data['status_id'] = $data['is_published'] ? ProductStatus::Published : ProductStatus::Delisted;
            unset($data['is_published']);
        }
        $prices = $data['model_prices'] ?? [];
        unset($data['model_prices']);
        DB::transaction(function () use ($product, $data, $prices) {
            $listing = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            abort_if($listing->status_id === ProductStatus::Deleted->value, 403);
            foreach ($prices as $price) {
                $model = $listing->models()->whereKey($price['id'])->first();
                if (!$model) {
                    throw ValidationException::withMessages(['model_prices' => 'The variant does not belong to this product.']);
                }
                $model->update(['price' => $price['price']]);
            }
            $listing->update($data);
        }, 3);

        return response()->json(['status' => true, 'data' => $product->fresh('models')]);
    }

    /**
     * Create the product.
     *
     * @param \App\Http\Requests\Product\CreateProductRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function create(CreateProductRequest $request)
    {
        $this->productService->create(
            $request->validated(),
        );

        return response()->json([
            'status' => true,
            'message' => 'Product has been created!',
        ], Response::HTTP_CREATED);
    }

    /**
     * Update the product.
     *
     * @param \App\Http\Requests\Product\UpdateProductRequest $request
     * @param \App\Models\Product\Product $product
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateProductRequest $request, Product $product)
    {
        $this->productService->update(
            $product->id,
            $request->validated(),
        );

        return response()->json([
            'status' => true,
            'message' => 'Product has been updated!',
        ]);
    }

    /**
     * Delete the product.
     *
     * @param \App\Http\Requests\Product\DeleteProductRequest $request
     * @param \App\Models\Product\Product $product
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete(DeleteProductRequest $request, Product $product)
    {
        $product->update([
            'status_id' => ProductStatus::Deleted,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Product has been deleted!',
        ]);
    }

    /**
     * Recovery the product.
     *
     * @param \App\Models\Product\Product $product
     * @return \Illuminate\Http\JsonResponse
     */
    public function recovery(Product $product)
    {
        if ($product->status_id !== ProductStatus::Deleted->value) {
            throw new BadRequestHttpException('Product has not been deleted yet!');
        }

        $product->update([
            'status_id' => ProductStatus::Delisted,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Product has been recoveried!',
        ]);
    }
}
