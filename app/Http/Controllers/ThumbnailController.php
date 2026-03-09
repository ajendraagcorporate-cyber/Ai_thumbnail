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

    public function generate(Request $request, ThumbnailService $service)
    {
        $request->validate([
            'title' => 'required|string|max:100',
            'image1' => 'required|image|mimes:jpeg,png,jpg',
            'image2' => 'required|image|mimes:jpeg,png,jpg',
        ]);

        // Basic Fallback Prompt
        $finalPrompt = "Create a highly engaging YouTube thumbnail for a video titled '{$request->title}'. The video is about: {$request->context}. Make it visually striking, professional, and click-worthy.";

        $image1Path = $request->file('image1')->path();
        $image2Path = $request->file('image2')->path();

        try {
            // 1. Analyze both images (used only for prompt crafting).  Provide a hint
            // about the intended use so the language model returns a richer, role‑specific
            // description.  Image1 is the main face/subject, image2 is the background.
            $desc1 = $service->analyzeImage($image1Path, 'face');
            $desc2 = $service->analyzeImage($image2Path, 'background');

            // 2. Generate Prompt using AI -- the service will refer to the descriptions and
            // encourage the final image to mimic both references closely.
            $finalPrompt = $service->generateFinalPrompt($request->title, $request->context, $desc1, $desc2);

            // 3. Ask the Gemini image model to render the thumbnail, providing the
            // two uploaded files as reference.  The service returns an array with the
            // base64 data and which service actually produced the image.
            $imgResult = $service->generateFinalImage($finalPrompt, $image1Path, $image2Path);

            $base64Image = $imgResult['base64'];
            $sourceLabel = $imgResult['service'] === 'gemini' ? 'gemini' : 'pollinations';

            // 4. Return as Data URI so frontend can display it directly
            return response()->json([
                'success' => true,
                'prompt_used' => $finalPrompt,
                'image_url' => "data:image/jpeg;base64," . $base64Image,
                'message' => 'Your thumbnail is ready! (AI-generated)',
                'source' => $sourceLabel
            ]);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            \Illuminate\Support\Facades\Log::warning('AI generation failed, using PHP compositor fallback: ' . $msg);

            // --- Last Resort Fallback: PHP GD Compositor ---
            // This always works locally. Title goes on left, face on right.
            try {
                $base64Image = $service->createCompositedImage($image2Path, $image1Path, $request->title);
                return response()->json([
                    'success' => true,
                    'prompt_used' => $finalPrompt,
                    'image_url' => "data:image/jpeg;base64," . $base64Image,
                    'message' => 'Thumbnail ready! (PHP compositor used – AI was unavailable)',
                    'source' => 'php_compositor',
                    'ai_error' => $msg
                ]);
            } catch (\Exception $compositorEx) {
                $statusCode = 500;
                if (stripos($msg, 'rate limit') !== false) {
                    $statusCode = 429;
                }
                return response()->json([
                    'success' => false,
                    'error' => $msg,
                    'prompt_used' => $finalPrompt
                ], $statusCode);
            }
        }
    }
}
