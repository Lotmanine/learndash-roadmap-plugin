# LearnDash Roadmap Enhanced

A custom WordPress plugin that turns a LearnDash course catalog into a **visual, gamified roadmap** and wires it into a real membership/CRM stack: **LearnDash + Memberium + Keap**.

> Adapted from a real client engagement. Client name, domain, author, and all client data have been removed. The code is the deliverable; this is a sanitized showcase copy.

## What it does

- **Visual roadmap** of levels and items built on custom post types (`roadmap_item`) and a `roadmap_level` taxonomy.
- **Ownership-aware access gating.** A single source of truth (`is_roadmap_item_owned()`) decides whether a user owns an item through two paths:
  1. LearnDash course access (default), or
  2. a Memberium/Keap tag check (fallback for items that are not LearnDash courses).
- **CRM tag automation.** When a user completes a level (100% progress), the plugin applies the configured **Keap tag through Memberium** (`[memb_set_tag]` via `do_shortcode()`), so access flows through the site's existing Keap to Memberium to LearnDash chain instead of bypassing it.
- **External / "owned URL" items.** Roadmap items can point at non-LearnDash destinations; `get_course_or_item_url()` resolves the right URL based on ownership (course content vs. sale page).
- **Elementor integration without code in the builder.** `body_class` and `post_class` filters inject state classes (`level-completed` / `level-pending`, `rm-item-owned` / `rm-item-not-owned`, `has-bonus-course`, etc.) so designers toggle visibility purely with CSS / Elementor conditions.
- **3-state bonus buttons** ("Locked" / "Claim Your Reward" / "Go To Course") driven by completion state AND bonus ownership.
- **Admin UX**: tooltip-documented fields on roadmap items and level taxonomy (bonus tag ID, ownership tag ID, owned URL, display-name overrides).

## Why it is interesting engineering

- Integrates **three systems** (LMS, membership, CRM) and keeps a single, consolidated ownership model rather than scattering access checks.
- CRM writes go **through the existing access pipeline** (Keap tags via Memberium), not around it, so a level completion produces the same downstream behavior as any other tag grant.
- Front-end visibility is data-driven through `body_class`/`post_class`, keeping logic in PHP and presentation in the page builder.
- Full changelog in the plugin header documents the evolution from direct LearnDash enrollment to the tag-based CRM flow.

## Stack

WordPress · PHP 8.x · LearnDash · Memberium · Keap (CRM) · Elementor · custom post types & taxonomies · shortcodes

## Install

Copy `ld-roadmap.php` into `wp-content/plugins/learndash-roadmap-plugin/` (or drop it as a single-file plugin) and activate. Requires LearnDash and Memberium for full functionality.

## Author

Built by **Lotfi Otmanine**, senior WordPress developer at [OLDEV](https://oldev.site).

- Website: [oldev.site](https://oldev.site)
- Hire me on Upwork: [upwork.com/freelancers/~01bd748fcb665b90ca](https://www.upwork.com/freelancers/~01bd748fcb665b90ca)
- LinkedIn: [linkedin.com/in/lotfi-otmanine](https://www.linkedin.com/in/lotfi-otmanine)
- GitHub: [github.com/Lotmanine](https://github.com/Lotmanine)

## License

GPL-2.0-or-later.
