<?php

namespace Database\Seeders;

use App\Models\Answer;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Question;
use App\Models\Tag;
use App\Models\User;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;
use Ybazli\Faker\Facades\Faker;

class PersianDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create some users first if they don't exist
        $users = $this->createUsers();

        // Create categories
        $categories = $this->createCategories();

        // Create tags
        $tags = $this->createTags();

        // Create questions
        $questions = $this->createQuestions($users, $categories, $tags);

        // Create answers for questions
        $answers = $this->createAnswers($users, $questions);

        // Create comments for questions and answers
        $this->createComments($users, $questions, $answers);
    }

    /**
     * Create users for questions
     */
    private function createUsers(): array
    {
        $users = [];

        // Create 10 users with Persian names
        $faker = FakerFactory::create('fa_IR');

        for ($i = 0; $i < 10; $i++) {
            $user = User::create([
                'name' => Faker::fullName(),
                'email' => $faker->email(),
                'email_verified_at' => now()->toDateTimeString(),
                'mobile' => Faker::mobile(),
                'level' => rand(1, 10),
                'role' => 'user',
                'score' => rand(0, 1000),
            ]);

            $users[] = $user;
        }

        return $users;
    }

    /**
     * Create categories with Persian names
     */
    private function createCategories(): array
    {
        $categories = [];

        // Main categories
        $mainCategories = [
            'برنامه‌نویسی' => 'programming',
            'طراحی وب' => 'web-design',
            'هوش مصنوعی' => 'artificial-intelligence',
            'امنیت سایبری' => 'cybersecurity',
            'پایگاه داده' => 'database',
            'موبایل' => 'mobile',
            'بلاکچین' => 'blockchain',
            'کلود کامپیوتینگ' => 'cloud-computing',
            'دیتا ساینس' => 'data-science',
            'شبکه' => 'networking',
        ];

        foreach ($mainCategories as $name => $slug) {
            $category = Category::create([
                'name' => $name,
                'slug' => $slug,
                'parent_id' => null,
                'last_activity' => now(),
            ]);

            $categories[] = $category;

            // Create sub-categories for each main category
            $this->createSubCategories($category, $categories);
        }

        return $categories;
    }

    /**
     * Create sub-categories for a parent category
     */
    private function createSubCategories(Category $parent, array &$categories): void
    {
        $subCategories = $this->getSubCategories($parent->name);

        foreach ($subCategories as $name => $slug) {
            $category = Category::create([
                'name' => $name,
                'slug' => $slug,
                'parent_id' => $parent->id,
                'last_activity' => now(),
            ]);

            $categories[] = $category;
        }
    }

    /**
     * Get sub-categories based on parent category
     */
    private function getSubCategories(string $parentName): array
    {
        $subCategories = [
            'برنامه‌نویسی' => [
                'PHP' => 'php',
                'JavaScript' => 'javascript',
                'Python' => 'python',
                'Java' => 'java',
                'C++' => 'cpp',
                'C#' => 'csharp',
            ],
            'طراحی وب' => [
                'HTML/CSS' => 'html-css',
                'React' => 'react',
                'Vue.js' => 'vuejs',
                'Angular' => 'angular',
                'Bootstrap' => 'bootstrap',
                'Tailwind CSS' => 'tailwind-css',
            ],
            'هوش مصنوعی' => [
                'یادگیری ماشین' => 'machine-learning',
                'پردازش زبان طبیعی' => 'nlp',
                'بینایی کامپیوتر' => 'computer-vision',
                'شبکه‌های عصبی' => 'neural-networks',
                'یادگیری عمیق' => 'deep-learning',
            ],
            'امنیت سایبری' => [
                'امنیت وب' => 'web-security',
                'رمزنگاری' => 'cryptography',
                'تست نفوذ' => 'penetration-testing',
                'امنیت شبکه' => 'network-security',
                'فورنزیک دیجیتال' => 'digital-forensics',
            ],
            'پایگاه داده' => [
                'MySQL' => 'mysql',
                'PostgreSQL' => 'postgresql',
                'MongoDB' => 'mongodb',
                'Redis' => 'redis',
                'SQLite' => 'sqlite',
            ],
            'موبایل' => [
                'Android' => 'android',
                'iOS' => 'ios',
                'React Native' => 'react-native',
                'Flutter' => 'flutter',
                'Xamarin' => 'xamarin',
            ],
            'بلاکچین' => [
                'Bitcoin' => 'bitcoin',
                'Ethereum' => 'ethereum',
                'Smart Contracts' => 'smart-contracts',
                'DeFi' => 'defi',
                'NFT' => 'nft',
            ],
            'کلود کامپیوتینگ' => [
                'AWS' => 'aws',
                'Azure' => 'azure',
                'Google Cloud' => 'google-cloud',
                'Docker' => 'docker',
                'Kubernetes' => 'kubernetes',
            ],
            'دیتا ساینس' => [
                'تحلیل داده' => 'data-analysis',
                'بصری‌سازی داده' => 'data-visualization',
                'آمار' => 'statistics',
                'پایتون برای دیتا' => 'python-data',
                'R' => 'r-language',
            ],
            'شبکه' => [
                'TCP/IP' => 'tcp-ip',
                'DNS' => 'dns',
                'VPN' => 'vpn',
                'فایروال' => 'firewall',
                'روتر و سوئیچ' => 'router-switch',
            ],
        ];

        return $subCategories[$parentName] ?? [];
    }

    /**
     * Create tags with Persian names
     */
    private function createTags(): array
    {
        $tags = [];

        $tagNames = [
            'لاراول' => 'laravel',
            'ویو جی‌اس' => 'vuejs',
            'ری‌اکت' => 'react',
            'پایتون' => 'python',
            'جاواسکریپت' => 'javascript',
            'پیاچ‌پی' => 'php',
            'جاوا' => 'java',
            'سی‌پلاس‌پلاس' => 'cpp',
            'سی‌شارپ' => 'csharp',
            'گو' => 'go',
            'روبی' => 'ruby',
            'سوئیفت' => 'swift',
            'کاتلین' => 'kotlin',
            'اسکالا' => 'scala',
            'پرل' => 'perl',
            'راست' => 'rust',
            'تایپ‌اسکریپت' => 'typescript',
            'کوئری' => 'query',
            'API' => 'api',
            'REST' => 'rest',
            'GraphQL' => 'graphql',
            'میکروسرویس' => 'microservices',
            'معماری نرم‌افزار' => 'software-architecture',
            'الگوهای طراحی' => 'design-patterns',
            'تست نویسی' => 'testing',
            'CI/CD' => 'ci-cd',
            'DevOps' => 'devops',
            'Git' => 'git',
            'GitHub' => 'github',
            'GitLab' => 'gitlab',
            'Bitbucket' => 'bitbucket',
            'Jira' => 'jira',
            'Trello' => 'trello',
            'Slack' => 'slack',
            'Discord' => 'discord',
            'Telegram' => 'telegram',
            'WhatsApp' => 'whatsapp',
            'Instagram' => 'instagram',
            'Twitter' => 'twitter',
            'LinkedIn' => 'linkedin',
            'YouTube' => 'youtube',
            'Udemy' => 'udemy',
            'Coursera' => 'coursera',
            'edX' => 'edx',
            'Khan Academy' => 'khan-academy',
            'Stack Overflow' => 'stack-overflow',
            'GitHub Copilot' => 'github-copilot',
            'ChatGPT' => 'chatgpt',
            'Claude' => 'claude',
            'Bard' => 'bard',
            'DALL-E' => 'dall-e',
            'Midjourney' => 'midjourney',
            'Stable Diffusion' => 'stable-diffusion',
        ];

        foreach ($tagNames as $name => $slug) {
            $tag = Tag::create([
                'name' => $name,
                'slug' => $slug,
            ]);

            $tags[] = $tag;
        }

        return $tags;
    }

    /**
     * Create questions with Persian content
     */
    private function createQuestions(array $users, array $categories, array $tags): array
    {
        $questionTemplates = [
            'چگونه می‌توانم {topic} را یاد بگیرم؟',
            'بهترین روش برای {topic} چیست؟',
            'مشکل در {topic} - راه حل چیست؟',
            'تفاوت بین {topic1} و {topic2} چیست؟',
            'آیا {topic} ارزش یادگیری دارد؟',
            'نکات مهم در {topic} کدامند؟',
            'چگونه {topic} را بهینه کنیم؟',
            'ابزارهای مفید برای {topic} کدامند؟',
            'مثال عملی برای {topic}',
            'آموزش گام به گام {topic}',
            'مشکلات رایج در {topic} و راه‌حل‌ها',
            'بهترین منابع برای یادگیری {topic}',
            'چگونه {topic} را در پروژه‌های واقعی استفاده کنیم؟',
            'نکات امنیتی در {topic}',
            'عملکرد {topic} چگونه است؟',
        ];

        $topics = [
            'لاراول', 'ویو جی‌اس', 'ری‌اکت', 'پایتون', 'جاواسکریپت', 'پیاچ‌پی',
            'جاوا', 'سی‌پلاس‌پلاس', 'سی‌شارپ', 'گو', 'روبی', 'سوئیفت',
            'کاتلین', 'اسکالا', 'پرل', 'راست', 'تایپ‌اسکریپت', 'API',
            'REST', 'GraphQL', 'میکروسرویس', 'معماری نرم‌افزار', 'الگوهای طراحی',
            'تست نویسی', 'CI/CD', 'DevOps', 'Git', 'GitHub', 'GitLab',
            'Bitbucket', 'Jira', 'Trello', 'Slack', 'Discord', 'Telegram',
            'WhatsApp', 'Instagram', 'Twitter', 'LinkedIn', 'YouTube',
            'Udemy', 'Coursera', 'edX', 'Khan Academy', 'Stack Overflow',
            'GitHub Copilot', 'ChatGPT', 'Claude', 'Bard', 'DALL-E',
            'Midjourney', 'Stable Diffusion', 'یادگیری ماشین', 'پردازش زبان طبیعی',
            'بینایی کامپیوتر', 'شبکه‌های عصبی', 'یادگیری عمیق', 'امنیت وب',
            'رمزنگاری', 'تست نفوذ', 'امنیت شبکه', 'فورنزیک دیجیتال',
            'MySQL', 'PostgreSQL', 'MongoDB', 'Redis', 'SQLite', 'Android',
            'iOS', 'React Native', 'Flutter', 'Xamarin', 'Bitcoin', 'Ethereum',
            'Smart Contracts', 'DeFi', 'NFT', 'AWS', 'Azure', 'Google Cloud',
            'Docker', 'Kubernetes', 'تحلیل داده', 'بصری‌سازی داده', 'آمار',
            'پایتون برای دیتا', 'R', 'TCP/IP', 'DNS', 'VPN', 'فایروال',
            'روتر و سوئیچ', 'HTML/CSS', 'Bootstrap', 'Tailwind CSS',
        ];

        $questions = [];
        $faker = FakerFactory::create('fa_IR');

        // Create 10 questions for testing
        for ($i = 0; $i < 10; $i++) {
            $template = $questionTemplates[array_rand($questionTemplates)];
            $topic = $topics[array_rand($topics)];

            // Replace placeholders in template
            $title = str_replace(['{topic}', '{topic1}', '{topic2}'], [
                $topic,
                $topics[array_rand($topics)],
                $topics[array_rand($topics)],
            ], $template);

            $question = Question::create([
                'category_id' => $categories[array_rand($categories)]->id,
                'user_id' => $users[array_rand($users)]->id,
                'title' => $title,
                'slug' => Question::generateSlug($title),
                'content' => Faker::paragraph(),
                'featured' => rand(0, 10) === 0, // 10% chance to be featured
                'last_activity' => now()->subDays(rand(0, 30)),
                'views' => rand(0, 1000),
                'published' => true,
                'published_at' => now()->subDays(rand(0, 60)),
                'published_by' => $users[array_rand($users)]->id,
            ]);

            // Attach random tags to the question
            $randomTags = array_rand($tags, rand(2, 5));
            if (! is_array($randomTags)) {
                $randomTags = [$randomTags];
            }

            foreach ($randomTags as $tagIndex) {
                $question->tags()->attach($tags[$tagIndex]->id);
            }

            $questions[] = $question;
        }

        return $questions;
    }

    /**
     * Create answers for questions
     */
    private function createAnswers(array $users, array $questions): array
    {
        $answers = [];

        // Create 2-5 answers for each question
        foreach ($questions as $question) {
            $answerCount = rand(2, 5);

            for ($i = 0; $i < $answerCount; $i++) {
                $answer = Answer::create([
                    'question_id' => $question->id,
                    'user_id' => $users[array_rand($users)]->id,
                    'content' => Faker::paragraph(),
                    'published' => true,
                    'published_at' => now()->subDays(rand(0, 30)),
                    'published_by' => $users[array_rand($users)]->id,
                    'is_correct' => $i === 0 && rand(0, 3) === 0, // 25% chance for first answer to be correct
                ]);

                $answers[] = $answer;
            }
        }

        return $answers;
    }

    /**
     * Create comments for questions and answers
     */
    private function createComments(array $users, array $questions, array $answers): void
    {
        // Create comments for questions
        foreach ($questions as $question) {
            $commentCount = rand(1, 4);

            for ($i = 0; $i < $commentCount; $i++) {
                Comment::create([
                    'user_id' => $users[array_rand($users)]->id,
                    'commentable_type' => Question::class,
                    'commentable_id' => $question->id,
                    'content' => Faker::sentence(),
                    'published' => true,
                    'published_at' => now()->subDays(rand(0, 20)),
                    'published_by' => $users[array_rand($users)]->id,
                ]);
            }
        }

        // Create comments for answers
        foreach ($answers as $answer) {
            $commentCount = rand(0, 3); // Some answers might not have comments

            for ($i = 0; $i < $commentCount; $i++) {
                Comment::create([
                    'user_id' => $users[array_rand($users)]->id,
                    'commentable_type' => Answer::class,
                    'commentable_id' => $answer->id,
                    'content' => Faker::sentence(),
                    'published' => true,
                    'published_at' => now()->subDays(rand(0, 15)),
                    'published_by' => $users[array_rand($users)]->id,
                ]);
            }
        }
    }
}
