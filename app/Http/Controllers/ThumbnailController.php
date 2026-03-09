<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ThumbnailService;

class ThumbnailController extends Controller
{
    public function index()
    {
        return view('creator');
    }

    /**
     * Build a detailed YouTube-thumbnail prompt purely from title + context,
     * without needing any Gemini API call. Used as fallback when AI prompt fails.
     */
    private function buildSmartFallbackPrompt(string $title, string $context): string
    {
        return "Create a stunning, professional YouTube thumbnail image (16:9 ratio, 1280x720 pixels). "
            . "TITLE TEXT (mandatory, large bold text on left side): \"{$title}\" — render in ultra-bright yellow (#FFD700) with thick black outline/shadow, bold sans-serif font, takes up 60% of the left side. "
            . "RIGHT SIDE: a photorealistic, dramatic human figure or thematic object directly related to the topic: \"{$context}\", confident pose, vibrant lighting. "
            . "BACKGROUND: bold, cinematic, dark gradient background with neon glowing accents and visual symbols strongly related to \"{$context}\" (e.g., for stock/finance topics: green stock charts, rupee/dollar symbols, upward arrows; for tech: circuit boards, blue neon; for travel: scenic landscapes). "
            . "STYLE: high contrast, vibrant colors (deep purple/blue/black background with neon gold and green accents), dramatic lighting, professional YouTube CTR-optimized design. "
            . "NO watermarks. NO extra text besides the title. Cinematic quality. Hyper-realistic.";
    }

    public function generate(Request $request, ThumbnailService $service)
    {
        // Both images are OPTIONAL
        $request->validate([
            'title'   => 'required|string|max:200',
            'context' => 'required|string|max:1000',
            'image1'  => 'nullable|image|mimes:jpeg,png,jpg|max:10240',
            'image2'  => 'nullable|image|mimes:jpeg,png,jpg|max:10240',
        ]);

        $title   = trim($request->title);
        $context = trim($request->context ?? '');

        $image1Path = $request->hasFile('image1') ? $request->file('image1')->path() : null;
        $image2Path = $request->hasFile('image2') ? $request->file('image2')->path() : null;

        $hasFaceImage  = !empty($image1Path) && file_exists($image1Path);
        $hasBgImage    = !empty($image2Path) && file_exists($image2Path);
        $hasBothImages = $hasFaceImage && $hasBgImage;

        // ──────────────────────────────────────────────────────────────
        // Step 1: Build Master Prompt
        //   START with our own smart detailed prompt (never a blank string).
        //   If Gemini succeeds → replace with its richer version.
        //   If Gemini fails → smart fallback is already set, continue.
        // ──────────────────────────────────────────────────────────────
        $finalPrompt = $this->buildSmartFallbackPrompt($title, $context);

        try {
            $desc1 = null;
            $desc2 = null;

            if ($hasFaceImage) {
                $desc1 = $service->analyzeImage($image1Path, 'face');
            }
            if ($hasBgImage) {
                $desc2 = $service->analyzeImage($image2Path, 'background');
            }

            // generateFinalPrompt handles null desc1/desc2 gracefully
            $geminiPrompt = $service->generateFinalPrompt($title, $context, $desc1, $desc2);

            // Only accept Gemini prompt if it's a real, detailed response
            if (!empty($geminiPrompt) && strlen($geminiPrompt) > 100) {
                $finalPrompt = $geminiPrompt;
                \Illuminate\Support\Facades\Log::info('✅ Master prompt from Gemini. Length: ' . strlen($finalPrompt));
            } else {
                \Illuminate\Support\Facades\Log::warning('⚠️ Gemini returned short/empty prompt — using smart fallback.');
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('⚠️ Gemini prompt generation failed, using smart fallback: ' . $e->getMessage());
            // $finalPrompt already has the smart fallback — no need to do anything
        }

        // ──────────────────────────────────────────────────────────────
        // Step 2: PATH A — Both images → PHP GD Compositor
        //         Real face on right, real background on left, title text
        // ──────────────────────────────────────────────────────────────
        if ($hasBothImages) {
            try {
                $base64Image = $service->createCompositedImage($image2Path, $image1Path, $title);

                return response()->json([
                    'success'     => true,
                    'prompt_used' => $finalPrompt,
                    'image_url'   => "data:image/jpeg;base64," . $base64Image,
                    'message'     => 'Thumbnail ready!',
                    'source'      => 'php_compositor',
                ]);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('PHP compositor failed, falling back to AI: ' . $e->getMessage());
                // fall through to AI generation below
            }
        }

        // ──────────────────────────────────────────────────────────────
        // Step 3: PATH B — Gemini Image API / Pollinations fallback
        //         Uses the smart detailed prompt (not a blank placeholder)
        // ──────────────────────────────────────────────────────────────
        try {
            $imgResult   = $service->generateFinalImage($finalPrompt, $image1Path, $image2Path);
            $base64Image = $imgResult['base64'];

            return response()->json([
                'success'     => true,
                'prompt_used' => $finalPrompt,
                'image_url'   => "data:image/jpeg;base64," . $base64Image,
                'message'     => 'Thumbnail ready! (AI Generated)',
                'source'      => $imgResult['service'] === 'gemini' ? 'gemini' : 'pollinations',
            ]);
        } catch (\Exception $aiEx) {
            \Illuminate\Support\Facades\Log::error('All generation methods failed: ' . $aiEx->getMessage());

            return response()->json([
                'success'     => false,
                'error'       => 'Could not generate thumbnail. Please try again.',
                'prompt_used' => $finalPrompt,
            ], 500);
        }
    }
}
