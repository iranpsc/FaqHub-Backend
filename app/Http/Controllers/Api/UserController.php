<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Rules\SecureImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Get user statistics
     */
    public function stats(Request $request)
    {
        $user = $request->user();

        $stats = [
            'questionsCount' => $user->questions()->published()->count(),
            'answersCount' => $user->answers()->published()->count(),
            'commentsCount' => $user->comments()->published()->count(),
        ];

        return response()->json($stats);
    }

    /**
     * Get user recent activity
     */
    public function activity(Request $request)
    {
        $user = $request->user();
        $activities = collect();

        // Get recent questions
        $questions = $user->questions()
            ->published()
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($question) {
                return [
                    'id' => 'question_'.$question->id,
                    'type' => 'question',
                    'description' => 'سوال جدید: '.$question->title,
                    'created_at' => $question->created_at,
                    'question_slug' => $question->slug,
                ];
            });

        // Get recent answers
        $answers = $user->answers()
            ->published()
            ->with('question')
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($answer) {
                return [
                    'id' => 'answer_'.$answer->id,
                    'type' => 'answer',
                    'description' => 'پاسخ جدید به: '.$answer->question->title,
                    'created_at' => $answer->created_at,
                    'question_slug' => $answer->question->slug,
                ];
            });

        // Get recent comments
        $comments = $user->comments()
            ->published()
            ->with(['commentable' => function ($morphTo) {
                $morphTo->morphWith([
                    'App\Models\Question' => [],
                    'App\Models\Answer' => ['question:id,title,slug'],
                ]);
            }])
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($comment) {
                $title = '';
                $questionSlug = null;
                if ($comment->commentable_type === 'App\Models\Question' && $comment->commentable) {
                    $title = $comment->commentable->title;
                    $questionSlug = $comment->commentable->slug;
                } elseif ($comment->commentable_type === 'App\Models\Answer' && $comment->commentable && $comment->commentable->question) {
                    $title = $comment->commentable->question->title;
                    $questionSlug = $comment->commentable->question->slug;
                }

                return [
                    'id' => 'comment_'.$comment->id,
                    'type' => 'comment',
                    'description' => 'دیدگاه جدید در: '.$title,
                    'created_at' => $comment->created_at,
                    'question_slug' => $questionSlug,
                ];
            });

        // Merge and sort activities
        $activities = $activities
            ->merge($questions)
            ->merge($answers)
            ->merge($comments)
            ->sortByDesc('created_at')
            ->take(10)
            ->values();

        return response()->json($activities);
    }

    /**
     * Update user profile image
     */
    public function updateImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image' => ['required', 'file', new SecureImage(['image/jpeg', 'image/png', 'image/webp']), 'max:1024'], // Max 1MB
        ], [
            'image.required' => 'لطفا یک تصویر انتخاب کنید',
            'image.file' => 'فایل انتخابی باید یک فایل معتبر باشد',
            'image.max' => 'حجم تصویر نباید بیشتر از 2 مگابایت باشد',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'خطا در اعتبارسنجی',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        try {
            // Delete old image if exists
            if ($user->image && Storage::disk('public')->exists($user->image)) {
                Storage::disk('public')->delete($user->image);
            }

            // Process and store new image
            $image = $request->file('image');
            $filename = 'avatars/'.$user->id.'_'.time().'.'.$image->getClientOriginalExtension();

            // Store the image
            $path = $image->storeAs('avatars', $user->id.'_'.time().'.'.$image->getClientOriginalExtension(), 'public');

            // Update user record
            $user->update(['image' => $path]);

            return response()->json([
                'message' => 'تصویر پروفایل با موفقیت بروزرسانی شد',
                'image_url' => asset('storage/'.$path),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'خطا در بروزرسانی تصویر پروفایل',
            ], 500);
        }
    }

    /**
     * Get user profile data
     */
    public function profile(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'image' => $user->image_url,
            'score' => $user->score ?? 0,
            'online' => $user->online ?? false,
            'login_notification_enabled' => $user->login_notification_enabled ?? false,
            'created_at' => $user->created_at,
        ]);
    }

    /**
     * Update user settings
     */
    public function updateSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'login_notification_enabled' => 'boolean',
        ], [
            'login_notification_enabled.boolean' => 'مقدار تنظیمات ورود باید درست یا نادرست باشد',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'خطا در اعتبارسنجی',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        $user->update([
            'login_notification_enabled' => $request->boolean('login_notification_enabled', false),
        ]);

        return response()->json([
            'message' => 'تنظیمات با موفقیت بروزرسانی شد',
            'login_notification_enabled' => $user->login_notification_enabled,
        ]);
    }
}
