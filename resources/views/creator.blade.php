<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Thumbnail Generator</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 40px 20px;
        }

        .container {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 640px;
            color: #f0f0f0;
        }

        h2 {
            text-align: center;
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 8px;
            background: linear-gradient(90deg, #a78bfa, #60a5fa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .subtitle {
            text-align: center;
            font-size: 13px;
            color: #9ca3af;
            margin-bottom: 30px;
        }

        label {
            font-weight: 600;
            display: block;
            margin-bottom: 6px;
            color: #d1d5db;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .optional-badge {
            display: inline-block;
            background: rgba(167, 139, 250, 0.15);
            border: 1px solid rgba(167, 139, 250, 0.35);
            color: #a78bfa;
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 20px;
            margin-left: 6px;
            text-transform: none;
            letter-spacing: 0;
            font-weight: 500;
            vertical-align: middle;
        }

        input[type="text"],
        textarea,
        input[type="file"] {
            width: 100%;
            padding: 12px 14px;
            margin-bottom: 4px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.08);
            color: #f0f0f0;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            outline: none;
            transition: border-color 0.3s;
        }

        input[type="text"]:focus,
        textarea:focus {
            border-color: #a78bfa;
        }

        input[type="file"] {
            cursor: pointer;
            color: #9ca3af;
        }

        textarea {
            resize: vertical;
        }

        .field-hint {
            color: #6b7280;
            font-size: 12px;
            margin-bottom: 16px;
            display: block;
            line-height: 1.5;
        }

        .optional-note {
            color: #a78bfa;
            font-size: 11.5px;
            font-weight: 500;
        }

        .image-field-wrapper {
            background: rgba(255, 255, 255, 0.03);
            border: 1px dashed rgba(167, 139, 250, 0.25);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .image-field-wrapper label {
            margin-bottom: 10px;
        }

        .image-field-wrapper input[type="file"] {
            margin-bottom: 6px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            color: #fff;
            padding: 13px 20px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: opacity 0.3s, transform 0.15s;
            margin-top: 6px;
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .btn-download {
            background: linear-gradient(135deg, #059669, #047857);
            color: #fff;
            padding: 12px 20px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            margin-top: 14px;
            transition: opacity 0.3s, transform 0.15s;
        }

        .btn-download:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        #loading {
            display: none;
            text-align: center;
            margin-top: 24px;
            color: #9ca3af;
        }

        .spinner {
            border: 4px solid rgba(255, 255, 255, 0.1);
            border-top: 4px solid #a78bfa;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            animation: spin 0.9s linear infinite;
            margin: 0 auto 10px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .loading-status {
            font-size: 13px;
            margin-top: 4px;
            color: #a78bfa;
            font-style: italic;
        }

        #result-container {
            display: none;
            margin-top: 30px;
        }

        #result-container h3 {
            text-align: center;
            font-size: 18px;
            font-weight: 700;
            color: #a78bfa;
            margin-bottom: 14px;
        }

        #thumbnail-preview {
            width: 100%;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.5);
            display: block;
        }

        .prompt-section {
            margin-top: 14px;
            animation: fadeSlideIn 0.4s ease;
        }

        @keyframes fadeSlideIn {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .prompt-box {
            background: rgba(167, 139, 250, 0.08);
            border: 1px solid rgba(167, 139, 250, 0.25);
            padding: 14px 16px;
            border-radius: 10px;
            font-size: 12.5px;
            color: #d1d5db;
            text-align: left;
            word-wrap: break-word;
            line-height: 1.7;
            max-height: 200px;
            overflow-y: auto;
        }

        .prompt-box strong {
            display: block;
            color: #a78bfa;
            margin-bottom: 6px;
            font-size: 13px;
        }

        .copy-btn {
            background: transparent;
            border: 1px solid rgba(167, 139, 250, 0.4);
            color: #a78bfa;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            cursor: pointer;
            width: auto;
            display: inline-block;
            margin-top: 8px;
            transition: background 0.2s;
        }

        .copy-btn:hover {
            background: rgba(167, 139, 250, 0.15);
        }

        .error-message {
            color: #fca5a5;
            background: rgba(220, 38, 38, 0.12);
            border: 1px solid rgba(220, 38, 38, 0.3);
            padding: 14px 16px;
            border-radius: 10px;
            margin-top: 20px;
            display: none;
            font-size: 13px;
        }

        .divider {
            border: none;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            margin: 20px 0 22px;
        }

        .section-note {
            font-size: 12px;
            color: #6b7280;
            text-align: center;
            margin-bottom: 16px;
            font-style: italic;
        }
    </style>
</head>

<body>

    <div class="container">
        <h2>🎨 AI Thumbnail Creator</h2>
        <p class="subtitle">Generate stunning YouTube thumbnails using AI magic</p>

        <form id="thumbnailForm" action="{{ route('thumbnail.create') }}" method="POST" enctype="multipart/form-data">
            @csrf

            <label>Video Title</label>
            <input type="text" name="title" id="title" placeholder="Enter awesome video title..." required>
            <span class="field-hint">AI will use this as the main text on your thumbnail.</span>

            <label>Video Context / Details</label>
            <textarea name="context" id="context" rows="3" placeholder="Describe what the video is about (e.g. Best stocks to invest in 2026, finance tips...)" required></textarea>
            <span class="field-hint">Helps AI understand the theme, mood and visual style to create.</span>

            <hr class="divider">
            <p class="section-note">📸 Images are optional — leave blank and AI will create everything from your title & context</p>

            <div class="image-field-wrapper">
                <label for="image1">
                    Image 1 (Face / Main Object)
                    <span class="optional-badge">Optional</span>
                </label>
                <input type="file" name="image1" id="image1" accept="image/jpeg, image/png, image/jpg">
                <span class="field-hint">Upload a clear photo of the person or object to appear on the right side.<br>
                    <span class="optional-note">✨ Leave empty → AI will invent a suitable character/object from your context.</span>
                </span>
            </div>

            <div class="image-field-wrapper">
                <label for="image2">
                    Image 2 (Background / Context Object)
                    <span class="optional-badge">Optional</span>
                </label>
                <input type="file" name="image2" id="image2" accept="image/jpeg, image/png, image/jpg">
                <span class="field-hint">Upload a background mood image (e.g. stock chart, scenery, product).<br>
                    <span class="optional-note">✨ Leave empty → AI will design a fitting background from your context.</span>
                </span>
            </div>

            <button type="submit" class="btn-primary" id="submitBtn">⚡ Generate My Thumbnail</button>
        </form>

        <div id="loading">
            <div class="spinner"></div>
            <p>Crafting your thumbnail with AI magic...</p>
            <p class="loading-status" id="loading-status">Step 1/3: Building master prompt...</p>
        </div>

        <div class="error-message" id="error-message"></div>

        <div id="result-container">
            <h3>🎉 Thumbnail Ready!</h3>
            <img id="thumbnail-preview" src="" alt="Generated Thumbnail">
            <div id="source-badge" style="text-align:center;margin-top:8px;font-size:12px;color:#9ca3af;"></div>

            <div class="prompt-section">
                <div class="prompt-box">
                    <strong>📝 Master Prompt Used:</strong>
                    <span id="prompt-used"></span>
                </div>
                <button class="copy-btn" onclick="copyPrompt()">📋 Copy Prompt</button>
            </div>

            <button class="btn-download" onclick="downloadImage()">⬇️ Download Image</button>
        </div>
    </div>

    <script>
        let statusTimer = null;
        const statusMessages = [
            'Step 1/3: Building master prompt...',
            'Step 2/3: Analyzing images & context...',
            'Step 3/3: Generating AI image...'
        ];
        let statusIndex = 0;

        function startStatusCycle() {
            statusIndex = 0;
            document.getElementById('loading-status').textContent = statusMessages[0];
            statusTimer = setInterval(() => {
                statusIndex = (statusIndex + 1) % statusMessages.length;
                document.getElementById('loading-status').textContent = statusMessages[statusIndex];
            }, 6000);
        }

        function stopStatusCycle() {
            if (statusTimer) {
                clearInterval(statusTimer);
                statusTimer = null;
            }
        }

        function copyPrompt() {
            const text = document.getElementById('prompt-used').textContent;
            navigator.clipboard.writeText(text).then(() => {
                const btn = document.querySelector('.copy-btn');
                btn.textContent = '✅ Copied!';
                setTimeout(() => btn.textContent = '📋 Copy Prompt', 2000);
            });
        }

        document.getElementById('thumbnailForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(e.target);
            const submitBtn = document.getElementById('submitBtn');
            const loading = document.getElementById('loading');
            const resultContainer = document.getElementById('result-container');
            const errorMsg = document.getElementById('error-message');

            submitBtn.disabled = true;
            loading.style.display = 'block';
            resultContainer.style.display = 'none';
            errorMsg.style.display = 'none';

            startStatusCycle();

            try {
                const response = await fetch("{{ route('thumbnail.create') }}", {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value
                    }
                });

                const result = await response.json();

                if (result.success) {
                    document.getElementById('thumbnail-preview').src = result.image_url;
                    document.getElementById('thumbnail-preview').style.display = 'block';
                    document.getElementById('prompt-used').textContent = result.prompt_used;
                    window.generatedImageBase64 = result.image_url;

                    // Show source badge
                    const badge = document.getElementById('source-badge');
                    if (result.source === 'php_compositor') {
                        badge.innerHTML = '✅ Thumbnail Generated (Real Face + Background)';
                        badge.style.color = '#6ee7b7';
                    } else if (result.source === 'gemini') {
                        badge.textContent = '✅ AI Generated (Gemini)';
                        badge.style.color = '#6ee7b7';
                    } else {
                        badge.textContent = '✅ AI Generated (Pollinations)';
                        badge.style.color = '#6ee7b7';
                    }

                    resultContainer.style.display = 'block';
                } else {
                    if (response.status === 429) {
                        errorMsg.textContent = 'Too many requests – please wait a minute and try again.';
                    } else {
                        errorMsg.textContent = result.error || 'Something went wrong.';
                    }
                    errorMsg.style.display = 'block';

                    if (result.prompt_used) {
                        document.getElementById('prompt-used').textContent = result.prompt_used;
                        document.getElementById('thumbnail-preview').style.display = 'none';
                        resultContainer.style.display = 'block';
                    }
                }

            } catch (err) {
                errorMsg.textContent = 'Server error occurred. Please try again.';
                errorMsg.style.display = 'block';
                console.error(err);
            } finally {
                stopStatusCycle();
                submitBtn.disabled = false;
                loading.style.display = 'none';
            }
        });

        function downloadImage() {
            if (!window.generatedImageBase64) return;
            const link = document.createElement('a');
            link.href = window.generatedImageBase64;
            link.download = 'ai-thumbnail-' + Date.now() + '.jpg';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>

</body>

</html>