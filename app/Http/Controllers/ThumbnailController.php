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
        return "Generate a professional YouTube thumbnail image. 16:9 ratio, 1280x720 pixels. "
            . "MANDATORY TITLE TEXT: Display ONLY these words as large bold text on the LEFT side: \"{$title}\". "
            . "Use maximum 5 words if the title is long — truncate smartly. "
            . "Font: extra-bold, thick sans-serif. Color: ultra-bright yellow (#FFD700) or white with a thick black shadow/outline for maximum readability. "
            . "RIGHT SIDE: a photorealistic, dramatic human figure or thematic object directly related to: \"{$context}\". Confident pose, professional studio lighting, NO white background halo or outline artefacts. "
            . "BACKGROUND: cinematic dark gradient with neon glowing accents and thematic symbols related to \"{$context}\" (e.g., finance: stock charts, rupee/dollar signs, upward arrows; tech: circuit boards; travel: scenic vistas). "
            . "STYLE RULES: high contrast, vibrant palette (deep purple/blue/black + neon gold/green), dramatic professional lighting, clean classic aesthetic, NO watermarks, NO extra text besides the title, NO borders or frames. Hyper-realistic, cinematic quality.";
    }

    public function generate(Request $request, ThumbnailService $service)
    {
        // Outer catch: guarantees we always return JSON even if constructor
        // or validation throws, so JS never sees an HTML 500 page.
        try {
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
            // Step 2: AI Generation — always send images to Gemini as
            //         inline_data so the model naturally blends face +
            //         background lighting (no PHP GD compositor artefacts).
            // ──────────────────────────────────────────────────────────────
            try {
                $imgResult   = $service->generateFinalImage($finalPrompt, $image1Path, $image2Path);
                $base64Image = $imgResult['base64'];

                $source  = $imgResult['service'] ?? 'pollinations';
                $message = match ($imgResult['service'] ?? 'pollinations') {
                    'imagen3' => 'Thumbnail ready! (Imagen-3 — Best Quality)',
                    'gemini'  => $hasBothImages ? 'Thumbnail ready! (AI blended face + background)' : 'Thumbnail ready! (Gemini AI)',
                    'local'   => 'Thumbnail ready! (Local synthesis fallback)',
                    default   => 'Thumbnail ready! (Pollinations fallback)',
                };

                return response()->json([
                    'success'         => true,
                    'prompt_used'     => $finalPrompt,
                    'image_url'       => "data:image/jpeg;base64," . $base64Image,
                    'message'         => $message,
                    'source'          => $source,
                    'has_both_images' => $hasBothImages,
                ]);
            } catch (\Exception $aiEx) {
                \Illuminate\Support\Facades\Log::error('All generation methods failed: ' . $aiEx->getMessage());

                return response()->json([
                    'success'     => false,
                    'error'       => 'Could not generate thumbnail. Please try again.',
                    'prompt_used' => $finalPrompt,
                ], 500);
            }
        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json([
                'success' => false,
                'error'   => 'Validation failed: ' . implode(' ', $ve->validator->errors()->all()),
            ], 422);
        } catch (\Exception $globalEx) {
            \Illuminate\Support\Facades\Log::error('Unhandled exception in generate(): ' . $globalEx->getMessage() . ' | ' . $globalEx->getFile() . ':' . $globalEx->getLine());
            return response()->json([
                'success' => false,
                'error'   => 'Server configuration error: ' . $globalEx->getMessage(),
            ], 500);
        }
    }
}
