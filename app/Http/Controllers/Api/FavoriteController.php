<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product\Favorite;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request)
    {
        return response()->json(['status' => true, 'data' => Favorite::where('user_id', $request->user()->id)->with('product.models')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id']);
        $favorite = Favorite::firstOrCreate(['user_id' => $request->user()->id, 'product_id' => $data['product_id']]);
        return response()->json(['status' => true, 'data' => $favorite], 201);
    }

    public function destroy(Request $request, int $productId)
    {
        Favorite::where('user_id', $request->user()->id)->where('product_id', $productId)->delete();
        return response()->json(['status' => true]);
    }
}
