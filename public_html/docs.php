<?php
// Define path to the markdown file
$file = __DIR__ . '/../private/docs/API.md';
$content = file_exists($file) ? file_get_contents($file) : '# Erreur: Documentation introuvable';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentation API ColiXpress</title>
    <!-- GitHub Markdown CSS for styling -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/github-markdown-css/5.2.0/github-markdown-light.min.css">
    <style>
        html {
            scroll-behavior: smooth;
        }
        body {
            box-sizing: border-box;
            min-width: 200px;
            max-width: 980px;
            margin: 0 auto;
            padding: 45px;
            font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;
            background-color: #fff;
        }
        @media (max-width: 767px) {
            body {
                padding: 15px;
            }
        }
        .markdown-body {
            background-color: transparent;
        }
        /* Ensure tables are scrollable on mobile */
        .markdown-body table {
            display: block;
            width: 100%;
            overflow: auto;
        }
    </style>
</head>
<body>
    <article class="markdown-body" id="content">
        <!-- Content will be rendered here -->
        <div style="text-align: center; padding: 50px; color: #666;">
            Chargement de la documentation...
        </div>
    </article>

    <!-- Hidden element to store the raw markdown content safely -->
    <script id="markdown-source" type="text/plain"><?php echo htmlspecialchars($content); ?></script>

    <!-- Marked.js for parsing Markdown -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const markdown = document.getElementById('markdown-source').textContent;
            const contentDiv = document.getElementById('content');
            
            // Configure marked options
            // gfm: true enables GitHub Flavored Markdown (tables, task lists, etc.)
            marked.use({
                gfm: true,
                breaks: true
            });

            try {
                // Render markdown to HTML
                contentDiv.innerHTML = marked.parse(markdown);
                
                // Handle hash navigation if present in URL after rendering
                if (window.location.hash) {
                    const id = window.location.hash.substring(1);
                    const element = document.getElementById(id);
                    if (element) {
                        setTimeout(() => element.scrollIntoView(), 100);
                    }
                }
            } catch (e) {
                contentDiv.innerHTML = '<p style="color:red; font-weight:bold;">Erreur lors du rendu de la documentation.</p>';
                console.error(e);
            }
        });
    </script>
</body>
</html>
