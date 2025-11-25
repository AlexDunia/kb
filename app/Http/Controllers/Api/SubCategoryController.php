<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubCategory;
use Illuminate\Http\Request;

class SubCategoryController extends Controller
{
    /**
     * Display a listing of the subcategories
     */
    public function index(Request $request)
    {
        $subCategories = SubCategory::all();

        return response()->json([
            'data' => $subCategories
        ]);
    }
}
