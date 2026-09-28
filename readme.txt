=== Admun Growth Engine ===
Contributors: admun
Tags: ai content, seo, social media auto post, content calendar, ai agents
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tell it a topic, a goal and how long to keep posting. A team of AI agents plans, writes, illustrates and checks every piece; you approve; it publishes to your site and social networks on schedule.

== How it works ==

1. **You** give a topic, the goal you want to reach, the period, posts per week, and which social accounts to use.
2. **Planner agent** (Claude) understands the goal and splits the period into a strategy and a checklist: one piece per posting date, each with a keyword, an objective and an angle. **You approve the plan** (or ask for a different one).
3. A few days before each date, the **content agent** (Claude) writes an SEO article plus a post for each network, and the **media agent** creates the image (OpenAI image, falling back to a Pexels stock photo).
4. The **reviewer agent** checks the piece: SEO rules in code (keyword placement, meta lengths, headings, links, length) and Jev (TypeSafe) for goal match, accuracy, search intent, quality and risk. If it falls short, the writer revises (up to 2 times).
5. The **main agent** brings the finished piece to you. **You approve**, edit, request changes, or skip.
6. On its date the article goes live on your site with SEO meta and schema, and the social posts go out to every chosen account.

Networks: X, LinkedIn, Facebook Pages, Instagram, Threads, Pinterest, Bluesky, Google Business Profile, Telegram, Discord, Tumblr, Mastodon, and anything else through a webhook (Zapier / Make / n8n).

== Setup ==

1. Growth Engine → Settings: add your Anthropic and TypeSafe keys (plus OpenAI for AI images and/or Pexels for stock photos). Click "Test Claude & Jev".
2. Fill in your brand; every agent uses it.
3. Connect social accounts. Login-button networks use your own developer app: register the redirect URL shown on the Settings screen in each app.
4. For reliable timing, call wp-cron.php from a real cron job every minute.

== Notes ==

* SEO meta is written into Yoast, Rank Math, AIOSEO or The SEO Framework when present; otherwise the plugin prints its own meta tags and Article schema. FAQ and HowTo schema are always added.
* If a network does not answer in time, the post is marked "may have posted" and is never retried automatically, to avoid duplicates.
* Keys and tokens are encrypted with your site's salts.

== Changelog ==

= 2.0.0 =
* Rebuilt around goal-driven campaigns with planner, content, media, reviewer and main agents and user approval at plan and post level.
