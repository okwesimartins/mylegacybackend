<?php


namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Plan extends Model
{
    protected $fillable = ['slug','name','features','limits','stripe_prices'];

    protected $casts = [
        'features' => 'array',
        'limits' => 'array',
        'stripe_prices' => 'array',
    ];

    public static function free(): self
    {
        return static::where('slug', 'free')->firstOrFail();
    }

    public static function premium(): self
    {
        return static::where('slug', 'premium')->firstOrFail();
    }

    public function hasFeature(string $key): bool
    {
        return (bool) Arr::get($this->features ?? [], $key, false);
    }

    public function limit(string $key, $default = null)
    {
        return Arr::get($this->limits ?? [], $key, $default);
    }

    // ✅ Function user asked for: feature list (for frontend)
    public static function featureMatrix(): array
    {
        $plans = static::whereIn('slug', ['free','premium'])->get()->keyBy('slug');

        $allFeatures = [
            ['key' => 'voice_to_text', 'label' => 'Voice-to-text journaling'],
            ['key' => 'entries.unlimited', 'label' => 'Unlimited journal entries'],
            ['key' => 'journals.unlimited', 'label' => 'Unlimited personalized journals'],
            ['key' => 'affirmations', 'label' => 'Well-being & Legacy affirmations'],
            ['key' => 'handover', 'label' => 'Next of Kin automated handover'],
            ['key' => 'export.pdf', 'label' => 'Export journals as PDF'],
            ['key' => 'backup.encryption', 'label' => 'Secure cloud backup and end-to-end encryption'],

            ['key' => 'audio.recording', 'label' => 'Audio recording and transcription'],
            ['key' => 'attachments.images', 'label' => 'Unlimited images'],
            ['key' => 'attachments.videos', 'label' => 'Videos'],
            ['key' => 'next_of_kin.multiple', 'label' => 'Multiple next of kin automated handovers'],
            ['key' => 'ai.chatbot', 'label' => 'AI well-being chatbot'],
        ];

        $mapPlan = function (?Plan $plan) use ($allFeatures) {
            if (!$plan) return [];
            return [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'limits' => $plan->limits ?? [],
                'features' => collect($allFeatures)->map(function ($f) use ($plan) {
                    return [
                        'key' => $f['key'],
                        'label' => $f['label'],
                        'enabled' => $plan->hasFeature($f['key']),
                    ];
                })->values()->all(),
            ];
        };

        return [
            'free' => $mapPlan($plans['free'] ?? null),
            'premium' => $mapPlan($plans['premium'] ?? null),
        ];
    }
}