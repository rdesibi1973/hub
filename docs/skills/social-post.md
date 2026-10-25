---
name: social-post
description: Prepare and schedule social posts for Savannah Explorers and The Orangi Collection on Facebook, Instagram, TikTok, YouTube and LinkedIn: right brand and hashtags, Tuesday blog, Thursday/Saturday photo/video posts, TikTok and YouTube Shorts, composer steps.
---

# Social posts (Facebook, Instagram, TikTok, YouTube, LinkedIn)

Use when Roberto asks for social posts ("post Facebook", "post Instagram", "TikTok", "YouTube", "LinkedIn", "programma i post", blog of the week, photo/video post), or types /social-post.
Talk to Roberto in Italian; posts are in English.

Before every social session read the Project doc `claude/SOCIAL_PLAYBOOK.md` (account IDs, step-by-step procedures for Meta, TikTok and YouTube, past mistakes — it is the detailed reference; this file is the short checklist). Calendar and status of every post: `claude/SOCIAL_FB_2026Q4.md`; post texts: `claude/SOCIAL_FOTO_POST_2026-10.md` — update both at the end.

## 1. Brand first (the most common mistake)

| Brand | What goes there | Hashtag |
|---|---|---|
| Savannah Explorers (FB + IG @savannahexplorers, TikTok @savannahexplorers, YouTube youtube.com/savannahexplorers = channel "Savannah Explorers - Tour Operator" @Savannahexplorers, ID UCgJqzK6k2rUOrVlgD7eCM8A; LinkedIn page SAVANNAH EXPLORERS LIMITED, linkedin.com/company/6229406) | blog, safaris, sightings, guests on safari, travel info (visa, insurance) | only #SavannahExplorers |
| The Orangi Collection (FB + IG @theorangicollection, TikTok @theorangicollection) | anything about the lodges: Kifaru, Marera, Orangi River, Arusha Explorers Lodge (including the hide and bush dinners) | only #TheOrangiCollection |

- Never cross-tag the two brands. Never use the separate "Serengeti Kifaru Tented Lodge" page.
- Before scheduling, check the active account: "Post to" in Meta, the profile name in TikTok (tiktok.com/profile), the channel in YouTube Studio.
- YouTube: the same Google account has several channels and Studio opens the last one used. Before every upload check the channel name top left and the URL (`/channel/UCgJqzK6k2rUOrVlgD7eCM8A`). Never upload to "Roberto Safari Tanzania" (in Oct 2026 three Shorts went there by mistake).

## 2. Cadence

- Tuesday 10:00 EAT: Savannah Explorers English blog article (savannahexplorers.net/blog), Facebook only. Texts from Stefano (mypresslab) or written by Claude from the article.
- Thursday 10:00 EAT: one photo/video post for Savannah Explorers and one for The Orangi Collection, FB + IG.
- Saturday 10:00 EAT: a second photo/video post for The Orangi Collection, FB + IG.
- TikTok and YouTube Shorts: the same vertical videos, at 18:00 EAT.
- LinkedIn (trade audience): only Stefano's video series (from the Things to Know article), with his LinkedIn text, Thursday 10:00 EAT, same day as the FB/IG Reel.
- Scheduling windows: Meta 20 minutes to 29 days ahead; TikTok about 10 days ahead. Beyond that, save as draft or note it in the calendar and tell Roberto.

## 3. Before proposing: already published?

- Check where each video/photo has already gone out: Meta → Published (scroll the list to the end, it loads in pieces), TikTok Studio → Posts, YouTube Studio → Content.
- The same video can go on another platform, never twice on the same one.

## 4. Material

- Photos/videos come from the WhatsApp group "Marketing Team The Orangi Collection"; Roberto saves them to `H:\My Drive\Social\<Savannah Explorers | The Orangi Collection>\<folder>`.
- Look at every photo/video before writing (contact sheet, duration, orientation). Without the PC link, download from Drive with the Google Drive connector, one file at a time (max 10 MB) — playbook §6.
- Recognizable guests need Roberto's ok (guest consent). Third-party footage (e.g. a TV clip with its logo) needs the owner's permission.

## 5. Texts

- Facebook: narrative prose, 3–5 hashtags. Instagram: short caption + hashtag block. TikTok: one or two lines + 5–6 hashtags. YouTube: title + description ending with the standard Savannah Explorers block (playbook / texts doc).
- Nothing alarming for clients (malaria, mosquitoes, drowning stats, safety warnings).
- Location: if the exact park or lodge is not certain, ask Roberto or just write "Tanzania".
- Show Roberto all texts and dates and get his ok before scheduling.
- Typing in composers: real click in the field, check `document.activeElement`, type the body, then each hashtag in a separate type call with a trailing space; never paste everything at once or use execCommand. Read the text back before scheduling.

## 6. Scheduling

Use Claude in Chrome (Claude uploads files ≤ 10 MB itself from `/mnt/user-data/uploads/social/`) or the built-in browser; Roberto selects only files over 10 MB. One tab per platform: never reuse the tab of a composer with a file uploaded until that post is scheduled (the upload is lost). Follow the playbook step by step:
- Meta (§4, §6): same exact size for all carousel photos; vertical videos in the Reels composer (Instagram max 60 s); dates first, then hours with arrow keys, verify 10 / 0 / AM; "Schedule", never "Share now".
- TikTok Studio (§7): set "Schedule", date and time FIRST, then write the description (TikTok drops hashtags written before), re-read it, then "Schedule". Never click "Post now"; if the content check hangs, switch off "Content check lite". Scheduled TikTok posts cannot be edited.
- YouTube Studio (§7): check the channel first; Shorts, "not made for kids", Visibility → Schedule, time zone GMT+3 Local Time; wait until the upload is complete before leaving the tab.
- LinkedIn (playbook §7): Start a post → Video → Roberto uploads → Next → text → clock icon → date from the calendar, time from the list (East Africa Time) → Next → Schedule; stay on the page until "Your upload is complete".
- Permanent deletions (YouTube "Delete forever", etc.) are done by Roberto; Claude can set a wrong post to Private.
After scheduling, reload each platform's scheduled list (scroll to the end) and verify date, time, platform and brand of every post.

## 7. Close

Tell Roberto in Italian what is scheduled (brand, platform, date/time, title) and anything left open. Update the calendar and texts docs, add any new mistake to `SOCIAL_PLAYBOOK.md` §5, and update Hub memo #36 (ext_key `social-fb-blog-2026q4`): via Agent API from the PC, or via the Hub Memo Board in Chrome (Edit → text in the Quill editor, next step in `#m_next_title`, Save).
