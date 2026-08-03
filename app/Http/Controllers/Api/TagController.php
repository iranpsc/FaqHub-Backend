<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuestionResource;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum')->except(['index', 'show', 'questions']);
        $this->authorizeResource(Tag::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('query');
        $perPage = $request->input('per_page', 12); // Default to 12 tags per page

        $tags = Tag::withCount('questions')
            ->when($query, function ($q) use ($query) {
                return $q->where('name', 'like', "%{$query}%");
            });

        // Use paginate instead of simplePaginate to get full metadata
        $tags = $tags->paginate($perPage);

        return TagResource::collection($tags);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:tags,name',
            'slug' => 'nullable|string|max:255|unique:tags,slug',
        ]);

        $tag = Tag::create($validated);

        return new TagResource($tag);
    }

    /**
     * Display the specified resource.
     */
    public function show(Tag $tag)
    {
        return new TagResource($tag);
    }

    /**
     * Get questions for a specific tag
     */
    public function questions(Request $request, Tag $tag)
    {
        $questions = $tag->questions()
            ->with('user', 'category', 'tags')
            ->withCount('votes', 'answers')
            ->published()
            ->whereHas('tags', function ($query) use ($tag) {
                $query->where('tags.id', $tag->id);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        $questionsCollection = QuestionResource::collection($questions);

        // Add tag information to the response
        $response = $questionsCollection->toResponse($request)->getData(true);
        $response['tag'] = new TagResource($tag);

        return response()->json($response);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Tag $tag)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:tags,name,'.$tag->id,
            'slug' => 'nullable|string|max:255|unique:tags,slug,'.$tag->id,
        ]);

        $tag->update($validated);

        return new TagResource($tag);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Tag $tag)
    {
        $tag->delete();

        return response()->noContent();
    }
}
