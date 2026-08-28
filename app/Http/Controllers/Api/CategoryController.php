<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\QuestionResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum')->except(['index', 'show', 'popular', 'questions']);
        $this->authorizeResource(Category::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('query');
        $categories = Category::when($query, function ($q) use ($query) {
            return $q->where('name', 'like', "%{$query}%");
        });

        $categories = $query
            ? $categories->get()
            : $categories->withCount('questions')->paginate();

        return CategoryResource::collection($categories);
    }

    /**
     * Get popular categories based on questions count.
     * Optimized: Uses simple questions_count instead of complex JOINs
     */
    public function popular(Request $request)
    {
        $limit = $request->input('limit', 15);

        // Simplified query - just use questions_count for popularity
        // The complex JOINs for answers and comments were causing performance issues
        $categories = Category::select('categories.*')
            ->selectSub(
                DB::table('questions')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('questions.category_id', 'categories.id')
                    ->where('questions.published', true),
                'questions_count'
            )
            ->orderByDesc('questions_count')
            ->limit($limit)
            ->get();

        return CategoryResource::collection($categories);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:categories',
            'parent_id' => 'nullable|exists:categories,id',
        ]);

        $category = Category::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'parent_id' => $request->parent_id,
        ]);

        return new CategoryResource($category);
    }

    /**
     * Display the specified resource.
     */
    public function show(Category $category)
    {
        $category->load('children');

        return new CategoryResource($category);
    }

    /**
     * Get questions for a specific category.
     */
    public function questions(Category $category)
    {
        $questions = $category->questions()
            ->with(['user', 'category'])
            ->withCount('answers', 'votes')
            ->latest()
            ->paginate(15);

        return QuestionResource::collection($questions);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name,'.$category->id,
            'parent_id' => 'nullable|exists:categories,id',
        ]);

        $category->update([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'parent_id' => $request->parent_id,
        ]);

        return new CategoryResource($category);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category)
    {
        $category->delete();

        return response()->noContent();
    }
}
