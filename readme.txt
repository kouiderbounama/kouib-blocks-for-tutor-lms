=== Kouib Blocks for Tutor LMS ===
Contributors: kouiderbounama
Tags: tutor lms, courses, gutenberg, filter, carousel
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
Requires Plugins: tutor
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Professional Gutenberg blocks for Tutor LMS courses: category filter, carousel, boxes, quick search and platform stats. Full RTL support.

== Description ==

The **Kouib Blocks for Tutor LMS** package turns a static course listing into a fast, interactive experience inside the Gutenberg block editor — no complex setup. Add a block and display it anywhere.

**The five blocks:**

1. **Courses Filter** — a grid of courses with category buttons, custom ordering by student count, and a cache per settings combination. Works on the frontend and in the editor (ServerSideRender).
2. **Courses Carousel** — a responsive slider with side arrows, columns adjustable from the editor, and ordering by newest, most enrolled, or random.
3. **Course Category Boxes** — a grid of categories with a course counter and a link per category, plus a custom icon (PNG/SVG) per category uploaded straight from the media library.
4. **Quick Search** — an instant (AJAX) search field with live results and no page reload, featuring:
   - Full Arabic normalization: (أ/إ/آ/ٱ → ا), (ة → ه), (ى → ي) and diacritics removal, so searching "الحساب" also finds "المحاسبة".
   - Smart relevance ranking: title match > starts with the phrase > contains it.
   - A "match exact phrase" option and a two-letter minimum for words.
   - Form colors, size and shape adjustable from the editor, with results floating over the page when needed.
5. **Platform Stats** — prominent counters (icon + number + label) for students, instructors, courses and lessons, with a responsive grid (3 breakpoints) and a full panel to show/hide each statistic and its style.

**Guaranteed performance:**
- Transient cache for every piece (ready HTML or stat numbers) with an active-keys registry.
- Automatic invalidation on saving/deleting a course, on new student enrollments, or on rating updates.
- Light queries: no row-by-row enrollment counting; instructor stats via a single query.
- Each block's assets load only when the block is present — no extra CSS/JS on pages that don't need it.

**Translation & Arabic:**
- All strings use `__()` with the `kouib-blocks-for-tutor-lms` text domain and are ready for .po/.mo.
- Full RTL support and numbers formatted with `number_format_i18n`.
- Compatible with Kadence and other themes that respect theme.json.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from "Plugins → Add New → Upload Plugin" (zip file).
2. Activate the plugin from the "Plugins" screen.
3. Activate **Tutor LMS** (the plugin works without it, but shows zero courses).
4. Open the editor and add any block from the **Kouib Blocks** category at the top of the block inserter.
5. Configure each block from the sidebar settings panel, then publish the page.

**Optional tuning:** the HTML cache duration, via the filter:

    add_filter( 'kouib_cache_ttl', fn() => 2 * HOUR_IN_SECONDS );

== Frequently Asked Questions ==

= Does the plugin work without Tutor LMS? =

Yes, it activates and registers the blocks; but zero courses/lessons appear because the content types belong to Tutor. The students statistic relies on the total WordPress user count.

= Does it affect site speed? =

No; each block gets its own cache, data is computed with light SQL statements and stored in a Transient (30 minutes, adjustable via the filter above), and each block's assets load only when the block is used.

= How do I recompute student counts after changes? =

They are refreshed automatically on: saving/deleting a course, a new enrollment, or any `tutor_after_*` event — and the whole cache is flushed when the plugin is updated.

= Is there an API for programmatic customization? =

Yes: `kouib_cache_ttl` (duration), the shared render key `kouib_render_course_card`, and the reusable cache layer `kouib_register_active_key`/`kouib_flush_courses_cache`.

= Do I need coding skills to use it? =

No; everything happens in the editor: live preview, colors, columns, icons, show/hide elements.

== Source Code ==

The source code for this plugin is publicly available at:
https://github.com/kouiderbounama/kouib-blocks-for-tutor-lms

The source code for the compiled block editor scripts is also included in the `src/` directory of this plugin. Each block's `edit.jsx` file is the human-readable source that generates the corresponding `build/*/index.js` file.

To regenerate the compiled files:

1. Install Node.js 18+ and run `npm install` in the plugin root.
2. Run `npx wp-scripts build` to compile the `src/` directory into `build/`.
3. Run `node build-i18n.js` to regenerate translation files.

The vanilla JavaScript files in `assets/js/` (carousel.js, search.js, view.js) are hand-written source files and are not generated by build tools.

== Screenshots ==

1. Platform stats: icon + number + label.
2. Courses filter with category buttons.
3. Courses carousel.
4. Category boxes with counter and custom icons.
5. Quick search with instant results.

== Changelog ==

= Initial release =
* Five blocks for Tutor LMS: Courses Filter, Courses Carousel, Course Category Boxes, Quick Search and Platform Stats.
* Transient cache per piece with automatic invalidation and selective asset loading.
* Central settings panel, full RTL support, Arabic-tolerant search and a bundled Arabic translation.
* Clean uninstall (settings and cache removed on deletion).