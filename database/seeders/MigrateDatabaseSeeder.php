<?php

namespace Database\Seeders;

use App\Models\Answer;
use App\Models\Category;
use App\Models\Question;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateDatabaseSeeder extends Seeder
{
    private string $wpPrefix = 'dfdf_';

    private string $wpConn = 'wordpress';

    private string $targetConn = 'app';

    private int $chunkSize = 1000;

    public function run(): void
    {
        // Route all writes to the target app database connection
        DB::setDefaultConnection($this->targetConn);
        DB::connection($this->wpConn)->disableQueryLog();
        DB::disableQueryLog();

        $this->seedUsers();
        $this->seedCategories();
        $this->seedTags();
        $this->seedQuestions();
        $this->seedQuestionTags();
        $this->seedAnswers();
        $this->seedComments();
        $this->seedVotes();
        $this->backfillCategoryLastActivity();
    }

    private function seedUsers(): void
    {
        $usersTable = $this->wpPrefix.'users';

        // Precompute reputation scores per user
        $scores = DB::connection($this->wpConn)
            ->table($this->wpPrefix.'ap_reputations as r')
            ->join($this->wpPrefix.'ap_reputation_events as e', 'e.slug', '=', 'r.rep_event')
            ->select('r.rep_user_id', DB::raw('SUM(e.points) as score'))
            ->groupBy('r.rep_user_id')
            ->pluck('score', 'rep_user_id');

        $lastId = 0;
        while (true) {
            $rows = DB::connection($this->wpConn)
                ->table($usersTable)
                ->where('ID', '>', $lastId)
                ->orderBy('ID')
                ->limit($this->chunkSize)
                ->get(['ID', 'user_login', 'user_email', 'display_name', 'user_registered']);

            if ($rows->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($rows, $scores) {
                foreach ($rows as $row) {
                    $email = $this->sanitizeEmail($row->user_email, (int) $row->ID);
                    $name = $row->display_name ?: $row->user_login ?: ('user_'.$row->ID);
                    $user = User::query()->firstOrCreate(
                        ['email' => $email],
                        [
                            'name' => $name,
                            'level' => 1,
                            'score' => (int) ($scores[$row->ID] ?? 0),
                            'role' => 'user',
                            'created_at' => $row->user_registered,
                            'updated_at' => $row->user_registered,
                        ]
                    );

                    DB::table('wp_to_laravel_users')
                        ->updateOrInsert(
                            ['wp_user_id' => $row->ID],
                            ['laravel_user_id' => $user->id, 'updated_at' => now(), 'created_at' => now()]
                        );
                }
            });

            $lastId = (int) $rows->last()->ID;
        }
    }

    private function seedCategories(): void
    {
        $terms = $this->wpPrefix.'terms';
        $tax = $this->wpPrefix.'term_taxonomy';

        $source = DB::connection($this->wpConn)
            ->table($terms.' as t')
            ->join($tax.' as tt', 'tt.term_id', '=', 't.term_id')
            ->leftJoin($tax.' as ttp', 'ttp.term_taxonomy_id', '=', 'tt.parent')
            ->where('tt.taxonomy', 'question_category')
            ->orderBy('t.term_id')
            ->get(['t.term_id', 't.name', 't.slug', 'tt.term_taxonomy_id', 'tt.parent', DB::raw('COALESCE(ttp.term_id, 0) as parent_term_id')]);

        DB::transaction(function () use ($source) {
            foreach ($source as $row) {
                $normalizedSlug = $this->normalizeSlug((string) $row->slug);
                $uniqueSlug = $this->ensureUniqueSlug('categories', $normalizedSlug);
                $category = Category::query()->firstOrCreate(
                    ['slug' => $uniqueSlug],
                    ['name' => $row->name]
                );

                DB::table('wp_to_laravel_terms')
                    ->updateOrInsert(
                        ['wp_term_id' => $row->term_id],
                        ['laravel_id' => $category->id, 'kind' => 'category', 'updated_at' => now(), 'created_at' => now()]
                    );
            }

            foreach ($source as $row) {
                if ((int) $row->parent_term_id === 0) {
                    continue;
                }
                $parentMap = DB::table('wp_to_laravel_terms')
                    ->where(['wp_term_id' => $row->parent_term_id, 'kind' => 'category'])
                    ->value('laravel_id');
                $childMap = DB::table('wp_to_laravel_terms')
                    ->where(['wp_term_id' => $row->term_id, 'kind' => 'category'])
                    ->value('laravel_id');
                if ($parentMap && $childMap) {
                    Category::query()->where('id', $childMap)->update(['parent_id' => $parentMap]);
                }
            }
        });
    }

    private function seedTags(): void
    {
        $terms = $this->wpPrefix.'terms';
        $tax = $this->wpPrefix.'term_taxonomy';

        $lastId = 0;
        while (true) {
            $rows = DB::connection($this->wpConn)
                ->table($terms.' as t')
                ->join($tax.' as tt', 'tt.term_id', '=', 't.term_id')
                ->where('tt.taxonomy', 'question_tag')
                ->where('t.term_id', '>', $lastId)
                ->orderBy('t.term_id')
                ->limit($this->chunkSize)
                ->get(['t.term_id', 't.name', 't.slug']);

            if ($rows->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($rows) {
                foreach ($rows as $row) {
                    $normalizedSlug = $this->normalizeSlug((string) $row->slug);
                    $uniqueSlug = $this->ensureUniqueSlug('tags', $normalizedSlug);
                    $tag = Tag::query()->firstOrCreate(
                        ['slug' => $uniqueSlug],
                        ['name' => $row->name]
                    );

                    DB::table('wp_to_laravel_terms')
                        ->updateOrInsert(
                            ['wp_term_id' => $row->term_id],
                            ['laravel_id' => $tag->id, 'kind' => 'tag', 'updated_at' => now(), 'created_at' => now()]
                        );
                }
            });

            $lastId = (int) $rows->last()->term_id;
        }
    }

    private function seedQuestions(): void
    {
        $posts = $this->wpPrefix.'posts';
        $aq = $this->wpPrefix.'ap_qameta';

        $lastId = 0;
        while (true) {
            $rows = DB::connection($this->wpConn)
                ->table($posts.' as p')
                ->leftJoin($aq.' as aq', 'aq.post_id', '=', 'p.ID')
                ->where('p.post_type', 'question')
                ->where('p.ID', '>', $lastId)
                ->orderBy('p.ID')
                ->limit($this->chunkSize)
                ->get([
                    'p.ID', 'p.post_author', 'p.post_title', 'p.post_content', 'p.post_status', 'p.post_date', 'p.post_modified', 'p.post_name',
                    'aq.featured', 'aq.views', 'aq.last_updated',
                ]);

            if ($rows->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($rows) {
                $userMap = $this->simpleUserMap($rows->pluck('post_author')->all());
                foreach ($rows as $row) {
                    if (DB::table('wp_to_laravel_posts')->where(['wp_post_id' => $row->ID, 'kind' => 'question'])->exists()) {
                        continue;
                    }
                    // If author is 0, use null and do not create a user
                    $laravelUserId = $userMap[$row->post_author] ?? null;
                    if ((int) $row->post_author === 0) {
                        $laravelUserId = null;
                    } elseif (! $laravelUserId) {
                        // Create or reuse a guest/migrated user and map for this author
                        $guestEmail = $this->sanitizeEmail('user_'.(int) $row->post_author.'@example.invalid', (int) $row->post_author);
                        $guestName = 'Guest';
                        $laravelUserId = $this->guestUserIdForEmail($guestEmail, $guestName);
                        if ((int) $row->post_author > 0) {
                            DB::table('wp_to_laravel_users')->updateOrInsert(
                                ['wp_user_id' => (int) $row->post_author],
                                ['laravel_user_id' => $laravelUserId, 'updated_at' => now(), 'created_at' => now()]
                            );
                        }
                    }
                    $categoryId = $this->firstCategoryIdForPost((int) $row->ID);
                    if (! $categoryId) {
                        $categoryId = Category::query()->firstOrCreate(['slug' => 'uncategorized'], ['name' => 'Uncategorized'])->id;
                    }

                    $published = $row->post_status === 'publish';

                    $questionId = DB::table('questions')->insertGetId([
                        'category_id' => $categoryId,
                        'user_id' => $laravelUserId,
                        'title' => $row->post_title,
                        // Map WP guid to slug per requirement
                        'slug' => $this->uniqueQuestionSlug($this->slugFromGuid((string) $row->post_name)),
                        'content' => $row->post_content,
                        'featured' => (bool) $row->featured,
                        'last_activity' => $row->last_updated ?: $row->post_modified,
                        'views' => (int) ($row->views ?? 0),
                        'published' => $published,
                        'published_at' => $published ? $row->post_date : null,
                        'published_by' => $published ? $laravelUserId : null,
                        'created_at' => $row->post_date,
                        'updated_at' => $row->post_modified,
                    ]);

                    DB::table('wp_to_laravel_posts')
                        ->updateOrInsert(
                            ['wp_post_id' => $row->ID],
                            ['laravel_id' => $questionId, 'kind' => 'question', 'updated_at' => now(), 'created_at' => now()]
                        );
                }
            });

            $lastId = (int) $rows->last()->ID;
        }
    }

    private function seedQuestionTags(): void
    {
        $posts = $this->wpPrefix.'posts';
        $rel = $this->wpPrefix.'term_relationships';
        $tax = $this->wpPrefix.'term_taxonomy';

        $lastId = 0;
        while (true) {
            $postIds = DB::connection($this->wpConn)
                ->table($posts)
                ->where('post_type', 'question')
                ->where('ID', '>', $lastId)
                ->orderBy('ID')
                ->limit($this->chunkSize)
                ->pluck('ID');

            if ($postIds->isEmpty()) {
                break;
            }

            $rels = DB::connection($this->wpConn)
                ->table($rel.' as tr')
                ->join($tax.' as tt', 'tt.term_taxonomy_id', '=', 'tr.term_taxonomy_id')
                ->whereIn('tr.object_id', $postIds)
                ->where('tt.taxonomy', 'question_tag')
                ->get(['tr.object_id', 'tt.term_id']);

            DB::transaction(function () use ($rels) {
                foreach ($rels as $r) {
                    $qId = DB::table('wp_to_laravel_posts')->where(['wp_post_id' => $r->object_id, 'kind' => 'question'])->value('laravel_id');
                    $tId = DB::table('wp_to_laravel_terms')->where(['wp_term_id' => $r->term_id, 'kind' => 'tag'])->value('laravel_id');
                    if ($qId && $tId) {
                        DB::table('question_tag')->updateOrInsert(
                            ['tag_id' => $tId, 'question_id' => $qId],
                            ['created_at' => now(), 'updated_at' => now()]
                        );
                    }
                }
            });

            $lastId = (int) $postIds->last();
        }
    }

    private function seedAnswers(): void
    {
        $posts = $this->wpPrefix.'posts';
        $aq = $this->wpPrefix.'ap_qameta';

        $lastId = 0;
        while (true) {
            $rows = DB::connection($this->wpConn)
                ->table($posts.' as p')
                ->leftJoin($aq.' as aq', 'aq.post_id', '=', 'p.post_parent')
                ->where('p.post_type', 'answer')
                ->where('p.ID', '>', $lastId)
                ->orderBy('p.ID')
                ->limit($this->chunkSize)
                ->get([
                    'p.ID', 'p.post_parent', 'p.post_author', 'p.post_content', 'p.post_status', 'p.post_date', 'p.post_modified',
                    'aq.selected_id',
                ]);

            if ($rows->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($rows) {
                $authorIds = $rows->pluck('post_author')->all();
                $userMap = $this->simpleUserMap($authorIds);
                foreach ($rows as $row) {
                    if (DB::table('wp_to_laravel_posts')->where(['wp_post_id' => $row->ID, 'kind' => 'answer'])->exists()) {
                        continue;
                    }
                    $questionId = DB::table('wp_to_laravel_posts')->where(['wp_post_id' => $row->post_parent, 'kind' => 'question'])->value('laravel_id');
                    if (! $questionId) {
                        continue;
                    }
                    $laravelUserId = $userMap[$row->post_author] ?? null;
                    if (! $laravelUserId) {
                        $guestEmail = $this->sanitizeEmail('user_'.(int) $row->post_author.'@example.invalid', (int) $row->post_author);
                        $guestName = 'Guest';
                        $laravelUserId = $this->guestUserIdForEmail($guestEmail, $guestName);
                        if ((int) $row->post_author > 0) {
                            DB::table('wp_to_laravel_users')->updateOrInsert(
                                ['wp_user_id' => (int) $row->post_author],
                                ['laravel_user_id' => $laravelUserId, 'updated_at' => now(), 'created_at' => now()]
                            );
                        }
                    }
                    $published = $row->post_status === 'publish';
                    $answerId = DB::table('answers')->insertGetId([
                        'question_id' => $questionId,
                        'user_id' => $laravelUserId,
                        'content' => $row->post_content,
                        'published' => $published,
                        'published_at' => $published ? $row->post_date : null,
                        'published_by' => $published ? $laravelUserId : null,
                        'is_correct' => (int) $row->ID === (int) ($row->selected_id ?? 0),
                        'created_at' => $row->post_date,
                        'updated_at' => $row->post_modified,
                    ]);

                    DB::table('wp_to_laravel_posts')
                        ->updateOrInsert(
                            ['wp_post_id' => $row->ID],
                            ['laravel_id' => $answerId, 'kind' => 'answer', 'updated_at' => now(), 'created_at' => now()]
                        );
                }
            });

            $lastId = (int) $rows->last()->ID;
        }
    }

    private function seedComments(): void
    {
        $comments = $this->wpPrefix.'comments';
        $posts = $this->wpPrefix.'posts';

        $lastId = 0;
        while (true) {
            $rows = DB::connection($this->wpConn)
                ->table($comments.' as c')
                ->join($posts.' as p', 'p.ID', '=', 'c.comment_post_ID')
                ->whereIn('p.post_type', ['question', 'answer'])
                ->where('c.comment_ID', '>', $lastId)
                ->orderBy('c.comment_ID')
                ->limit($this->chunkSize)
                ->get([
                    'c.comment_ID', 'c.comment_post_ID', 'c.user_id', 'c.comment_author', 'c.comment_author_email', 'c.comment_content', 'c.comment_approved', 'c.comment_date', 'c.comment_date_gmt',
                    'p.post_type',
                ]);

            if ($rows->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($rows) {
                $userMap = $this->simpleUserMap($rows->pluck('user_id')->all());
                foreach ($rows as $row) {
                    if (DB::table('wp_to_laravel_comments')->where('wp_comment_id', $row->comment_ID)->exists()) {
                        continue;
                    }
                    $published = in_array((string) $row->comment_approved, ['1', 'approve'], true);
                    $votableKind = $row->post_type === 'question' ? 'question' : 'answer';
                    $laravelCommentableId = DB::table('wp_to_laravel_posts')->where(['wp_post_id' => $row->comment_post_ID, 'kind' => $votableKind])->value('laravel_id');
                    if (! $laravelCommentableId) {
                        continue;
                    }
                    // Resolve user: WP user or create guest
                    $userId = $userMap[$row->user_id] ?? null;
                    if (! $userId) {
                        $guestEmail = $this->sanitizeEmail($row->comment_author_email, (int) $row->comment_ID);
                        $guestName = trim((string) $row->comment_author) ?: 'Guest';
                        $userId = $this->guestUserIdForEmail($guestEmail, $guestName);
                    }
                    $commentId = DB::table('comments')->insertGetId([
                        'user_id' => $userId,
                        'commentable_type' => $votableKind === 'question' ? Question::class : Answer::class,
                        'commentable_id' => $laravelCommentableId,
                        'content' => $row->comment_content,
                        'published' => $published,
                        'published_at' => $published ? $row->comment_date : null,
                        'published_by' => null,
                        'created_at' => $row->comment_date,
                        'updated_at' => $row->comment_date_gmt,
                    ]);
                    DB::table('wp_to_laravel_comments')
                        ->updateOrInsert(
                            ['wp_comment_id' => $row->comment_ID],
                            ['laravel_comment_id' => $commentId, 'updated_at' => now(), 'created_at' => now()]
                        );
                }
            });

            $lastId = (int) $rows->last()->comment_ID;
        }
    }

    private function seedVotes(): void
    {
        $votes = $this->wpPrefix.'ap_votes';
        $posts = $this->wpPrefix.'posts';

        $lastId = 0;
        while (true) {
            $rows = DB::connection($this->wpConn)
                ->table($votes.' as v')
                ->join($posts.' as p', 'p.ID', '=', 'v.vote_post_id')
                ->where('v.vote_id', '>', $lastId)
                ->where('v.vote_type', 'vote')
                ->whereIn('p.post_type', ['question', 'answer'])
                ->orderBy('v.vote_id')
                ->limit($this->chunkSize)
                ->get(['v.vote_id', 'v.vote_post_id', 'v.vote_user_id', 'v.vote_value', 'v.vote_date', 'p.post_type']);

            if ($rows->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($rows) {
                $userMap = $this->simpleUserMap($rows->pluck('vote_user_id')->all());
                foreach ($rows as $row) {
                    $kind = $row->post_type === 'question' ? 'question' : 'answer';
                    $laravelId = DB::table('wp_to_laravel_posts')->where(['wp_post_id' => $row->vote_post_id, 'kind' => $kind])->value('laravel_id');
                    if (! $laravelId) {
                        continue;
                    }
                    $type = ((int) $row->vote_value) === 1 ? 'up' : 'down';
                    $userId = $userMap[$row->vote_user_id] ?? $this->guestUserIdForEmail('user_'.(int) $row->vote_user_id.'@example.invalid', 'Guest');
                    $votableType = $kind === 'question' ? Question::class : Answer::class;

                    // Upsert to respect unique constraint: one vote per user per votable
                    $voteRow = [
                        'user_id' => $userId,
                        'votable_type' => $votableType,
                        'votable_id' => $laravelId,
                        'type' => $type,
                        'last_voted_at' => $row->vote_date,
                        'created_at' => $row->vote_date,
                        'updated_at' => $row->vote_date,
                    ];
                    DB::table('votes')->upsert(
                        [$voteRow],
                        ['user_id', 'votable_type', 'votable_id'],
                        ['type', 'last_voted_at', 'updated_at']
                    );
                }
            });

            $lastId = (int) $rows->last()->vote_id;
        }
    }

    private function backfillCategoryLastActivity(): void
    {
        DB::table('categories')
            ->orderBy('id')
            ->chunkById($this->chunkSize, function ($categories) {
                $categoryIds = $categories->pluck('id')->all();
                if (empty($categoryIds)) {
                    return false;
                }

                $lastActivities = DB::table('questions')
                    ->select('category_id', DB::raw('MAX(last_activity) as last_activity'))
                    ->whereIn('category_id', $categoryIds)
                    ->groupBy('category_id')
                    ->get()
                    ->keyBy('category_id');

                foreach ($categories as $category) {
                    $last = optional($lastActivities->get($category->id))->last_activity;
                    DB::table('categories')->where('id', $category->id)->update(['last_activity' => $last]);
                }
            });
    }

    private function sanitizeEmail(?string $email, int $wpUserId): string
    {
        $email = trim((string) $email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'user_'.$wpUserId.'@example.invalid';
        }

        return $email;
    }

    private function uniqueQuestionSlug(string $guid): string
    {
        $slug = trim($guid);
        if ($slug === '') {
            $slug = Str::uuid()->toString();
        }
        // Normalize decoded slug
        $slug = $this->normalizeSlug($slug);
        $base = $slug;
        $i = 1;
        while (DB::table('questions')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    private function slugFromGuid(string $guid): string
    {
        $guid = trim($guid);
        if ($guid === '') {
            return Str::uuid()->toString();
        }
        // Take the last path segment of the URL (strip query/fragments)
        $noQuery = strtok($guid, '?#');
        $noQuery = rtrim($noQuery, '/');
        $lastSlash = strrpos($noQuery, '/');
        $last = $lastSlash !== false ? substr($noQuery, $lastSlash + 1) : $noQuery;
        // Decode percent-encoded (including double-encoded) and normalize
        $decoded = $this->deepUrlDecode($last);
        $normalized = $this->normalizeSlug($decoded);

        return $normalized !== '' ? $normalized : Str::uuid()->toString();
    }

    private function deepUrlDecode(string $value): string
    {
        $prev = $value;
        for ($i = 0; $i < 3; $i++) {
            $curr = rawurldecode($prev);
            if ($curr === $prev) {
                break;
            }
            $prev = $curr;
        }

        return $prev;
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = trim($slug);
        $slug = $this->deepUrlDecode($slug);
        // Replace spaces with hyphens
        $slug = preg_replace('/\s+/u', '-', $slug);
        // Collapse multiple hyphens
        $slug = preg_replace('/-+/u', '-', $slug);
        // Trim hyphens
        $slug = trim($slug, '-');

        return $slug ?? '';
    }

    private function ensureUniqueSlug(string $table, string $baseSlug): string
    {
        $slug = $baseSlug !== '' ? $baseSlug : Str::uuid()->toString();
        $i = 1;
        while (DB::table($table)->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$i;
            $i++;
        }

        return $slug;
    }

    private function simpleUserMap(array $wpUserIds): array
    {
        $wpUserIds = array_values(array_unique(array_map('intval', $wpUserIds)));
        if (empty($wpUserIds)) {
            return [];
        }

        return DB::table('wp_to_laravel_users')
            ->whereIn('wp_user_id', $wpUserIds)
            ->pluck('laravel_user_id', 'wp_user_id')
            ->toArray();
    }

    private function firstCategoryIdForPost(int $wpPostId): ?int
    {
        $rel = $this->wpPrefix.'term_relationships';
        $tax = $this->wpPrefix.'term_taxonomy';

        $termId = DB::connection($this->wpConn)
            ->table($rel.' as tr')
            ->join($tax.' as tt', 'tt.term_taxonomy_id', '=', 'tr.term_taxonomy_id')
            ->where('tr.object_id', $wpPostId)
            ->where('tt.taxonomy', 'question_category')
            ->orderBy('tr.term_taxonomy_id')
            ->limit(1)
            ->value('tt.term_id');

        if (! $termId) {
            return null;
        }

        return DB::table('wp_to_laravel_terms')->where(['wp_term_id' => $termId, 'kind' => 'category'])->value('laravel_id');
    }

    private function guestUserIdForEmail(string $email, string $name): int
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'level' => 1,
                'role' => 'user',
                'score' => 0,
            ]
        );

        // Map artificial wp_user_id of 0 or negative emails are not tracked in map table.
        return $user->id;
    }
}
