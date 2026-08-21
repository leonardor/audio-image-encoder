<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audio CD Image Encoder/Decoder</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex flex-col items-center justify-center p-6">

    <div class="max-w-2xl w-full bg-slate-800 rounded-2xl shadow-xl p-8 border border-slate-700">
        <h1 class="text-3xl font-extrabold text-center bg-gradient-to-r from-cyan-400 to-fuchsia-500 bg-clip-text text-transparent mb-2">
            Cyber CD Encoder
        </h1>
        <p class="text-slate-400 text-center text-sm mb-8">Hide complete songs in colorful CD-shaped pixels</p>

        <!-- Alert messages -->
        <?php if ($message): ?>
            <div class="p-4 rounded-xl border <?php echo $messageType === 'success' ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20' : 'bg-rose-500/10 text-rose-300 border-rose-500/20'; ?>">
            <p class="font-medium text-sm"><?php echo $message; ?></p>
            
            <!-- PLAYER PENTRU PREVIEW AUDIO POST-DECODARE -->
            <?php if (!empty($downloadUrl)): ?>
                <div class="mt-4 p-3 bg-slate-900/60 rounded-lg border border-slate-700/50">
                    <p class="text-xs text-fuchsia-400 font-semibold mb-2 flex items-center gap-1">🎧 Listen to the decoded song directly from the image:</p>
                    <audio src="audio.php?file=<?php echo $downloadUrl; ?>" controls class="w-full h-10 accent-fuchsia-500" autoplay></audio>
                    <?php if (!empty($metadata['title']) || !empty($metadata['artist']) || !empty($metadata['album']) || !empty($metadata['year'])): ?>
                        <div class="mt-4 rounded-lg border border-emerald-500/20 bg-slate-900/70 p-4 text-left">
                            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-amber-300">MP3 metadata</h2>
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                            <?php if (!empty($metadata['title'])): ?><div><dt class="text-slate-500">Titlu</dt><dd class="text-slate-200"><?php echo htmlspecialchars($metadata['title'], ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
                            <?php if (!empty($metadata['artist'])): ?><div><dt class="text-slate-500">Artist</dt><dd class="text-slate-200"><?php echo htmlspecialchars($metadata['artist'], ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
                            <?php if (!empty($metadata['album'])): ?><div><dt class="text-slate-500">Album</dt><dd class="text-slate-200"><?php echo htmlspecialchars($metadata['album'], ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
                            <?php if (!empty($metadata['year'])): ?><div><dt class="text-slate-500">An</dt><dd class="text-slate-200"><?php echo htmlspecialchars($metadata['year'], ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
                            </dl>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($metadata['technical'])): ?>
                        <div class="mt-4 rounded-lg border border-emerald-500/20 bg-slate-900/70 p-4 text-left">
                            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-emerald-300">MP3 technical data</h2>
                            <dl class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                                <?php foreach ($metadata['technical'] as $key => $value): ?>
                                    <?php if ($value !== null && $value !== ''): ?><div class="flex min-w-0 items-center justify-between gap-3 border-b border-slate-700/60 pb-1"><dt class="truncate text-slate-500"><?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?></dt><dd class="text-right font-mono text-slate-200"><?php echo htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
                                <?php endforeach; ?>
                            </dl>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($metadata['encoding'])): ?>
                        <div class="mt-4 rounded-lg border border-cyan-500/20 bg-slate-900/70 p-4 text-left">
                            <div class="mb-3 flex items-center justify-between">
                                <h2 class="text-xs font-semibold uppercase tracking-wider text-cyan-300">Encoding configuration</h2>
                                <span class="rounded-full bg-cyan-500/10 px-2 py-1 text-[10px] text-cyan-300">XMP</span>
                            </div>
                            <dl class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                                <?php foreach ($metadata['encoding'] as $key => $value): ?>
                                    <div class="flex min-w-0 items-center justify-between gap-3 border-b border-slate-700/60 pb-1">
                                        <dt class="truncate text-slate-500"><?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?></dt>
                                        <dd class="text-right font-mono text-slate-200"><?php echo htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); ?></dd>
                                    </div>
                                <?php endforeach; ?>
                            </dl>
                        </div>
                    <?php endif; ?>
                    <div class="mt-3 text-right">
                        <a href="audio.php?file=<?php echo $downloadUrl; ?>" download class="inline-block bg-fuchsia-600 hover:bg-fuchsia-500 text-white text-xs font-bold px-4 py-2 rounded-lg transition-all shadow-lg shadow-fuchsia-600/20">
                            💾 Download the MP3 file
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ENCODED IMAGE DOWNLOAD -->
            <?php if (!empty($imageUrl)): ?>
                <div class="mt-3 text-right">
                    <p class="text-xs text-fuchsia-400 font-semibold mb-2 flex items-center gap-1">🎧 This is the generated image:</p>
                    <img src="image.php?file=<?php echo $imageUrl ?>" with="300" height="300"/>
                    <?php if (!empty($metadata['title']) || !empty($metadata['artist']) || !empty($metadata['album']) || !empty($metadata['year'])): ?>
                        <div class="mt-4 rounded-lg border border-emerald-500/20 bg-slate-900/70 p-4 text-left">
                            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-amber-300">MP3 metadata</h2>
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                                <?php foreach (['title' => 'Title', 'artist' => 'Artist', 'album' => 'Album', 'year' => 'Year'] as $key => $label): ?>
                                    <?php if (!empty($metadata[$key])): ?><div><dt class="text-slate-500"><?php echo $label; ?></dt><dd class="text-slate-200"><?php echo htmlspecialchars($metadata[$key], ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
                                <?php endforeach; ?>
                            </dl>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($metadata['technical'])): ?>
                        <div class="mt-4 rounded-lg border border-emerald-500/20 bg-slate-900/70 p-4 text-left">
                            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-emerald-300">MP3 technical data</h2>
                            <dl class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                                <?php foreach ($metadata['technical'] as $key => $value): ?>
                                    <?php if ($value !== null && $value !== ''): ?><div class="flex min-w-0 items-center justify-between gap-3 border-b border-slate-700/60 pb-1"><dt class="truncate text-slate-500"><?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?></dt><dd class="text-right font-mono text-slate-200"><?php echo htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
                                <?php endforeach; ?>
                            </dl>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($metadata['encoding'])): ?>
                        <div class="mt-4 rounded-lg border border-cyan-500/20 bg-slate-900/70 p-4 text-left">
                            <div class="mb-3 flex items-center justify-between">
                                <h2 class="text-xs font-semibold uppercase tracking-wider text-cyan-300">Encoding configuration</h2>
                                <span class="rounded-full bg-cyan-500/10 px-2 py-1 text-[10px] text-cyan-300">XMP</span>
                            </div>
                            <dl class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                                <?php foreach ($metadata['encoding'] as $key => $value): ?>
                                    <div class="flex min-w-0 items-center justify-between gap-3 border-b border-slate-700/60 pb-1">
                                        <dt class="truncate text-slate-500"><?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?></dt>
                                        <dd class="text-right font-mono text-slate-200"><?php echo htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); ?></dd>
                                    </div>
                                <?php endforeach; ?>
                            </dl>
                        </div>
                    <?php endif; ?>
                    <a href="image.php?file=<?php echo $imageUrl; ?>" download class="inline-block bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-bold px-4 py-2 rounded-lg transition-all shadow-lg shadow-cyan-600/20">
                        💾 Download the CD image (.webp)
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Tab navigation -->
        <div class="flex border-b border-slate-700 mb-6">
            <button onclick="switchTab('encode-tab')" id="btn-encode-tab" class="flex-1 py-3 text-center font-medium border-b-2 <?php echo $action === 'encode' ? 'border-cyan-500 text-cyan-400' : 'border-transparent text-slate-400'; ?> focus:outline-none transition-all cursor-pointer">
                🎵 Encode (Create CD)
            </button>
            <button onclick="switchTab('decode-tab')" id="btn-decode-tab" class="flex-1 py-3 text-center font-medium border-b-2 <?php echo $action === 'decode' ? 'border-fuchsia-500 text-fuchsia-400' : 'border-transparent text-slate-400'; ?> focus:outline-none transition-all cursor-pointer">
                🔍 Decode (Listen to CD)
            </button>
        </div>

        <!-- Encoding section -->
        <div id="encode-tab" class="tab-content <?php echo $action === 'encode' ? '' : 'hidden'; ?>">
            <form action="/" method="POST" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="action" value="encode">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Choose an audio file (MP3):</label>
                    <input type="file" id="audio_input" name="audio_file" accept=".mp3" required class="w-full text-sm text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-cyan-500/10 file:text-cyan-400 hover:file:bg-cyan-500/20 border border-slate-700 rounded-lg p-2 bg-slate-900/50">
                </div>
                
                <div id="audio_preview_container" class="hidden bg-slate-900/60 p-4 rounded-xl border border-slate-700/50">
                    <p class="text-xs text-cyan-400 font-semibold mb-2">🎵 Audio preview before upload:</p>
                    <audio id="audio_preview" controls class="w-full h-10 accent-cyan-500"></audio>
                </div>

                <button type="submit" class="w-full bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white font-bold py-3 px-4 rounded-xl transition-all shadow-lg shadow-cyan-500/10 cursor-pointer">
                    Generate WebP image
                </button>
            </form>
        </div>

        <!-- Decoding section -->
        <div id="decode-tab" class="tab-content <?php echo ($action === 'decode') ? '' : 'hidden'; ?>">
            <form action="index.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="action" value="decode">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Upload the CD image (WebP):</label>
                    <input type="file" id="image_input" name="image_file" accept=".webp" required class="w-full text-sm text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-fuchsia-500/10 file:text-fuchsia-400 hover:file:bg-fuchsia-500/20 border border-slate-700 rounded-lg p-2 bg-slate-900/50">
                </div>

                <div id="image_preview_container" class="hidden flex flex-col items-center bg-slate-900/60 p-4 rounded-xl border border-slate-700/50">
                    <p class="text-xs text-fuchsia-400 font-semibold mb-3 self-start">🖼️ Selected image preview:</p>
                    <img id="image_preview" src="" alt="Preview CD" class="w-48 h-48 object-cover rounded-full border-2 border-fuchsia-500 shadow-lg shadow-fuchsia-500/10">
                </div>

                <button type="submit" class="w-full bg-gradient-to-r from-fuchsia-500 to-purple-600 hover:from-fuchsia-400 hover:to-purple-500 text-white font-bold py-3 px-4 rounded-xl transition-all shadow-lg shadow-fuchsia-500/10 cursor-pointer">
                    Decode to MP3
                </button>
            </form>
        </div>

    </div>

    <!-- JAVASCRIPT FOR TABS AND LOCAL PREVIEWS BEFORE UPLOAD -->
    <script>
        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.getElementById(tabId).classList.remove('hidden');
            const btnEncode = document.getElementById('btn-encode-tab');
            const btnDecode = document.getElementById('btn-decode-tab');

            if (tabId === 'encode-tab') {
                btnEncode.className = "flex-1 py-3 text-center font-medium border-b-2 border-cyan-500 text-cyan-400 focus:outline-none transition-all cursor-pointer";
                btnDecode.className = "flex-1 py-3 text-center font-medium border-b-2 border-transparent text-slate-400 hover:text-slate-200 focus:outline-none transition-all cursor-pointer";
            } else if (tabId === 'decode-tab') {
                btnEncode.className = "flex-1 py-3 text-center font-medium border-b-2 border-transparent text-slate-400 hover:text-slate-200 focus:outline-none transition-all cursor-pointer";
                btnDecode.className = "flex-1 py-3 text-center font-medium border-b-2 border-fuchsia-500 text-fuchsia-400 focus:outline-none transition-all cursor-pointer";
            } else {
                btnEncode.className = "flex-1 py-3 text-center font-medium border-b-2 border-transparent text-slate-400 hover:text-slate-200 focus:outline-none transition-all cursor-pointer";
                btnDecode.className = "flex-1 py-3 text-center font-medium border-b-2 border-transparent text-slate-400 hover:text-slate-200 focus:outline-none transition-all cursor-pointer";
            }
        }

        // Local MP3 preview before upload
        document.getElementById('audio_input').addEventListener('change', function(e) {
            if(e.target.files[0]) {
                document.getElementById('audio_preview').src = URL.createObjectURL(e.target.files[0]);
                document.getElementById('audio_preview_container').classList.remove('hidden');
            }
        });

        // Local WebP preview before upload
        document.getElementById('image_input').addEventListener('change', function(e) {
            if(e.target.files[0]) {
                document.getElementById('image_preview').src = URL.createObjectURL(e.target.files[0]);
                document.getElementById('image_preview_container').classList.remove('hidden');
            }
        });
    </script>
</body>
</html>