<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ThumbnailService
{
    protected $apiKey;
    protected $baseUrl = "https://generativelanguage.googleapis.com/v1beta";

    public function __construct()
    {
        $this->apiKey = env('GEMINI_API_KEY', '');
        // Note: we no longer throw here — individual methods will throw if the key
        // is absent, so the controller's try/catch can handle it gracefully and
        // still return JSON (instead of an HTML 500 page).
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
        if (empty($this->apiKey)) {
            throw new \Exception('GEMINI_API_KEY is not configured. Please set it in Railway environment variables.');
        }

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
    /**
     * Resize an image to max 800×600 and return its JPEG bytes.
     * This keeps base64 payloads small (<300 KB) and prevents PHP OOM crashes
     * when users upload large photos (e.g. 4K / 8 MB+).
     *
     * @param string $path  absolute path to the image file
     * @return string       raw JPEG bytes
     */
    private function resizeImageForUpload(string $path): string
    {
        $info = @getimagesize($path);
        if (!$info) {
            return file_get_contents($path); // not an image we can resize – return as-is
        }

        [$origW, $origH, $type] = $info;
        $maxW = 800;
        $maxH = 600;

        // Only resize if needed
        if ($origW <= $maxW && $origH <= $maxH) {
            return file_get_contents($path);
        }

        // Calculate new dimensions keeping aspect ratio
        $ratio   = min($maxW / $origW, $maxH / $origH);
        $newW    = (int)($origW * $ratio);
        $newH    = (int)($origH * $ratio);

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default        => false,
        };

        if (!$src) {
            return file_get_contents($path); // fallback
        }

        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($src);

        ob_start();
        imagejpeg($dst, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($dst);

        return $bytes;
    }

    public function analyzeImage($imagePath, $role = 'general')
    {
        if (!file_exists($imagePath) || !is_readable($imagePath)) {
            throw new \InvalidArgumentException("Image file not found: {$imagePath}");
        }

        // Resize before encoding — prevents OOM crash on large uploaded photos
        $imageData = base64_encode($this->resizeImageForUpload($imagePath));

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
    public function generateFinalPrompt($title, $context, $desc1 = null, $desc2 = null)
    {
        $modelName = env('GEMINI_MODEL_GENERATE', 'gemini-1.5-pro');
        $endpoint = "{$this->baseUrl}/models/{$modelName}:generateContent";

        $hasFace = !empty($desc1);
        $hasBg   = !empty($desc2);

        $instruction = "You are a world-class YouTube Thumbnail Prompt Engineer. "
            . "Craft a single, detailed, visual-heavy prompt for an AI image generator (Gemini Image) to create a stunning YouTube thumbnail.\n"
            . "Video Title (MUST APPEAR IN THUMBNAIL EXACTLY AS WRITTEN): '{$title}'\n"
            . "Video Context/Details: '{$context}'\n";

        if ($hasFace) {
            $instruction .= "Reference Image #1 — Main Subject/Face Description: {$desc1}\n";
        }
        if ($hasBg) {
            $instruction .= "Reference Image #2 — Background/Context Description: {$desc2}\n";
        }

        $instruction .= "\nCRITICAL INSTRUCTIONS FOR THE AI IMAGE GENERATOR PROMPT YOU WILL WRITE:\n";
        $instruction .= "1. TITLE TEXT: Display the text '{$title}' as large, extra-bold text on the LEFT side of the thumbnail. "
            . "Use MAXIMUM 5 WORDS — if the title exceeds 5 words, intelligently abbreviate to the most impactful 5 words. "
            . "If the title is in Hindi/Devanagari or any non-English language, render it EXACTLY in that script. "
            . "Font: thick, extra-bold sans-serif. Color: ultra-bright yellow (#FFD700) or white with a thick black shadow/outline for maximum readability. MANDATORY.\n";

        if ($hasFace) {
            $instruction .= "2. FACE/CHARACTER (RIGHT SIDE): The right side MUST show a PHOTOREALISTIC PERSON that precisely matches "
                . "the physical features, skin tone, hair color/style, facial structure, expression, and clothing described in Reference Image #1. "
                . "The person must appear NATURALLY LIT matching the background — NO white background halo, NO white outline artefacts, NO cutout edges. "
                . "Blend the subject seamlessly into the scene with professional studio-quality composite lighting.\n";
        } else {
            $instruction .= "2. FACE/CHARACTER (RIGHT SIDE): The right side MUST show a PHOTOREALISTIC, DRAMATIC character or object "
                . "directly relevant to the video topic: '{$context}'. Make it visually striking, professionally lit, and seamlessly composited.\n";
        }

        if ($hasBg) {
            $instruction .= "3. BACKGROUND: Draw heavily from Reference Image #2's mood, dominant colors, and visual elements. "
                . "Bold, colorful, dramatic — bright gradients, neon effects, high contrast. The face must blend naturally with this specific background's lighting.\n";
        } else {
            $instruction .= "3. BACKGROUND: Design a bold, dramatic, vivid background that perfectly represents the video topic: '{$context}'. "
                . "Use neon gradients, glowing effects, and thematic visuals (e.g., financial charts for finance topics, landscapes for travel, etc.). High contrast.\n";
        }

        $instruction .= "4. CONTEXT ACCURACY: Incorporate compelling visual symbols directly related to '{$context}'.\n"
            . "5. STYLE RULES: Professional YouTube thumbnail. High contrast. Vibrant palette (neon gold/purple/red/blue). "
            . "Clean and classic aesthetic. Professional dramatic lighting. 16:9 aspect ratio (1280x720). "
            . "Absolutely NO watermarks. NO extra text besides the title. NO borders or frames. NO amateur compositing artefacts.\n"
            . "6. CRITICAL: Output ONLY the raw prompt text — a single paragraph. No explanatory text, asterisks, quotes, or intro phrases like 'Here is the prompt:'.\n";

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
        $text = preg_replace('/^Here is.*?:\s*/sim', '', $text);
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

    // ─────────────────────────────────────────────────────────────────────────
    // Step 3: 3-Tier Image Generation (Bulletproof Fallback Strategy)
    //
    //   Tier 1 ▶ Imagen-3       (imagen-3.0-generate-002)
    //            ✅ Best text spelling & professional lighting
    //            ✅ Uses :predict endpoint with unique payload format
    //            ❌ Text-to-image only (no inline image refs)
    //
    //   Tier 2 ▶ Gemini 2.0 Flash Preview (gemini-2.0-flash-preview-image-generation)
    //            ✅ Accepts inline face+background images → natural blending
    //            ✅ Falls back through older Gemini models automatically
    //
    //   Tier 3 ▶ Pollinations.ai (free, no API key, always available)
    //            ✅ Zero-cost last resort, prevents 100% failure
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Main entry point: tries all 3 tiers in order, returns first success.
     *
     * @param string      $prompt   The master prompt generated earlier
     * @param string|null $facePath Path to face/person reference image
     * @param string|null $bgPath   Path to background reference image
     * @return array                ['base64' => string, 'service' => 'imagen3'|'gemini'|'pollinations']
     * @throws \Exception when all 3 tiers fail
     */
    public function generateFinalImage($prompt, $facePath = null, $bgPath = null)
    {
        if (empty($prompt)) {
            \Illuminate\Support\Facades\Log::error('generateFinalImage Error: prompt is empty.');
            throw new \InvalidArgumentException('Prompt cannot be empty');
        }

        // ── Tier 1: Imagen-3 ────────────────────────────────────────────────
        $result = $this->tryImagen3($prompt);
        if ($result) {
            \Illuminate\Support\Facades\Log::info('✅ Tier 1 (Imagen-3) succeeded.');
            return $result;
        }
        \Illuminate\Support\Facades\Log::warning('⚠️ Tier 1 (Imagen-3) failed. Trying Tier 2 (Gemini Flash Preview)...');

        // ── Tier 2: Gemini 2.0 Flash Preview (with inline image support) ────
        $result = $this->tryGeminiFlashPreview($prompt, $facePath, $bgPath);
        if ($result) {
            \Illuminate\Support\Facades\Log::info('✅ Tier 2 (Gemini Flash Preview) succeeded.');
            return $result;
        }
        \Illuminate\Support\Facades\Log::warning('⚠️ Tier 2 (Gemini) failed. Trying Tier 3 (Pollinations)...');

        // ── Tier 3: Pollinations.ai ──────────────────────────────────────────
        $result = $this->tryPollinations($prompt);
        if ($result) {
            \Illuminate\Support\Facades\Log::info('✅ Tier 3 (Pollinations) succeeded.');
            return $result;
        }

        // ── Tier 4: Local GD Composite (Never-Fail Last Resort) ────────────────
        \Illuminate\Support\Facades\Log::warning('⚠️ All AI tiers failed. Falling back to Tier 4 (Local Composite)...');
        $b64 = $this->createCompositedImage($bgPath, $facePath, $prompt);
        if ($b64) {
            return ['base64' => $b64, 'service' => 'local'];
        }

        // if we reach here something truly failed
        throw new \Exception('All generation methods (including local) failed.');
    }

    /**
     * Tier 1 — Imagen-3 via the :predict endpoint.
     * Uses a distinct payload format (instances/parameters), NOT generateContent.
     * Best-in-class for text spelling accuracy in generated images.
     *
     * @param string $prompt
     * @return array|null  ['base64' => ..., 'service' => 'imagen3'] or null on failure
     */
    private function tryImagen3(string $prompt): ?array
    {
        if (empty($this->apiKey)) return null;

        // Using Imagen 4 as Tier 1 (available in this system)
        $endpoint = "{$this->baseUrl}/models/imagen-4.0-generate-001:predict";

        try {
            $response = Http::withoutVerifying()
                ->timeout(120)
                ->post("{$endpoint}?key={$this->apiKey}", [
                    'instances' => [
                        ['prompt' => $prompt]
                    ],
                    'parameters' => [
                        'sampleCount'       => 1,
                        'aspectRatio'       => '16:9',       // YouTube 16:9
                        'safetyFilterLevel' => 'block_some',
                        'personGeneration'  => 'allow_adult',
                    ],
                ]);

            if ($response->status() === 429) {
                \Illuminate\Support\Facades\Log::warning('Imagen-3: Rate limit (429). Skipping to Tier 2.');
                return null;
            }

            if ($response->status() === 400) {
                // Billing not enabled or quota not available — skip silently
                $errMsg = $response->json('error.message') ?? $response->body();
                \Illuminate\Support\Facades\Log::warning("Imagen-3: 400 error — {$errMsg}");
                return null;
            }

            if ($response->failed()) {
                \Illuminate\Support\Facades\Log::warning("Imagen-3: Failed ({$response->status()}) — " . $response->body());
                return null;
            }

            $b64 = $response->json('predictions.0.bytesBase64Encoded');
            if ($b64) {
                return ['base64' => $b64, 'service' => 'imagen3'];
            }

            \Illuminate\Support\Facades\Log::warning('Imagen-3: No image data in response. Body: ' . mb_substr($response->body(), 0, 300));
            return null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Imagen-3 exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Tier 2 — Gemini 2.0 Flash Preview image generation.
     * Accepts inline face+background images → natural AI blending.
     * Falls back through older Gemini models if Preview is unavailable.
     *
     * @param string      $prompt
     * @param string|null $facePath
     * @param string|null $bgPath
     * @return array|null  ['base64' => ..., 'service' => 'gemini'] or null on total failure
     */
    private function tryGeminiFlashPreview(string $prompt, ?string $facePath, ?string $bgPath): ?array
    {
        // Model priority: best text rendering first, newer models from your models.json
        $models = [
            'gemini-3.1-flash-image-preview',
            'gemini-2.5-flash-image',
            'gemini-2.0-flash-exp-image-generation',
            'gemini-2.0-flash',
        ];

        // Build parts: text prompt + optional inline images
        $hasImages = ($facePath && file_exists($facePath)) || ($bgPath && file_exists($bgPath));

        $enhancedPrompt = $prompt;
        if ($hasImages) {
            $note = "\n\nREFERENCE IMAGES ATTACHED — ";
            if ($facePath && file_exists($facePath)) {
                $note .= "Image 1 = face/person: replicate exact facial features, skin tone, expression, hair. ";
            }
            if ($bgPath && file_exists($bgPath)) {
                $note .= "Image 2 = background: use its mood, colors, lighting palette. ";
            }
            $note .= "Blend subject naturally into background — NO white halos, NO cutout artefacts. Follow all prompt rules strictly.";
            $enhancedPrompt = $prompt . $note;
        }

        $payloadParts = [['text' => $enhancedPrompt]];

        $attachImage = function (?string $path) use (&$payloadParts): void {
            if ($path && file_exists($path) && is_readable($path)) {
                $data = base64_encode($this->resizeImageForUpload($path));
                $payloadParts[] = ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $data]];
            }
        };
        $attachImage($facePath);
        $attachImage($bgPath);

        foreach ($models as $modelName) {
            $endpoint = "{$this->baseUrl}/models/{$modelName}:generateContent";
            try {
                $response = Http::withoutVerifying()
                    ->timeout(120)
                    ->post("{$endpoint}?key={$this->apiKey}", [
                        'contents'         => [['parts' => $payloadParts]],
                        'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
                    ]);

                if ($response->status() === 404) {
                    \Illuminate\Support\Facades\Log::warning("Gemini Tier 2: Model {$modelName} not found, trying next...");
                    continue;
                }
                if ($response->status() === 429) {
                    \Illuminate\Support\Facades\Log::warning("Gemini Tier 2: Model {$modelName} rate-limited, trying next...");
                    continue;
                }
                if ($response->failed()) {
                    \Illuminate\Support\Facades\Log::warning("Gemini Tier 2: Model {$modelName} failed ({$response->status()}).");
                    continue;
                }

                \Illuminate\Support\Facades\Log::info("Gemini Tier 2: Got response from {$modelName}.");

                // Check all known response locations for base64 image data
                if ($b64 = $response->json('predictions.0.bytesBase64Encoded')) {
                    return ['base64' => $b64, 'service' => 'gemini'];
                }
                if ($b64 = $response->json('candidates.0.content.parts.0.inlineData.data')) {
                    return ['base64' => $b64, 'service' => 'gemini'];
                }
                foreach ($response->json('candidates') ?? [] as $candidate) {
                    foreach ($candidate['content']['parts'] ?? [] as $part) {
                        if (!empty($part['inlineData']['data'])) {
                            return ['base64' => $part['inlineData']['data'], 'service' => 'gemini'];
                        }
                    }
                }

                \Illuminate\Support\Facades\Log::warning("Gemini Tier 2: {$modelName} returned no image data.");
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Gemini Tier 2: {$modelName} exception — " . $e->getMessage());
            }
        }

        return null; // all Gemini models exhausted
    }

    /**
     * Tier 3 — Pollinations.ai (free, no API key, always available).
     * Text-to-image only — used as the never-fail last resort.
     *
     * @param string $prompt
     * @return array  ['base64' => ..., 'service' => 'pollinations']
     * @throws \Exception if Pollinations also fails
     */
    private function tryPollinations(string $prompt): ?array
    {
        // Shorter, simpler prompt for fallback reliability
        $shortPrompt   = mb_substr($prompt, 0, 400);
        $encodedPrompt = rawurlencode($shortPrompt);
        $seed          = rand(1, 99999);
        // Remove high-end model/params if service is struggling
        $url           = "https://image.pollinations.ai/prompt/{$encodedPrompt}?width=1280&height=720&nologo=true&seed={$seed}";

        \Illuminate\Support\Facades\Log::info('Tier 3 (Pollinations) URL: ' . $url);

        try {
            $imgResponse = Http::withoutVerifying()
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36'])
                ->timeout(60)
                ->get($url);

            if ($imgResponse->successful() && strlen($imgResponse->body()) > 2000) {
                return ['base64' => base64_encode($imgResponse->body()), 'service' => 'pollinations'];
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Pollinations failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Remove a white or near-white background from a GD image resource.
     *
     * Strategy:
     *   1. Convert to true-colour with alpha support.
     *   2. Walk every pixel on the 4 edges of the image.
     *   3. For each border pixel whose RGB components are all above a threshold
     *      (i.e. it looks whitish), flood-fill from that pixel, making all
     *      connected near-white pixels fully transparent.
     *   4. Return the modified image resource (caller must destroy it).
     *
     * @param  resource  $img   GD image resource (true-colour)
     * @param  int       $threshold  0-255; pixels with R, G, B all ≥ this are white-ish.
     *                               230 is a good default (handles off-white studio back-
     *                               grounds while keeping skin tones).
     * @return resource  same or new GD resource with white BG made transparent
     */
    private function removeLightBackground($img, int $threshold = 230)
    {
        $w = imagesx($img);
        $h = imagesy($img);

        // Create a fresh true-colour canvas with alpha channel
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
        imagefill($out, 0, 0, $transparent);

        // Copy original pixels onto the new canvas
        imagecopy($out, $img, 0, 0, 0, 0, $w, $h);

        // Helper: is pixel at (x,y) "near white" and fully opaque?
        $isWhiteish = function (int $x, int $y) use ($out, $w, $h, $threshold): bool {
            if ($x < 0 || $x >= $w || $y < 0 || $y >= $h) return false;
            $rgba  = imagecolorat($out, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            if ($alpha > 20) return false; // already transparent-ish – skip
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8)  & 0xFF;
            $b =  $rgba        & 0xFF;
            return ($r >= $threshold && $g >= $threshold && $b >= $threshold);
        };

        // Flood-fill using SplStack (iterative BFS to avoid recursion limit)
        // visited[] is indexed as y*w+x for fast lookup (no string ops)
        $visited = new \SplFixedArray($w * $h);
        $fullyTransparent = imagecolorallocatealpha($out, 255, 255, 255, 127);

        $makeTransparent = function (int $startX, int $startY) use (
            $out,
            $w,
            $h,
            $threshold,
            &$isWhiteish,
            &$visited,
            $fullyTransparent
        ) {
            $stack = new \SplStack();
            $stack->push([$startX, $startY]);

            while (!$stack->isEmpty()) {
                [$cx, $cy] = $stack->pop();
                if ($cx < 0 || $cx >= $w || $cy < 0 || $cy >= $h) continue;
                $idx = $cy * $w + $cx;
                if ($visited[$idx]) continue;
                $visited[$idx] = true;

                if (!$isWhiteish($cx, $cy)) continue;

                imagesetpixel($out, $cx, $cy, $fullyTransparent);

                // Push 4-connected neighbours
                $stack->push([$cx + 1, $cy]);
                $stack->push([$cx - 1, $cy]);
                $stack->push([$cx, $cy + 1]);
                $stack->push([$cx, $cy - 1]);
            }
        };

        // Seed from all 4 edges
        for ($x = 0; $x < $w; $x++) {
            if ($isWhiteish($x, 0))       $makeTransparent($x, 0);
            if ($isWhiteish($x, $h - 1))  $makeTransparent($x, $h - 1);
        }
        for ($y = 0; $y < $h; $y++) {
            if ($isWhiteish(0, $y))       $makeTransparent(0, $y);
            if ($isWhiteish($w - 1, $y))  $makeTransparent($w - 1, $y);
        }

        imagedestroy($img);
        return $out;
    }

    public function createCompositedImage($bgPath, $facePath, $title)
    {
        $canvas = imagecreatetruecolor(1280, 720);
        if ($canvas === false) {
            throw new \Exception('Failed to create canvas resource');
        }

        $loadImage = function ($path) {
            if (empty($path)) return false;
            if (!file_exists($path)) return false;
            $info = @getimagesize($path);
            if (!$info) return false;
            if ($info[2] == IMAGETYPE_PNG) return @imagecreatefrompng($path);
            if ($info[2] == IMAGETYPE_JPEG) return @imagecreatefromjpeg($path);
            if ($info[2] == IMAGETYPE_WEBP) return @imagecreatefromwebp($path);
            return false;
        };

        // ── 1. Background – fill canvas with the uploaded background image ───────
        $bg = $loadImage($bgPath);
        if ($bg) {
            $bgWidth  = imagesx($bg);
            $bgHeight = imagesy($bg);

            // Cover-crop: fill canvas without distorting
            $bgRatio     = $bgWidth / $bgHeight;
            $canvasRatio = 1280 / 720;

            if ($bgRatio > $canvasRatio) {
                $newW = (int)($bgHeight * $canvasRatio);
                $newH = $bgHeight;
                $srcX = (int)(($bgWidth - $newW) / 2);
                $srcY = 0;
            } else {
                $newW = $bgWidth;
                $newH = (int)($bgWidth / $canvasRatio);
                $srcX = 0;
                $srcY = (int)(($bgHeight - $newH) / 2);
            }

            imagecopyresampled($canvas, $bg, 0, 0, $srcX, $srcY, 1280, 720, $newW, $newH);
            imagedestroy($bg);

            // Very light darken – keep background vivid like reference image
            $darken = imagecolorallocatealpha($canvas, 0, 0, 0, 100);
            imagefilledrectangle($canvas, 0, 0, 1280, 720, $darken);
        } else {
            // Fallback: vibrant purple gradient
            for ($y = 0; $y < 720; $y++) {
                $r = (int)(120 - ($y / 720) * 40);
                $g = (int)(30  + ($y / 720) * 10);
                $b = (int)(200 - ($y / 720) * 60);
                $c = imagecolorallocate($canvas, $r, $g, $b);
                imageline($canvas, 0, $y, 1280, $y, $c);
            }
        }

        // ── 2. Left-side semi-transparent panel for text readability ─────────────
        // Matches reference: slightly darker left area so yellow text pops
        $panelColor = imagecolorallocatealpha($canvas, 10, 5, 40, 60);
        imagefilledrectangle($canvas, 0, 0, 680, 720, $panelColor);

        // ── 3. Face / Character on the RIGHT (Image 1) ────────────────────────────
        $face = $loadImage($facePath);
        if ($face) {
            imagepalettetotruecolor($face);

            // Remove white/near-white background so the person blends naturally
            $face = $this->removeLightBackground($face);

            imagealphablending($face, false);
            imagesavealpha($face, true);

            $faceW = imagesx($face);
            $faceH = imagesy($face);

            // Fill right ~60% of the canvas height
            $newH  = 710;
            $newW  = (int)(($faceW / $faceH) * $newH);

            // Clamp width so it does not overflow canvas
            if ($newW > 680) {
                $newW = 680;
                $newH = (int)(($faceH / $faceW) * $newW);
            }

            $destX = 1280 - $newW - 5;   // flush to right edge
            $destY = 720  - $newH;        // sit on bottom

            // Enable alpha blending on canvas so transparent pixels show through
            imagealphablending($canvas, true);
            imagecopyresampled($canvas, $face, $destX, $destY, 0, 0, $newW, $newH, $faceW, $faceH);
            imagedestroy($face);
        }

        // ── 4. Bold Typography (Hindi/Devanagari via Nirmala font) ───────────────
        // Font priority: NirmalaB (Hindi) → Nirmala → Arial Bold → fallback
        $fontCandidates = [
            // ── Linux / Docker paths (Railway deployment) ──────────────────────
            base_path('resources/fonts/LiberationSans-Bold.ttf'),
            base_path('resources/fonts/LiberationSans-Regular.ttf'),
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
            '/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
            // ── Windows paths (local XAMPP development) ────────────────────────
            'C:\\Windows\\Fonts\\NirmalaB.ttf',
            'C:\\Windows\\Fonts\\Nirmala.ttf',
            'C:\\Windows\\Fonts\\arialbd.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
        ];
        $fontPath = null;
        foreach ($fontCandidates as $f) {
            if (file_exists($f)) {
                $fontPath = $f;
                break;
            }
        }

        if ($fontPath) {
            // Colors matching reference image: vibrant yellow text, black outline
            $yellow      = imagecolorallocate($canvas, 255, 220, 0);
            $white       = imagecolorallocate($canvas, 255, 255, 255);
            $black       = imagecolorallocate($canvas, 0, 0, 0);
            $darkOutline  = imagecolorallocate($canvas, 15, 10, 20);

            $fontSize   = 72;   // Large, dominant text
            $maxWidth   = 580;  // Max text width before wrapping
            $padding    = 55;   // Left margin

            // Word-wrap
            $words = explode(' ', trim($title));
            $lines = [];
            $cur   = '';

            foreach ($words as $word) {
                $test = $cur === '' ? $word : $cur . ' ' . $word;
                $bbox = imagettfbbox($fontSize, 0, $fontPath, $test);
                if (($bbox[2] - $bbox[0]) > $maxWidth && $cur !== '') {
                    $lines[] = $cur;
                    $cur     = $word;
                } else {
                    $cur = $test;
                }
            }
            if ($cur !== '') $lines[] = $cur;

            // Reduce font size if too many lines
            if (count($lines) > 4) {
                $fontSize = 56;
            }

            $lineHeight  = (int)($fontSize * 1.35);
            $totalHeight = count($lines) * $lineHeight;
            $startY      = (int)((720 - $totalHeight) / 2) + $lineHeight;

            foreach ($lines as $i => $line) {
                // ALL lines yellow like reference; last line can be white for contrast
                $color = ($i === count($lines) - 1 && count($lines) > 2) ? $white : $yellow;
                $x = $padding;
                $y = $startY + ($i * $lineHeight);

                // Thick black outline (draw 8 directions) for crisp readability
                $offsets = [[-3, -3], [0, -3], [3, -3], [-3, 0], [3, 0], [-3, 3], [0, 3], [3, 3]];
                foreach ($offsets as [$ox, $oy]) {
                    imagettftext($canvas, $fontSize, 0, $x + $ox, $y + $oy, $black, $fontPath, $line);
                }
                // Main text
                imagettftext($canvas, $fontSize, 0, $x, $y, $color, $fontPath, $line);
            }
        }

        ob_start();
        imagejpeg($canvas, null, 95);
        $bytes = ob_get_clean();
        imagedestroy($canvas);

        return base64_encode($bytes);
    }
}
