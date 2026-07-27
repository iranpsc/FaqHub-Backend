<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Check if an index exists on a table
     */
    private function indexExists(string $table, string $indexName): bool
    {
        return Schema::hasIndex($table, $indexName);
    }

    /**
     * Run the migrations.
     * These indexes optimize the question listing and dashboard queries.
     * Only adds indexes that don't already exist.
     */
    public function up(): void
    {
        // Index for questions table - optimizes filtering and sorting
        Schema::table('questions', function (Blueprint $table) {
            // Index for category filtering with published status
            if (! $this->indexExists('questions', 'questions_category_published_idx')) {
                $table->index(['category_id', 'published'], 'questions_category_published_idx');
            }
        });

        // Index for answers table - optimizes is_solved check and counts
        Schema::table('answers', function (Blueprint $table) {
            if (! $this->indexExists('answers', 'answers_question_solved_idx')) {
                $table->index(['question_id', 'is_correct'], 'answers_question_solved_idx');
            }
            if (! $this->indexExists('answers', 'answers_question_published_idx')) {
                $table->index(['question_id', 'published'], 'answers_question_published_idx');
            }
            if (! $this->indexExists('answers', 'answers_user_idx')) {
                $table->index('user_id', 'answers_user_idx');
            }
            if (! $this->indexExists('answers', 'answers_published_idx')) {
                $table->index('published', 'answers_published_idx');
            }
        });

        // Index for votes table - optimizes user_vote lookup and counts
        Schema::table('votes', function (Blueprint $table) {
            if (! $this->indexExists('votes', 'votes_votable_user_idx')) {
                $table->index(['votable_type', 'votable_id', 'user_id'], 'votes_votable_user_idx');
            }
            if (! $this->indexExists('votes', 'votes_votable_idx')) {
                $table->index(['votable_type', 'votable_id'], 'votes_votable_idx');
            }
        });

        // Index for comments table - optimizes unpublished count
        Schema::table('comments', function (Blueprint $table) {
            if (! $this->indexExists('comments', 'comments_commentable_published_idx')) {
                $table->index(['commentable_type', 'commentable_id', 'published'], 'comments_commentable_published_idx');
            }
            if (! $this->indexExists('comments', 'comments_user_idx')) {
                $table->index('user_id', 'comments_user_idx');
            }
        });

        // Index for user_pinned_questions - optimizes pin status lookup
        Schema::table('user_pinned_questions', function (Blueprint $table) {
            if (! $this->indexExists('user_pinned_questions', 'pinned_question_user_idx')) {
                $table->index(['question_id', 'user_id'], 'pinned_question_user_idx');
            }
        });

        // Index for user_featured_questions - optimizes feature status lookup
        Schema::table('user_featured_questions', function (Blueprint $table) {
            if (! $this->indexExists('user_featured_questions', 'featured_question_user_idx')) {
                $table->index(['question_id', 'user_id'], 'featured_question_user_idx');
            }
        });

        // Index for question_tag pivot table
        Schema::table('question_tag', function (Blueprint $table) {
            if (! $this->indexExists('question_tag', 'question_tag_tag_question_idx')) {
                $table->index(['tag_id', 'question_id'], 'question_tag_tag_question_idx');
            }
        });

        // Index for users table - optimizes active users query
        Schema::table('users', function (Blueprint $table) {
            if (! $this->indexExists('users', 'users_score_idx')) {
                $table->index('score', 'users_score_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            if ($this->indexExists('questions', 'questions_category_published_idx')) {
                $table->dropIndex('questions_category_published_idx');
            }
        });

        Schema::table('answers', function (Blueprint $table) {
            if ($this->indexExists('answers', 'answers_question_solved_idx')) {
                $table->dropIndex('answers_question_solved_idx');
            }
            if ($this->indexExists('answers', 'answers_question_published_idx')) {
                $table->dropIndex('answers_question_published_idx');
            }
            if ($this->indexExists('answers', 'answers_user_idx')) {
                $table->dropIndex('answers_user_idx');
            }
            if ($this->indexExists('answers', 'answers_published_idx')) {
                $table->dropIndex('answers_published_idx');
            }
        });

        Schema::table('votes', function (Blueprint $table) {
            if ($this->indexExists('votes', 'votes_votable_user_idx')) {
                $table->dropIndex('votes_votable_user_idx');
            }
            if ($this->indexExists('votes', 'votes_votable_idx')) {
                $table->dropIndex('votes_votable_idx');
            }
        });

        Schema::table('comments', function (Blueprint $table) {
            if ($this->indexExists('comments', 'comments_commentable_published_idx')) {
                $table->dropIndex('comments_commentable_published_idx');
            }
            if ($this->indexExists('comments', 'comments_user_idx')) {
                $table->dropIndex('comments_user_idx');
            }
        });

        Schema::table('user_pinned_questions', function (Blueprint $table) {
            if ($this->indexExists('user_pinned_questions', 'pinned_question_user_idx')) {
                $table->dropIndex('pinned_question_user_idx');
            }
        });

        Schema::table('user_featured_questions', function (Blueprint $table) {
            if ($this->indexExists('user_featured_questions', 'featured_question_user_idx')) {
                $table->dropIndex('featured_question_user_idx');
            }
        });

        Schema::table('question_tag', function (Blueprint $table) {
            if ($this->indexExists('question_tag', 'question_tag_tag_question_idx')) {
                $table->dropIndex('question_tag_tag_question_idx');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if ($this->indexExists('users', 'users_score_idx')) {
                $table->dropIndex('users_score_idx');
            }
        });
    }
};
