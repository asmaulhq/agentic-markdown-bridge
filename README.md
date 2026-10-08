cat > README.md <<'EOF'
# Agentic Markdown Bridge

Agentic Markdown Bridge makes WordPress content easier for AI agents, LLMs, and machine-readable workflows to consume by exposing clean Markdown representations of public content and providing practical `llms.txt` tools.

## Features

- Clean `/index.md` representations for WordPress pages, posts, and public custom post types
- Automatic Markdown discovery with `rel="alternate"`
- Optional `rel="describedby"` support for `llms.txt`
- Built-in `llms.txt` editor and virtual endpoint
- Safe editing of an existing physical `llms.txt`
- Editable local `llms.txt` generator
- AI-assisted `llms.txt` prompt generation for ChatGPT, Claude, Gemini, and Perplexity
- Per-page Markdown enable/disable controls
- Custom Markdown overrides
- Automatic H1 handling
- Optional removal of content before the first H1
- Configurable exclusion classes such as `.no-markdown`
- Front-end “View Markdown” button
- ETag and Last-Modified support
- Markdown caching
- Diagnostics tools
- WP-CLI commands
- Multisite support
- No telemetry
- No required external API
- No API keys required

## Example

A WordPress page:

```text
https://example.com/about/
