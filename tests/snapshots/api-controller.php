<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ArticleController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Article::class);
        $query = Article::query()->with($this->includes($request));

        // ?filter[column]=value, or ?filter[column][]=a&filter[column][]=b. Only these columns can be filtered.
        $filters = array_intersect_key((array) $request->query('filter'), array_flip(['active']));

        foreach ($filters as $column => $value) {
            is_array($value)
                ? $query->whereIn($column, array_filter($value, 'is_scalar'))
                : $query->where($column, $value);
        }

        // ?search=text
        $search = $request->query('search');

        if (is_string($search) && $search !== '') {
            $like = "%{$search}%";
            $query->where(fn ($q) => $q->whereLike('title', $like)->orWhereLike('body', $like));
        }

        // ?sort=column (ascending) or ?sort=-column (descending). Only these columns can be sorted.
        $sort = $request->query('sort');

        if (is_string($sort) && in_array(ltrim($sort, '-'), ['id', 'created_at'], true)) {
            $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc');
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        return ArticleResource::collection($query->paginate($perPage)->withQueryString());
    }

    public function show(Request $request, Article $record)
    {
        Gate::authorize('view', $record);
        return new ArticleResource($record->load($this->includes($request)));
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Article::class);
        $record = new Article;
        $record->forceFill($this->validated($request, $record))->save();

        return new ArticleResource($record);
    }

    public function update(Request $request, Article $record)
    {
        Gate::authorize('update', $record);
        $record->forceFill($this->validated($request, $record))->save();

        return new ArticleResource($record);
    }

    public function destroy(Article $record)
    {
        Gate::authorize('delete', $record);
        Storage::disk('public')->delete(array_filter([$record->cover]));

        foreach ($record->team ?? [] as $item) {
            Storage::disk('public')->delete(array_filter([$item['image'] ?? null]));
        }

        $record->delete();

        return response()->noContent();
    }

    /** ?include=a,b. Only these relations can be loaded. */
    private function includes(Request $request): array
    {
        $include = $request->query('include');

        return is_string($include) ? array_values(array_intersect(explode(',', $include), ['author', 'comments'])) : [];
    }

    /** Validates with Article::rules() and returns only the fields the API may write. */
    private function validated(Request $request, Article $record): array
    {
        // A writable field without a rule is never validated, so it is never written.
        $rules = array_filter(
            Article::rules($record->getKey(), $request),
            fn ($field) => in_array(explode('.', $field)[0], ['title', 'cover'], true),
            ARRAY_FILTER_USE_KEY,
        );

        if ($request->isMethod('PATCH')) {
            // Partial update: a field is only validated when it was sent.
            $rules = array_map(fn ($rule) => ['sometimes', ...(is_string($rule) ? explode('|', $rule) : Arr::wrap($rule))], $rules);
        }

        $data = $request->validate($rules);

        // cover: only a real upload is stored; any other value (such as a path string) is ignored.
        if (($data['cover'] ?? null) instanceof UploadedFile) {
            if ($record->cover) {
                Storage::disk('public')->delete($record->cover);
            }

            $data['cover'] = $data['cover']->store('uploads', 'public');
        } else {
            unset($data['cover']);
        }

        return $data;
    }
}

