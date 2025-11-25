<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Display a listing of the categories
     */
    public function index(Request $request)
    {
        $categories = Category::all();

        return response()->json([
            'data' => $categories
        ]);
    }
}
