<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ThumbnailService
{
    protected $apiKey;
    protected $baseUrl = "https://generativelanguage.googleapis.com/v1beta";

    public function __construct()
    {
        $this->apiKey = env('GEMINI_API_KEY');

        if (empty($this->apiKey)) {
            throw new \Exception('Gemini API key is not set. Please add GEMINI_API_KEY to your .env file.');
        }
    }

    /**
     * Perform a POST call to the Gemini API and handle common error cases.
     *
     * @param string $endpoint full URL without key
     * @param array  $payload  data to JSON encode
     * @param int    $retryCount recursion depth for rate limit retries
     * @return \Illuminate\Http\Client\Response
     * @throws \Exception on persistent errors
     */
    private function makeApiRequest($endpoint, $payload, $retryCount = 0)
    {
        set_time_limit(240); // allow longer execution for backoff sleeps

        // always supply the key as query param (relying on constructor check)
        $response = Http::withoutVerifying()
            ->timeout(120)
            ->post("{$endpoint}?key={$this->apiKey}", $payload);

        // handle rate‑limit 429 with exponential backoff (max 3 retries)
        if ($response->status() === 429) {
            // if we still have retries left, wait and try again
            if ($retryCount < 3) {
                $wait = 5 * pow(2, $retryCount); // 5s,10s,20s
                \Illuminate\Support\Facades\Log::warning("Rate limit hit, sleeping for {$wait} seconds (retry #" . ($retryCount + 1) . ")...");
                sleep($wait);
                return $this->makeApiRequest($endpoint, $payload, $retryCount + 1);
            }

            // final failure – bubble up a clear error so controller can respond
            throw new \Exception('The Gemini API rate limit has been exceeded. Please try again in a minute or reduce request frequency.');
        }

        if ($response->failed()) {
            $body = $response->body();
            $status = $response->status();
            \Illuminate\Support\Facades\Log::error("Gemini API call failed ({$status}): {$body}");
            throw new \Exception('Gemini API error: ' . ($response->json('error.message') ?? $body));
        }

        return $response;
    }

    // Step 1: Analyze Image
    /**
     * Ask Gemini to produce a textual description of the supplied picture.
     *
     * @param string $imagePath path to a local file (jpeg/png/webp)
     * @return string|null description or null on failure
     */
    /**
     * Analyze an image and return a description tailored for thumbnail prompt crafting.
     *
     * @param string $imagePath path to a local file (jpeg/png/webp)
     * @param string $role     "face" for the main subject or "background" for context
     *                         (defaults to 'general' if omitted).
     * @return string|null description or null on failure
     */
    public function analyzeImage($imagePath, $role = 'general')
    {
        if (!file_exists($imagePath) || !is_readable($imagePath)) {
            throw new \InvalidArgumentException("Image file not found: {$imagePath}");
        }

        $imageData = base64_encode(file_get_contents($imagePath));
        $modelName = env('GEMINI_MODEL_ANALYZE', 'gemini-1.5-flash');
        $endpoint = "{$this->baseUrl}/models/{$modelName}:generateContent";

        // adjust the instruction based on the intended use of the image
        if ($role === 'face') {
            $instruction = "Analyze the image of a person or object that will serve as the *main subject* of a YouTube thumbnail. "
                . "Describe appearance, expression, pose, clothing, notable features, lighting, and any colors or styles the artist should emulate.\n";
        } elseif ($role === 'background') {
            $instruction = "Analyze the image that will be used as a reference for the *background or context* of a YouTube thumbnail. "
                . "Describe the overall mood, dominant colors, objects, activity, and atmosphere so an artist can recreate or draw inspiration from it.\n";
        } else {
            $instruction = "Analyze the image and describe details relevant for a YouTube thumbnail.\n";
        }

        $response = $this->makeApiRequest($endpoint, [
            'contents' => [[
                'parts' => [
                    ['text' => $instruction],
                    ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $imageData]]
                ]
            ]]
        ]);

        \Illuminate\Support\Facades\Log::info('analyzeImage Response: ', $response->json() ?? []);

        return $response->json('candidates.0.content.parts.0.text');
    }

    // Step 2: Generate Final Prompt
    /**
     * Craft the full image-generation prompt from video/title/context/descriptions.
     *
     * @param string $title
     * @param string $context
     * @param string $desc1 result of analyzeImage() for primary face
     * @param string $desc2 result of analyzeImage() for background
     * @return string
     */
    public function generateFinalPrompt($title, $context, $desc1, $desc2)
    {
        $modelName = env('GEMINI_MODEL_GENERATE', 'gemini-1.5-pro');
        $endpoint = "{$this->baseUrl}/models/{$modelName}:generateContent";

        // build human-friendly instruction with proper escaping and explicit references to the two
        // uploaded images.  Desc1 is based on the first image (face/main object) and desc2 on the
        // second image (background/context).
        $instruction = "You are a professional YouTube Thumbnail Expert. "
            . "Using the first reference image (main subject) and the second reference image (background), "
            . "craft a detailed, visual-heavy prompt for an AI image generator (Midjourney / DALL-E / Gemini Image) that will produce a thumbnail matching the following specifics:\n"
            . "Video Title (MUST APPEAR IN THUMBNAIL): '{$title}'\n"
            . "Video Context/Details: '{$context}'\n"
            . "Reference Image #1 Description (use this as the basis for the character/person/object on the right): {$desc1}\n"
            . "Reference Image #2 Description (use this as the basis for the background and overall vibe): {$desc2}\n\n"
            . "CRITICAL INSTRUCTIONS FOR AI IMAGE GENERATOR:\n"
            . "1. TITLE TEXT ON THUMBNAIL: The video title '{$title}' must be prominently displayed as large, bold, eye-catching text on the *left* side of the thumbnail. Use a bright color (yellow, white, orange, etc.) with a dark shadow or outline for readability. This is MANDATORY and non-negotiable.\n"
            . "2. CHARACTER ON RIGHT: The right side MUST feature a person or object that closely resembles the first reference image. Maintain the pose, expression, clothing, or other key visual cues; the subject should look confident and dynamic (e.g., pointing, shocked, arms crossed).\n"
            . "3. BACKGROUND LAYOUT: The background should draw heavily from the second reference image's mood, colors, and elements. It should be bold, colorful, and dramatic — bright gradients, neon effects, financial charts, etc., as appropriate to the context text. High contrast is essential.\n"
            . "4. CONTEXT ACCURACY: Incorporate visual hints or symbols that directly relate to the video context ('{$context}'). For example, if the video is about the stock market, include upward-trending graphs, money icons, or relevant signage.\n"
            . "5. STYLE: Professional YouTube thumbnail style only. High contrast. Vibrant neon/gold/purple/red color palette. The overall look must scream 'clickbait-worthy' and premium. 16:9 aspect ratio, no watermarks, no extra text besides the title.\n"
            . "6. CRITICAL: Output ONLY the raw prompt text. Do not include any explanatory text, asterisks, quotes, or introductory phrases. The output should be a single prompt ready to paste into an image generator.\n";

        $response = $this->makeApiRequest($endpoint, [
            'contents' => [[
                'parts' => [
                    ['text' => $instruction]
                ]
            ]]
        ]);

        \Illuminate\Support\Facades\Log::info('generateFinalPrompt Response: ', $response->json() ?? []);

        $text = $response->json('candidates.0.content.parts.0.text');
        
        // Cleanup potential AI conversational garbage
        $text = preg_replace('/^Here is.*?:/sim', '', $text);
        $text = preg_replace('/^\*\*AI IMAGE GENERATOR PROMPT:\*\*/sim', '', $text);
        return trim(str_replace(['**', '```'], '', $text));
    }

    /**
     * Generate a prompt strictly for backgrounds (no text, no people).
     * This is used internally to get a beautiful backdrop from AI.
     */
    public function generateBackgroundPrompt($context, $desc2)
    {
        $modelName = env('GEMINI_MODEL_GENERATE', 'gemini-1.5-pro');
        $endpoint = "{$this->baseUrl}/models/{$modelName}:generateContent";

        $instruction = "Create a vivid prompt for an AI image generator to make a clean, stunning, colorful BACKGROUND IMAGE for a YouTube thumbnail.\n"
            . "Video Context: '{$context}'\n"
            . "Visual Style Reference: {$desc2}\n\n"
            . "RULES:\n"
            . "1. NO PEOPLE, no faces, no humans.\n"
            . "2. NO TEXT, no words, no letters.\n"
            . "3. Make it dramatic lighting, neon accents, high contrast, suitable for dropping text over it.\n"
            . "4. CRITICAL: Output ONLY the raw prompt text. No conversational filler like 'Here is the prompt'.";

        $response = $this->makeApiRequest($endpoint, [
            'contents' => [[
                'parts' => [
                    ['text' => $instruction]
                ]
            ]]
        ]);

        $text = $response->json('candidates.0.content.parts.0.text');
        
        // Cleanup potential AI conversational garbage
        $text = preg_replace('/^Here is.*?:/sim', '', $text);
        return trim(str_replace(['**', '```'], '', $text));
    }

    // Step 3: Imagen 3 API Final Image Generation
    /**
     * Request an image from the Gemini image model and return base64 bytes.
     *
     * This method is now used as the *primary* way of obtaining the thumbnail
     * so that the output matches the prompt when fed directly to Gemini/other
     * image services.  Previously the controller bypassed the API and drew a
     * composition itself; that code is still available in
     * createCompositedImage() if you ever want a deterministic fallback.
     *
     * @param string $prompt the text prompt generated earlier
     * @return string|null base64-encoded JPEG/PNG bytes
     */
    /**
     * Request an image from the Gemini image model and return base64 bytes.
     *
     * @param string      $prompt     The text prompt generated earlier
     * @param string|null $facePath   Path to the face/main-object reference image
     * @param string|null $bgPath     Path to the background reference image
     * @return array                  ['base64' => string, 'service' => 'gemini'|'pollinations']
     * @throws \Exception on persistent failure
     */
    public function generateFinalImage($prompt, $facePath = null, $bgPath = null)
    {
        if (empty($prompt)) {
            \Illuminate\Support\Facades\Log::error('generateFinalImage Error: prompt is empty.');
            throw new \InvalidArgumentException('Prompt cannot be empty');
        }

        $modelName = env('GEMINI_MODEL_IMAGE', 'gemini-2.0-flash-preview-image-generation');
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent";

        // Build contents payload with optional inline images
        $payloadParts = [
            ['text' => $prompt]
        ];

        $attachImage = function ($path) use (&$payloadParts) {
            if ($path && file_exists($path) && is_readable($path)) {
                $data = base64_encode(file_get_contents($path));
                $payloadParts[] = ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $data]];
            }
        };
        $attachImage($facePath);
        $attachImage($bgPath);

        try {
            $response = $this->makeApiRequest($endpoint, [
                'contents' => [[
                    'parts' => $payloadParts
                ]],
                'generationConfig' => [
                    'responseModalities' => ['TEXT', 'IMAGE']
                ]
            ]);

            \Illuminate\Support\Facades\Log::info('generateFinalImage Response: ', $response->json() ?? []);

            // Several possible locations for the returned image bytes
            if ($response->json('predictions.0.bytesBase64Encoded')) {
                return ['base64' => $response->json('predictions.0.bytesBase64Encoded'), 'service' => 'gemini'];
            }

            if ($response->json('candidates.0.content.parts.0.inlineData.data')) {
                return ['base64' => $response->json('candidates.0.content.parts.0.inlineData.data'), 'service' => 'gemini'];
            }

            $candidates = $response->json('candidates') ?? [];
            foreach ($candidates as $candidate) {
                $parts = $candidate['content']['parts'] ?? [];
                foreach ($parts as $part) {
                    if (!empty($part['inlineData']['data'])) {
                        return ['base64' => $part['inlineData']['data'], 'service' => 'gemini'];
                    }
                }
            }

            \Illuminate\Support\Facades\Log::warning('Gemini image model returned no image. Falling back to Pollinations.ai');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Gemini image model failed: ' . $e->getMessage() . '. Falling back to Pollinations.ai');
        }

        // --- Fallback: Pollinations.ai (free, no key needed) ---
        $encodedPrompt = urlencode($prompt);
        $pollinationsUrl = "https://image.pollinations.ai/prompt/{$encodedPrompt}?width=1280&height=720&nologo=true&enhance=true";

        \Illuminate\Support\Facades\Log::info('Trying Pollinations.ai fallback: ' . $pollinationsUrl);

        $imgResponse = Http::withoutVerifying()->timeout(90)->get($pollinationsUrl);

        if ($imgResponse->successful() && strlen($imgResponse->body()) > 1000) {
            return base64_encode($imgResponse->body());
        }

        // if we reach here something unexpected happened
        \Illuminate\Support\Facades\Log::error('Both Gemini and Pollinations failed.');
        throw new \Exception('Could not generate image. Please check your API key and try again.');
    }

    public function createCompositedImage($bgPath, $facePath, $title)
    {
        $canvas = imagecreatetruecolor(1280, 720);
        if ($canvas === false) {
            throw new \Exception('Failed to create canvas resource');
        }

        $loadImage = function ($path) {
            $info = @getimagesize($path);
            if (!$info) return false;
            if ($info[2] == IMAGETYPE_PNG) return imagecreatefrompng($path);
            if ($info[2] == IMAGETYPE_JPEG) return imagecreatefromjpeg($path);
            if ($info[2] == IMAGETYPE_WEBP) return @imagecreatefromwebp($path);
            return false;
        };

        // 1. Background (Image 2)
        $bg = $loadImage($bgPath);
        if ($bg) {
            $bgWidth = imagesx($bg);
            $bgHeight = imagesy($bg);

            // Smart cover resize (Scale to fill)
            $bgRatio = $bgWidth / $bgHeight;
            $canvasRatio = 1280 / 720;

            if ($bgRatio > $canvasRatio) {
                $newWidth = (int)($bgHeight * $canvasRatio);
                $newHeight = $bgHeight;
                $srcX = (int)(($bgWidth - $newWidth) / 2);
                $srcY = 0;
            } else {
                $newWidth = $bgWidth;
                $newHeight = (int)($bgWidth / $canvasRatio);
                $srcX = 0;
                $srcY = (int)(($bgHeight - $newHeight) / 2);
            }

            imagecopyresampled($canvas, $bg, 0, 0, $srcX, $srcY, 1280, 720, $newWidth, $newHeight);
            imagedestroy($bg);

            $darken = imagecolorallocatealpha($canvas, 0, 0, 0, 75); 
            imagefilledrectangle($canvas, 0, 0, 1280, 720, $darken);

            // Just a slight blur so we don't destroy AI background details
            imagefilter($canvas, IMG_FILTER_GAUSSIAN_BLUR);
        } else {
            $darkColor = imagecolorallocate($canvas, 15, 20, 35);
            imagefill($canvas, 0, 0, $darkColor);
        }

        $glowColor = imagecolorallocatealpha($canvas, 70, 0, 200, 60);
        $pointsGlow = [-50, -50, 880, -50, 680, 770, -50, 770];
        imagefilledpolygon($canvas, $pointsGlow, 4, $glowColor);

        $panelColor = imagecolorallocatealpha($canvas, 10, 10, 20, 20);
        $pointsPanel = [-50, -50, 850, -50, 650, 770, -50, 770];
        imagefilledpolygon($canvas, $pointsPanel, 4, $panelColor);

        // Neon Cyan Accent Line
        $neonColor = imagecolorallocate($canvas, 0, 255, 255); // Cyan
        imagesetthickness($canvas, 8);
        imageline($canvas, 850, -50, 650, 770, $neonColor);

        // 3. Face / Character (Image 1)
        $face = $loadImage($facePath);
        if ($face) {
            imagepalettetotruecolor($face);
            imagealphablending($face, true);
            imagesavealpha($face, true);

            $faceW = imagesx($face);
            $faceH = imagesy($face);

            // Scale optimally to dominate right side
            $newH = 680;
            $newW = (int)(($faceW / $faceH) * $newH);

            $destX = 1280 - $newW - 10; // Padding from right edge
            $destY = 720 - $newH; // Sit on bottom

            // Draw Face
            imagecopyresampled($canvas, $face, $destX, $destY, 0, 0, $newW, $newH, $faceW, $faceH);
            imagedestroy($face);
        }

        // 4. Highly Readable & Bold Typography
        // use system font or fallback to bundled font
        $fontPath = 'C:\Windows\Fonts\NirmalaB.ttf';
        if (!file_exists($fontPath)) {
            // fallback to bundled LiberationSans if available
            $fallback = base_path('resources/fonts/LiberationSans-Regular.ttf');
            if (file_exists($fallback)) {
                $fontPath = $fallback;
            }
        }

        if (file_exists($fontPath)) {
            $textColorWhite = imagecolorallocate($canvas, 255, 255, 255);
            $textColorYellow = imagecolorallocate($canvas, 255, 230, 0); // Vibrant Gold
            $shadowColor = imagecolorallocate($canvas, 10, 10, 15);

            $fontSize = 50;

            $words = explode(' ', trim($title));
            $lines = [];
            $currentLine = '';

            foreach ($words as $word) {
                $testLine = $currentLine == '' ? $word : $currentLine . ' ' . $word;
                $bbox = imagettfbbox($fontSize, 0, $fontPath, $testLine);
                $width = $bbox[2] - $bbox[0];
                if ($width > 550) { // Keep inside dark panel
                    $lines[] = $currentLine;
                    $currentLine = $word;
                } else {
                    $currentLine = $testLine;
                }
            }
            if ($currentLine != '') {
                $lines[] = $currentLine;
            }

            // Vertically center text based on number of lines
            $totalHeight = count($lines) * 90;
            $y = (720 - $totalHeight) / 2 + 60;

            foreach ($lines as $index => $line) {
                // Highlight last line in Yellow for click-bait effect
                $color = ($index == count($lines) - 1 && count($lines) > 1) ? $textColorYellow : $textColorWhite;
                $x = 70; // X padding

                // Heavy multi-layered shadow for extreme readability
                for ($sx = 1; $sx <= 4; $sx++) {
                    for ($sy = 1; $sy <= 4; $sy++) {
                        imagettftext($canvas, $fontSize, 0, $x + $sx, $y + $sy, $shadowColor, $fontPath, $line);
                    }
                }

                // Actual outline/glow could be added, but heavy shadow is usually best
                imagettftext($canvas, $fontSize, 0, $x, $y, $color, $fontPath, $line);
                $y += 90;
            }
        }

        ob_start();
        imagejpeg($canvas, null, 95); // High quality JPEG
        $imageBytes = ob_get_clean();
        imagedestroy($canvas);

        return base64_encode($imageBytes);
    }
}
