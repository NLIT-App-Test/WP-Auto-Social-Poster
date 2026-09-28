# Credits

Admun Growth Engine is licensed GPL-2.0-or-later. It includes code adapted from these GPL-2.0+ plugins:

- **Bit Social Pro** by Bit Apps (https://bitapps.pro), GPL-2.0+ — publishing flows for X, Instagram,
  Threads, Pinterest, Bluesky, Telegram, Discord, Tumblr and Google Business Profile (API request shapes,
  media container polling, image loading/multipart upload). Reworked to use the site owner's own OAuth apps
  instead of the Bit Apps auth broker. See `src/Social/Platform.php` and `src/Social/Platforms/`.
- **AI Puffer (formerly AI Power)** by Senol Sahin (https://aipower.org), GPL-2.0+ — SEO plugin detection and
  storage (Yoast, Rank Math, AIOSEO including its `aioseo_posts` table, The SEO Framework), the on-page check
  set, and the featured-image sources. See `src/SEO/SeoBridge.php`, `src/SEO/Checks.php`, `src/Agents/Media.php`.

No trademarks, branding, licensing SDKs (Freemius) or hosted services of those products are used.
