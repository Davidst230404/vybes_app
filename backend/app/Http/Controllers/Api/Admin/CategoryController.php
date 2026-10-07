<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Http\Resources\Admin\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * List all categories with venues count.
     */
    public function index(): AnonymousResourceCollection
    {
        $categories = Category::withCount('venues')
            ->orderBy('sort_order')
            ->latest('id')
            ->get();

        return CategoryResource::collection($categories);
    }

    /**
     * Store new category.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (empty($validated['slug'])) {
            $baseSlug = Str::slug($validated['name']);
            $slug = $baseSlug;
            $counter = 1;
            while (Category::where('slug', $slug)->exists()) {
                $slug = "{$baseSlug}-{$counter}";
                $counter++;
            }
            $validated['slug'] = $slug;
        }

        $category = Category::create($validated);

        return response()->json([
            'message' => 'Category created successfully.',
            'data' => new CategoryResource($category->loadCount('venues')),
        ], 201);
    }

    /**
     * Show category detail.
     */
    public function show(Category $category): JsonResponse
    {
        return response()->json([
            'data' => new CategoryResource($category->loadCount('venues')),
        ]);
    }

    /**
     * Update category.
     */
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $validated = $request->validated();

        if (isset($validated['name']) && empty($validated['slug'])) {
            $baseSlug = Str::slug($validated['name']);
            $slug = $baseSlug;
            $counter = 1;
            while (Category::where('slug', $slug)->where('id', '!=', $category->id)->exists()) {
                $slug = "{$baseSlug}-{$counter}";
                $counter++;
            }
            $validated['slug'] = $slug;
        }

        $category->update($validated);

        return response()->json([
            'message' => 'Category updated successfully.',
            'data' => new CategoryResource($category->fresh()->loadCount('venues')),
        ]);
    }

    /**
     * Delete category if no venues are attached.
     */
    public function destroy(Category $category): JsonResponse
    {
        if ($category->venues()->exists()) {
            return response()->json([
                'message' => 'Cannot delete category that is currently assigned to venues.',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully.',
        ]);
    }
}
