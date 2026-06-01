# WordPress Ops Tools

Practical tools and notes for independent WordPress, WooCommerce, and social commerce operations.

## Projects

- `wc-manual-pix-ultimate` - WooCommerce manual Pix payment gateway enhancements and documentation.
- `tiktok-tools/angrybird` - TikTok video data tooling with a desktop/Streamlit-style interface.
- `tiktok-tools/scraper` - Product and TikTok research dashboard built with Vite/React.
- `tiktok-tools/video-pipeline` - Local short-video and image workflow scripts.

## Security

This public version intentionally excludes local secrets and generated artifacts:

- `.env` files
- API keys and cookies
- generated media
- dependency folders such as `node_modules`
- local ffmpeg binaries
- caches, logs, and backup files

Use the included `.env.example` files as templates and keep real credentials local.
