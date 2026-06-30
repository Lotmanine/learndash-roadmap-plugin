<?php
/**
 * Plugin Name:       LearnDash Roadmap Enhanced
 * Plugin URI:        https://github.com/Lotmanine/learndash-roadmap-plugin
 * Description:       A visual, gamified roadmap for LearnDash courses with Memberium + Keap (CRM) integration: ownership-aware access gating, CRM tag automation on level completion, and Elementor-friendly body/post classes.
 * Version:           1.7.1
 * Author:            Lotfi O.
 * Author URI:        https://oldev.site
 * License:           GPL-2.0-or-later
 * Text Domain:       ld-roadmap
 *
 * Changelog:
 *
 * 1.7.1 — 2026-04-07
 * - Added: "Owned URL" field on roadmap items for external content destinations.
 * - Added: Tooltip descriptions on all admin fields (items + bonus courses).
 * - Changed: get_course_or_item_url() now checks _owned_url before falling back
 *   to the item permalink, enabling proper navigation for non-LearnDash items.
 *
 * 1.7.0 — 2026-04-08
 * - Removed: Direct LearnDash auto-enrollment (ld_update_course_access) for
 *   bonus courses. All access now flows through the existing Keap → Memberium
 *   → LearnDash chain for consistency with the site's existing access model.
 * - Added: Keap tag application via Memberium when a level is completed. Uses
 *   [memb_set_tag] shortcode through do_shortcode() to apply the configured
 *   bonus tag. Triggers the normal access flow instead of bypassing it.
 * - Added: "Bonus Tag ID (Keap)" field on roadmap level taxonomy for storing
 *   the tag ID to apply on level completion.
 * - Added: "Ownership Tag ID (Keap)" field on roadmap_item CPT for items that
 *   are not LearnDash courses. The plugin checks this tag via Memberium as a
 *   fallback when no LearnDash course is linked.
 * - Improved: is_roadmap_item_owned() now supports two ownership paths —
 *   LearnDash course access (default) or Memberium tag check (fallback for
 *   non-LearnDash items).
 *
 * 1.6.0 — 2026-04-05
 * - Added: Auto-enrollment for bonus courses. When a user reaches 100%
 *   progress on a level and a bonus LearnDash course is assigned, the
 *   plugin automatically enrolls them via ld_update_course_access().
 *   Note: This grants LearnDash access directly — no Keap tag is created.
 *   If Keap tag sync is needed later, a Keap API integration can be added.
 * - Changed: "Bonus Sale Page URL" label renamed to "Bonus Course URL"
 *   in the admin UI. Internal meta key unchanged for backwards compatibility.
 * - Improved: Bonus URL resolution now falls back to course permalink
 *   when no custom URL is set.
 * - Improved: Added comprehensive PHPDoc comments and section headers
 *   throughout the entire plugin for maintainability.
 * - Fixed: has-bonus-course body class now checks for bonus URL in addition
 *   to bonus course ID, so the bonus section displays when only a URL is set.
 *
 * 1.5.0 — 2026-04-05
 * - Added: is_bonus_course_owned() helper to check if user owns the bonus course.
 * - Added: roadmap_level_bonus_button_text shortcode for 3-state button labels
 *   (Mastery Bonus Locked / Claim Your Reward / Go To Course).
 * - Added: bonus-owned / bonus-not-owned body classes on roadmap_level archives.
 * - Added: CSS visibility rules for bonus-owned-btn / bonus-not-owned-btn.
 * - Improved: handle_roadmap_level_bonus_text_shortcode() now returns three
 *   different messages based on level completion AND bonus ownership.
 * - Improved: handle_roadmap_level_bonus_course_shortcode() info="url" now
 *   returns course permalink when user owns the bonus, sale URL otherwise.
 *
 * 1.4.1 — 2026-03-31
 * - Added: JavaScript alert on bonus section button click when level is
 *   pending. Shows "Complete this level to get this bonus" and prevents
 *   navigation.
 *
 * 1.4.0 — 2026-03-31
 * - Added: Optional "Bonus Display Name" and "Bonus Display Description"
 *   override fields on roadmap_level taxonomy. When filled, the shortcode
 *   uses these instead of the linked LearnDash course title/excerpt.
 *
 * 1.3.0 — 2026-03-30
 * - Added: body_class filter injects level-completed / level-pending and
 *   has-bonus-course / no-bonus-course on roadmap_level archive pages.
 * - Added: CSS visibility rules for .bonus-locked-btn / .bonus-unlocked-btn
 *   and .bonus-section based on body classes.
 *
 * 1.2.0 — 2026-03-30
 * - Added: post_class filter injects rm-item-owned / rm-item-not-owned on
 *   roadmap_item loop wrappers for Elementor visibility toggling.
 * - Added: Frontend CSS (wp_head) hides .owned-btn / .not-owned-btn based
 *   on parent ownership class. Only loads on roadmap pages.
 *
 * 1.1.0 — 2026-03-30
 * - Fixed: roadmap_item_url now checks course ownership before returning URL;
 *   owned users are sent to course content, not the sales page.
 * - Fixed: Smart quote encoding issue in roadmap_level_message "LET'S GET STARTED" string.
 * - Improved: Consolidated duplicate ownership logic; get_roadmap_access_state()
 *   now delegates to is_roadmap_item_owned() as the single source of truth.
 * - Added: get_course_or_item_url() helper for consistent URL resolution.
 *
 * 1.0.0 — Initial release by previous developer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =========================================================================
 * Main Plugin Class
 * ========================================================================= */

/**
 * Class LD_RoadMap_Core
 *
 * Core class for the LearnDash Road Map Enhanced plugin. Handles:
 * - Custom Post Type (roadmap_item) and Taxonomy (roadmap_level) registration
 * - Admin meta boxes for linking LearnDash courses to roadmap items
 * - Taxonomy meta fields for bonus courses, display overrides, and URLs
 * - Ownership detection and progress calculation per level
 * - Keap tag application via Memberium when a level is completed
 * - Frontend shortcodes for dynamic content in Elementor templates
 * - Frontend CSS for conditional visibility (owned/locked states)
 * - Frontend JS for bonus section modal alerts
 */
class LD_RoadMap_Core {

	/* =====================================================================
	 * Constructor & Hook Registration
	 * ===================================================================== */

	/**
	 * Register all WordPress hooks, actions, filters, and shortcodes.
	 *
	 * This is the single entry point that wires the plugin into WordPress.
	 * Each hook is grouped by concern: CPT/taxonomy, admin UI, frontend
	 * filters, frontend assets, and shortcodes.
	 */
	public function __construct() {

		// --- CPT & Taxonomy ---
		add_action( 'init', array( $this, 'register_roadmap_structures' ) );

		// --- Admin meta boxes for roadmap_item ---
		add_action( 'add_meta_boxes', array( $this, 'add_item_meta_boxes' ) );
		add_action( 'save_post_roadmap_item', array( $this, 'save_item_meta_data' ) );

		// --- Admin taxonomy fields for roadmap_level ---
		add_action( 'roadmap_level_add_form_fields', array( $this, 'render_level_bonus_course_add_field' ) );
		add_action( 'roadmap_level_edit_form_fields', array( $this, 'render_level_bonus_course_edit_field' ) );
		add_action( 'created_roadmap_level', array( $this, 'save_level_bonus_course_meta' ) );
		add_action( 'edited_roadmap_level', array( $this, 'save_level_bonus_course_meta' ) );

		// --- Frontend filters (post classes, body classes) ---
		add_filter( 'post_class', array( $this, 'add_roadmap_item_post_classes' ), 10, 3 );
		add_filter( 'body_class', array( $this, 'add_roadmap_level_body_classes' ) );

		// --- Frontend CSS & JS ---
		add_action( 'wp_head', array( $this, 'print_roadmap_visibility_css' ) );
		add_action( 'wp_footer', array( $this, 'print_roadmap_bonus_alert_js' ) );

		// --- Shortcodes: Item-level ---
		add_shortcode( 'roadmap_access_icon', array( $this, 'handle_roadmap_access_icon_shortcode' ) );
		add_shortcode( 'roadmap_access_class', array( $this, 'handle_roadmap_access_class_shortcode' ) );
		add_shortcode( 'roadmap_item_url', array( $this, 'handle_roadmap_item_url_shortcode' ) );
		add_shortcode( 'roadmap_course_table', array( $this, 'render_course_table_shortcode' ) );
		add_shortcode( 'roadmap_item_button_text', array( $this, 'handle_roadmap_item_button_text_shortcode' ) );
		add_shortcode( 'roadmap_item_sale_page_url', array( $this, 'handle_roadmap_item_sale_page_url_shortcode' ) );

		// --- Shortcodes: Level-level ---
		add_shortcode( 'roadmap_level_progress', array( $this, 'handle_roadmap_level_progress_shortcode' ) );
		add_shortcode( 'roadmap_level_message', array( $this, 'handle_roadmap_level_message_shortcode' ) );
		add_shortcode( 'roadmap_level_bonus_text', array( $this, 'handle_roadmap_level_bonus_text_shortcode' ) );
		add_shortcode( 'roadmap_level_bonus_button_text', array( $this, 'handle_roadmap_level_bonus_button_text_shortcode' ) );
		add_shortcode( 'roadmap_level_status_class', array( $this, 'handle_roadmap_level_status_class_shortcode' ) );
		add_shortcode( 'roadmap_level_progress_bar_text', array( $this, 'handle_roadmap_level_progress_bar_text_shortcode' ) );
		add_shortcode( 'roadmap_level_bonus_course', array( $this, 'handle_roadmap_level_bonus_course_shortcode' ) );
		add_shortcode( 'roadmap_level_has_bonus_course', array( $this, 'handle_roadmap_level_has_bonus_course_shortcode' ) );
		add_shortcode( 'roadmap_level_bonus_sale_page_url', array( $this, 'handle_roadmap_level_bonus_sale_page_url_shortcode' ) );
	}

	/* =====================================================================
	 * CPT & Taxonomy Registration
	 * ===================================================================== */

	/**
	 * Register the roadmap_item custom post type and roadmap_level taxonomy.
	 *
	 * - roadmap_item: Each post represents one course/product bundle in the roadmap.
	 * - roadmap_level: Hierarchical taxonomy that groups items into levels (e.g. "Level 1").
	 *
	 * Hooked to: init
	 */
	public function register_roadmap_structures() {
		$item_labels = array(
			'name'               => __( 'RoadMap Items', 'ld-roadmap' ),
			'singular_name'      => __( 'RoadMap Item', 'ld-roadmap' ),
			'menu_name'          => __( 'RoadMap Items', 'ld-roadmap' ),
			'name_admin_bar'     => __( 'RoadMap Item', 'ld-roadmap' ),
			'add_new'            => __( 'Add New Road Map Item', 'ld-roadmap' ),
			'add_new_item'       => __( 'Add New Road Map Item', 'ld-roadmap' ),
			'new_item'           => __( 'New Road Map Item', 'ld-roadmap' ),
			'edit_item'          => __( 'Edit Road Map Item', 'ld-roadmap' ),
			'view_item'          => __( 'View Road Map Item', 'ld-roadmap' ),
			'all_items'          => __( 'All Road Map Items', 'ld-roadmap' ),
			'search_items'       => __( 'Search Road Map Items', 'ld-roadmap' ),
			'parent_item_colon'  => __( 'Parent Road Map Items:', 'ld-roadmap' ),
			'not_found'          => __( 'No Road Map Items found.', 'ld-roadmap' ),
			'not_found_in_trash' => __( 'No Road Map Items found in Trash.', 'ld-roadmap' ),
		);

		$item_args = array(
			'labels'          => $item_labels,
			'public'          => true,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'query_var'       => true,
			'rewrite'         => array( 'slug' => 'roadmap-item' ),
			'capability_type' => 'post',
			'has_archive'     => true,
			'hierarchical'    => false,
			'menu_position'   => 5,
			'menu_icon'       => 'dashicons-location-alt',
			'supports'        => array( 'title', 'editor', 'thumbnail', 'custom-fields', 'excerpt' ),
			'show_in_rest'    => true,
		);

		register_post_type( 'roadmap_item', $item_args );

		$tax_labels = array(
			'name'              => __( 'RoadMap Levels', 'ld-roadmap' ),
			'singular_name'     => __( 'RoadMap Level', 'ld-roadmap' ),
			'search_items'      => __( 'Search RoadMap Levels', 'ld-roadmap' ),
			'all_items'         => __( 'All RoadMap Levels', 'ld-roadmap' ),
			'parent_item'       => __( 'Parent RoadMap Level', 'ld-roadmap' ),
			'parent_item_colon' => __( 'Parent RoadMap Level:', 'ld-roadmap' ),
			'edit_item'         => __( 'Edit RoadMap Level', 'ld-roadmap' ),
			'update_item'       => __( 'Update RoadMap Level', 'ld-roadmap' ),
			'add_new_item'      => __( 'Add New RoadMap Level', 'ld-roadmap' ),
			'new_item_name'     => __( 'New RoadMap Level Name', 'ld-roadmap' ),
			'menu_name'         => __( 'RoadMap Levels', 'ld-roadmap' ),
		);

		register_taxonomy(
			'roadmap_level',
			array( 'roadmap_item' ),
			array(
				'hierarchical'      => true,
				'labels'            => $tax_labels,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'show_in_rest'      => true,
				'show_in_nav_menus' => true,
				'rewrite'           => array( 'slug' => 'roadmap-level' ),
			)
		);
	}

	/* =====================================================================
	 * Admin Meta Boxes — Item Level
	 * ===================================================================== */

	/**
	 * Register meta boxes on the roadmap_item edit screen.
	 *
	 * Two meta boxes:
	 * 1. "Linked LearnDash Courses" — checkbox list to link LD courses to this item.
	 * 2. "Sale Page URL" — optional URL for users who don't own the item.
	 *
	 * Hooked to: add_meta_boxes
	 */
	public function add_item_meta_boxes() {
		add_meta_box(
			'item_courses_selection',
			__( 'Linked LearnDash Courses', 'ld-roadmap' ),
			array( $this, 'render_item_course_selection_html' ),
			'roadmap_item',
			'normal',
			'high'
		);

		add_meta_box(
			'item_sale_page_url',
			__( 'Sale Page URL', 'ld-roadmap' ),
			array( $this, 'render_item_sale_page_url_meta_box' ),
			'roadmap_item',
			'side',
			'default'
		);

		add_meta_box(
			'item_ownership_tag_id',
			__( 'Ownership Tag ID (Keap)', 'ld-roadmap' ),
			array( $this, 'render_item_ownership_tag_meta_box' ),
			'roadmap_item',
			'side',
			'default'
		);

		add_meta_box(
			'item_owned_url',
			__( 'Owned URL', 'ld-roadmap' ),
			array( $this, 'render_item_owned_url_meta_box' ),
			'roadmap_item',
			'side',
			'default'
		);
	}

	/**
	 * Render the "Linked LearnDash Courses" meta box.
	 *
	 * Displays a searchable checkbox list of all published LD courses.
	 * Selected courses are stored in post meta '_linked_course_ids' as an array.
	 * Ownership of a roadmap_item is determined by whether the user has access
	 * to ALL linked courses.
	 *
	 * @param WP_Post $post Current post being edited.
	 */
	public function render_item_course_selection_html( $post ) {
		wp_nonce_field( 'save_roadmap_item_courses', 'roadmap_item_nonce' );

		$linked_courses = get_post_meta( $post->ID, '_linked_course_ids', true );
		if ( ! is_array( $linked_courses ) ) {
			$linked_courses = array();
		}

		$courses = get_posts(
			array(
				'post_type'   => 'sfwd-courses',
				'numberposts' => -1,
				'post_status' => 'publish',
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		if ( empty( $courses ) ) {
			echo '<p>' . esc_html__( 'No LearnDash courses found.', 'ld-roadmap' ) . '</p>';
			return;
		}
		?>
		<style>
			.roadmap-search-container { margin-bottom: 15px; padding-top: 10px; }
			#roadmap-course-search {
				width: 100%;
				padding: 10px;
				border: 1px solid #ccc;
				border-radius: 4px;
				box-shadow: inset 0 1px 2px rgba(0,0,0,0.07);
			}
			.roadmap-course-list {
				max-height: 350px;
				overflow-y: auto;
				background: #fff;
				border: 1px solid #ddd;
				padding: 10px;
				border-radius: 4px;
			}
			.roadmap-course-item {
				padding: 6px 0;
				display: flex;
				align-items: center;
				border-bottom: 1px solid #f0f0f0;
			}
			.roadmap-course-item:last-child { border-bottom: none; }
			.roadmap-course-item.hidden { display: none; }
			.roadmap-course-item input { margin-right: 12px; cursor: pointer; }
			.roadmap-course-item label { cursor: pointer; font-size: 13px; flex: 1; }
			.no-results-found { color: #888; padding: 10px; font-style: italic; }
		</style>

		<div class="roadmap-search-container">
			<input
				type="text"
				id="roadmap-course-search"
				placeholder="<?php echo esc_attr__( 'Type course name to filter...', 'ld-roadmap' ); ?>"
				autocomplete="off"
			/>
		</div>

		<div class="roadmap-course-list" id="roadmap-list-container">
			<?php foreach ( $courses as $course ) : ?>
				<?php
				$course_id    = absint( $course->ID );
				$course_title = get_the_title( $course_id );
				$is_checked   = in_array( $course_id, $linked_courses, true ) ? 'checked="checked"' : '';
				?>
				<div class="roadmap-course-item" data-title="<?php echo esc_attr( strtolower( $course_title ) ); ?>">
					<input
						type="checkbox"
						name="linked_course_ids[]"
						id="course_<?php echo esc_attr( $course_id ); ?>"
						value="<?php echo esc_attr( $course_id ); ?>"
						<?php echo $is_checked; ?>
					/>
					<label for="course_<?php echo esc_attr( $course_id ); ?>">
						<?php echo esc_html( $course_title ); ?>
						<span style="color: #999; font-size: 11px;">
							(<?php echo esc_html__( 'ID:', 'ld-roadmap' ); ?> <?php echo esc_html( $course_id ); ?>)
						</span>
					</label>
				</div>
			<?php endforeach; ?>

			<div id="no-results" class="no-results-found" style="display:none;">
				<?php echo esc_html__( 'No courses match your search.', 'ld-roadmap' ); ?>
			</div>
		</div>

		<script>
			(function() {
				const searchInput = document.getElementById('roadmap-course-search');
				const items = document.querySelectorAll('.roadmap-course-item');
				const noResults = document.getElementById('no-results');

				if (!searchInput || !items.length || !noResults) {
					return;
				}

				searchInput.addEventListener('input', function() {
					const query = this.value.toLowerCase().trim();
					let matchFound = false;

					items.forEach(function(item) {
						const title = item.getAttribute('data-title') || '';

						if (title.includes(query)) {
							item.classList.remove('hidden');
							matchFound = true;
						} else {
							item.classList.add('hidden');
						}
					});

					noResults.style.display = matchFound ? 'none' : 'block';
				});
			})();
		</script>

		<p class="description">
			<?php echo esc_html__( 'Select the LearnDash course(s) this item represents. The plugin uses this to check ownership via sfwd_lms_has_access(). Leave empty if the item is not a LearnDash course.', 'ld-roadmap' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the "Sale Page URL" meta box on the roadmap_item sidebar.
	 *
	 * This URL is used when a user does NOT own the item — the Buy button
	 * links here instead of the LearnDash course page.
	 * Stored in post meta: '_sale_page_url'
	 *
	 * @param WP_Post $post Current post being edited.
	 */
	public function render_item_sale_page_url_meta_box( $post ) {
		$sale_page_url = get_post_meta( $post->ID, '_sale_page_url', true );
		?>
		<p>
			<label for="roadmap-item-sale-page-url">
				<?php esc_html_e( 'Enter the sale page URL for this item.', 'ld-roadmap' ); ?>
			</label>
		</p>
		<input
			type="url"
			id="roadmap-item-sale-page-url"
			name="sale_page_url"
			value="<?php echo esc_attr( $sale_page_url ); ?>"
			placeholder="https://example.com/sale-page"
			style="width:100%;"
		/>
		<p class="description">
			<?php esc_html_e( 'The purchase/sales page URL. When a user does NOT own this item, the Buy button links here. Should always be set.', 'ld-roadmap' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the "Ownership Tag ID (Keap)" meta box on the roadmap_item sidebar.
	 *
	 * Used for roadmap items that are NOT LearnDash courses (e.g. external pages,
	 * charting platforms, downloads). The plugin checks this Keap tag via Memberium
	 * to determine ownership instead of checking LearnDash access.
	 * Leave blank for regular LearnDash items.
	 *
	 * Stored in post meta: '_ownership_tag_id'
	 *
	 * Added in v1.7.0.
	 *
	 * @param WP_Post $post Current post being edited.
	 */
	public function render_item_ownership_tag_meta_box( $post ) {
		$ownership_tag_id = absint( get_post_meta( $post->ID, '_ownership_tag_id', true ) );
		?>
		<p>
			<label for="roadmap-item-ownership-tag-id">
				<?php esc_html_e( 'Keap Tag ID for ownership check.', 'ld-roadmap' ); ?>
			</label>
		</p>
		<input
			type="number"
			id="roadmap-item-ownership-tag-id"
			name="ownership_tag_id"
			value="<?php echo $ownership_tag_id > 0 ? esc_attr( $ownership_tag_id ) : ''; ?>"
			placeholder="e.g. 1234"
			style="width:100%;"
			min="0"
			step="1"
		/>
		<p class="description">
			<?php esc_html_e( 'The Keap tag ID used to check ownership via Memberium for non-LearnDash items. The plugin calls memb_hasAnyTags() with this ID. Leave empty if the item uses a Linked LearnDash Course instead.', 'ld-roadmap' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the "Owned URL" meta box on the roadmap_item sidebar.
	 *
	 * Used for roadmap items that are NOT LearnDash courses. When the user
	 * owns the item, the Review button links here instead of the course page.
	 * For LearnDash items, leave blank — the plugin uses the course permalink.
	 *
	 * Stored in post meta: '_owned_url'
	 *
	 * Added in v1.7.1.
	 *
	 * @param WP_Post $post Current post being edited.
	 */
	public function render_item_owned_url_meta_box( $post ) {
		$owned_url = get_post_meta( $post->ID, '_owned_url', true );
		?>
		<p>
			<label for="roadmap-item-owned-url">
				<?php esc_html_e( 'Owned URL', 'ld-roadmap' ); ?>
			</label>
		</p>
		<input
			type="url"
			id="roadmap-item-owned-url"
			name="owned_url"
			value="<?php echo esc_attr( $owned_url ); ?>"
			placeholder="https://example.com/member-content"
			style="width:100%;"
		/>
		<p class="description">
			<?php esc_html_e( 'Where the Review button links when a user owns this item. Use this for external content (charting tools, downloads, etc.) that lives outside WordPress. Leave empty if the item has a Linked LearnDash Course — the plugin will use the course permalink automatically.', 'ld-roadmap' ); ?>
		</p>
		<?php
	}

	/**
	 * Save roadmap_item meta data when the post is saved.
	 *
	 * Saves four fields:
	 * - '_linked_course_ids' (array of ints) — LearnDash course IDs linked to this item.
	 * - '_sale_page_url' (string) — Optional sale page URL for non-owners.
	 * - '_ownership_tag_id' (int) — Optional Keap tag ID for non-LearnDash ownership check.
	 * - '_owned_url' (string) — Optional URL for owned non-LearnDash items (v1.7.1).
	 *
	 * Includes standard WordPress security checks: revision, autosave,
	 * capability, and nonce verification.
	 *
	 * Hooked to: save_post_roadmap_item
	 *
	 * @param int $post_id The roadmap_item post ID being saved.
	 */
	public function save_item_meta_data( $post_id ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['roadmap_item_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['roadmap_item_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'save_roadmap_item_courses' ) ) {
			return;
		}

		// Save linked course IDs.
		if ( isset( $_POST['linked_course_ids'] ) && is_array( $_POST['linked_course_ids'] ) ) {
			$sanitized_ids = array_map( 'absint', wp_unslash( $_POST['linked_course_ids'] ) );
			$sanitized_ids = array_filter( $sanitized_ids );
			update_post_meta( $post_id, '_linked_course_ids', array_values( $sanitized_ids ) );
		} else {
			update_post_meta( $post_id, '_linked_course_ids', array() );
		}

		// Save sale page URL.
		if ( isset( $_POST['sale_page_url'] ) ) {
			$sale_page_url = esc_url_raw( wp_unslash( $_POST['sale_page_url'] ) );

			if ( ! empty( $sale_page_url ) ) {
				update_post_meta( $post_id, '_sale_page_url', $sale_page_url );
			} else {
				delete_post_meta( $post_id, '_sale_page_url' );
			}
		}

		// v1.7.0: Save ownership tag ID.
		if ( isset( $_POST['ownership_tag_id'] ) ) {
			$ownership_tag_id = absint( wp_unslash( $_POST['ownership_tag_id'] ) );

			if ( $ownership_tag_id > 0 ) {
				update_post_meta( $post_id, '_ownership_tag_id', $ownership_tag_id );
			} else {
				delete_post_meta( $post_id, '_ownership_tag_id' );
			}
		}

		// v1.7.1: Save owned URL.
		if ( isset( $_POST['owned_url'] ) ) {
			$owned_url = esc_url_raw( wp_unslash( $_POST['owned_url'] ) );

			if ( ! empty( $owned_url ) ) {
				update_post_meta( $post_id, '_owned_url', $owned_url );
			} else {
				delete_post_meta( $post_id, '_owned_url' );
			}
		}
	}

	/* =====================================================================
	 * Admin Meta Boxes — Level (Taxonomy) Level
	 * ===================================================================== */

	/**
	 * Render bonus course fields on the "Add New RoadMap Level" screen.
	 *
	 * Displays:
	 * - Radio list to select one bonus LearnDash course (with search filter)
	 * - Override fields for display name and description
	 * - Bonus Course URL field
	 *
	 * Hooked to: roadmap_level_add_form_fields
	 */
	public function render_level_bonus_course_add_field() {
		$courses = get_posts(
			array(
				'post_type'   => 'sfwd-courses',
				'numberposts' => -1,
				'post_status' => 'publish',
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		?>
		<div class="form-field term-bonus-course-wrap">
			<label for="roadmap-level-bonus-course-search"><?php esc_html_e( 'Bonus LearnDash Course', 'ld-roadmap' ); ?></label>

			<input
				type="text"
				id="roadmap-level-bonus-course-search"
				placeholder="<?php echo esc_attr__( 'Type course name to filter...', 'ld-roadmap' ); ?>"
				autocomplete="off"
			/>

			<div class="roadmap-level-bonus-course-list" style="max-height:240px; overflow-y:auto; border:1px solid #ccd0d4; background:#fff; padding:10px; margin-top:8px;">
				<p>
					<label>
						<input type="radio" name="bonus_course_id" value="0" checked="checked" />
						<?php esc_html_e( 'No bonus course', 'ld-roadmap' ); ?>
					</label>
				</p>

				<?php if ( ! empty( $courses ) ) : ?>
					<?php foreach ( $courses as $course ) : ?>
						<?php
						$course_id    = absint( $course->ID );
						$course_title = get_the_title( $course_id );
						?>
						<p class="roadmap-level-bonus-course-item" data-title="<?php echo esc_attr( strtolower( $course_title ) ); ?>">
							<label>
								<input type="radio" name="bonus_course_id" value="<?php echo esc_attr( $course_id ); ?>" />
								<?php echo esc_html( $course_title ); ?>
								<span style="color:#777;">(ID: <?php echo esc_html( $course_id ); ?>)</span>
							</label>
						</p>
					<?php endforeach; ?>
				<?php endif; ?>

				<p id="roadmap-level-bonus-no-results" style="display:none; color:#777;">
					<?php esc_html_e( 'No courses match your search.', 'ld-roadmap' ); ?>
				</p>
			</div>

			<!-- v1.7.0: Bonus Tag ID field -->
			<div class="form-field term-bonus-tag-id-wrap" style="margin-top:12px;">
				<label for="roadmap-level-bonus-tag-id">
					<?php esc_html_e( 'Bonus Tag ID (Keap)', 'ld-roadmap' ); ?>
				</label>
				<input
					type="number"
					id="roadmap-level-bonus-tag-id"
					name="bonus_tag_id"
					value=""
					placeholder="e.g. 1234"
					min="0"
					step="1"
				/>
				<p class="description">
					<?php esc_html_e( 'The Keap tag ID the plugin applies via Memberium when a user completes this level at 100%. Required when the bonus needs to grant access (both LearnDash courses and external tools). Leave empty only if the bonus is a simple URL with no access control.', 'ld-roadmap' ); ?>
				</p>
			</div>

			<div class="form-field term-bonus-display-name-wrap" style="margin-top:12px;">
				<label for="roadmap-level-bonus-display-name">
					<?php esc_html_e( 'Bonus Display Name (override)', 'ld-roadmap' ); ?>
				</label>
				<input
					type="text"
					id="roadmap-level-bonus-display-name"
					name="bonus_display_name"
					value=""
					placeholder="<?php echo esc_attr__( 'Leave blank to use the LearnDash course title', 'ld-roadmap' ); ?>"
				/>
				<p class="description">
					<?php esc_html_e( 'Override the bonus course title shown on the frontend. Leave blank to use the linked course title.', 'ld-roadmap' ); ?>
				</p>
			</div>

			<div class="form-field term-bonus-display-desc-wrap" style="margin-top:12px;">
				<label for="roadmap-level-bonus-display-desc">
					<?php esc_html_e( 'Bonus Display Description (override)', 'ld-roadmap' ); ?>
				</label>
				<textarea
					id="roadmap-level-bonus-display-desc"
					name="bonus_display_desc"
					rows="3"
					placeholder="<?php echo esc_attr__( 'Leave blank to use the LearnDash course excerpt', 'ld-roadmap' ); ?>"
				></textarea>
				<p class="description">
					<?php esc_html_e( 'Override the bonus course description shown on the frontend. Leave blank to use the linked course excerpt.', 'ld-roadmap' ); ?>
				</p>
			</div>

			<!-- v1.6.0: Label changed from "Bonus Sale Page URL" to "Bonus Course URL". Meta key unchanged. -->
			<div class="form-field term-bonus-sale-page-url-wrap" style="margin-top:12px;">
				<label for="roadmap-level-bonus-sale-page-url">
					<?php esc_html_e( 'Bonus Course URL', 'ld-roadmap' ); ?>
				</label>
				<input
					type="url"
					id="roadmap-level-bonus-sale-page-url"
					name="bonus_sale_page_url"
					value=""
					placeholder="https://example.com/bonus-course-page"
				/>
				<p class="description">
					<?php esc_html_e( 'The URL where the bonus button links. For external bonuses, this is the destination. For LearnDash bonuses, this overrides the default course permalink. Leave empty to use the course permalink.', 'ld-roadmap' ); ?>
				</p>
			</div>

			<p class="description">
				<?php esc_html_e( 'Select the LearnDash course awarded as a bonus when this level is completed. Leave empty if the bonus is an external URL only.', 'ld-roadmap' ); ?>
			</p>
		</div>

		<script>
			(function() {
				const searchInput = document.getElementById('roadmap-level-bonus-course-search');
				const items = document.querySelectorAll('.roadmap-level-bonus-course-item');
				const noResults = document.getElementById('roadmap-level-bonus-no-results');

				if (!searchInput || !noResults) {
					return;
				}

				searchInput.addEventListener('input', function() {
					const query = this.value.toLowerCase().trim();
					let matchFound = false;

					items.forEach(function(item) {
						const title = item.getAttribute('data-title') || '';

						if (title.includes(query)) {
							item.style.display = '';
							matchFound = true;
						} else {
							item.style.display = 'none';
						}
					});

					noResults.style.display = matchFound ? 'none' : 'block';
				});
			})();
		</script>
		<?php
	}

	/**
	 * Render bonus course fields on the "Edit RoadMap Level" screen.
	 *
	 * Same fields as the Add screen, but pre-populated with saved values.
	 * Uses <tr> table rows to match the WordPress taxonomy edit form layout.
	 *
	 * Hooked to: roadmap_level_edit_form_fields
	 *
	 * @param WP_Term $term The taxonomy term being edited.
	 */
	public function render_level_bonus_course_edit_field( $term ) {
		$selected_bonus_course = absint( get_term_meta( $term->term_id, '_bonus_course_id', true ) );
		$bonus_sale_page_url   = get_term_meta( $term->term_id, '_bonus_sale_page_url', true );
		$bonus_tag_id          = absint( get_term_meta( $term->term_id, '_bonus_tag_id', true ) );

		$courses = get_posts(
			array(
				'post_type'   => 'sfwd-courses',
				'numberposts' => -1,
				'post_status' => 'publish',
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		?>
		<tr class="form-field term-bonus-course-wrap">
			<th scope="row">
				<label for="roadmap-level-bonus-course-search"><?php esc_html_e( 'Bonus LearnDash Course', 'ld-roadmap' ); ?></label>
			</th>
			<td>
				<input
					type="text"
					id="roadmap-level-bonus-course-search"
					placeholder="<?php echo esc_attr__( 'Type course name to filter...', 'ld-roadmap' ); ?>"
					autocomplete="off"
					style="width:100%; max-width:420px;"
				/>

				<div class="roadmap-level-bonus-course-list" style="max-height:260px; overflow-y:auto; border:1px solid #ccd0d4; background:#fff; padding:10px; margin-top:8px; max-width:420px;">
					<p>
						<label>
							<input type="radio" name="bonus_course_id" value="0" <?php checked( 0, $selected_bonus_course ); ?> />
							<?php esc_html_e( 'No bonus course', 'ld-roadmap' ); ?>
						</label>
					</p>

					<?php if ( ! empty( $courses ) ) : ?>
						<?php foreach ( $courses as $course ) : ?>
							<?php
							$course_id    = absint( $course->ID );
							$course_title = get_the_title( $course_id );
							?>
							<p class="roadmap-level-bonus-course-item" data-title="<?php echo esc_attr( strtolower( $course_title ) ); ?>">
								<label>
									<input type="radio" name="bonus_course_id" value="<?php echo esc_attr( $course_id ); ?>" <?php checked( $selected_bonus_course, $course_id ); ?> />
									<?php echo esc_html( $course_title ); ?>
									<span style="color:#777;">(ID: <?php echo esc_html( $course_id ); ?>)</span>
								</label>
							</p>
						<?php endforeach; ?>
					<?php endif; ?>

					<p id="roadmap-level-bonus-no-results" style="display:none; color:#777;">
						<?php esc_html_e( 'No courses match your search.', 'ld-roadmap' ); ?>
					</p>
				</div>

				<p class="description">
					<?php esc_html_e( 'Select the LearnDash course awarded as a bonus when this level is completed. Leave empty if the bonus is an external URL only.', 'ld-roadmap' ); ?>
				</p>
			</td>
		</tr>

		<!-- v1.7.0: Bonus Tag ID field -->
		<tr class="form-field term-bonus-tag-id-wrap">
			<th scope="row">
				<label for="roadmap-level-bonus-tag-id">
					<?php esc_html_e( 'Bonus Tag ID (Keap)', 'ld-roadmap' ); ?>
				</label>
			</th>
			<td>
				<input
					type="number"
					id="roadmap-level-bonus-tag-id"
					name="bonus_tag_id"
					value="<?php echo $bonus_tag_id > 0 ? esc_attr( $bonus_tag_id ) : ''; ?>"
					placeholder="e.g. 1234"
					style="width:100%; max-width:420px;"
					min="0"
					step="1"
				/>
				<p class="description">
					<?php esc_html_e( 'The Keap tag ID the plugin applies via Memberium when a user completes this level at 100%. Required when the bonus needs to grant access (both LearnDash courses and external tools). Leave empty only if the bonus is a simple URL with no access control.', 'ld-roadmap' ); ?>
				</p>
			</td>
		</tr>

		<?php
		$bonus_display_name = get_term_meta( $term->term_id, '_bonus_display_name', true );
		$bonus_display_desc = get_term_meta( $term->term_id, '_bonus_display_desc', true );
		?>
		<tr class="form-field term-bonus-display-name-wrap">
			<th scope="row">
				<label for="roadmap-level-bonus-display-name">
					<?php esc_html_e( 'Bonus Display Name (override)', 'ld-roadmap' ); ?>
				</label>
			</th>
			<td>
				<input
					type="text"
					id="roadmap-level-bonus-display-name"
					name="bonus_display_name"
					value="<?php echo esc_attr( $bonus_display_name ); ?>"
					placeholder="<?php echo esc_attr__( 'Leave blank to use the LearnDash course title', 'ld-roadmap' ); ?>"
					style="width:100%; max-width:420px;"
				/>
				<p class="description">
					<?php esc_html_e( 'Override the bonus course title shown on the frontend. Leave blank to use the linked course title.', 'ld-roadmap' ); ?>
				</p>
			</td>
		</tr>

		<tr class="form-field term-bonus-display-desc-wrap">
			<th scope="row">
				<label for="roadmap-level-bonus-display-desc">
					<?php esc_html_e( 'Bonus Display Description (override)', 'ld-roadmap' ); ?>
				</label>
			</th>
			<td>
				<textarea
					id="roadmap-level-bonus-display-desc"
					name="bonus_display_desc"
					rows="3"
					placeholder="<?php echo esc_attr__( 'Leave blank to use the LearnDash course excerpt', 'ld-roadmap' ); ?>"
					style="width:100%; max-width:420px;"
				><?php echo esc_textarea( $bonus_display_desc ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'Override the bonus course description shown on the frontend. Leave blank to use the linked course excerpt.', 'ld-roadmap' ); ?>
				</p>
			</td>
		</tr>

		<!-- v1.6.0: Label changed from "Bonus Sale Page URL" to "Bonus Course URL". Meta key unchanged. -->
		<tr class="form-field term-bonus-sale-page-url-wrap">
			<th scope="row">
				<label for="roadmap-level-bonus-sale-page-url">
					<?php esc_html_e( 'Bonus Course URL', 'ld-roadmap' ); ?>
				</label>
			</th>
			<td>
				<input
					type="url"
					id="roadmap-level-bonus-sale-page-url"
					name="bonus_sale_page_url"
					value="<?php echo esc_attr( $bonus_sale_page_url ); ?>"
					placeholder="https://example.com/bonus-course-page"
					style="width:100%; max-width:420px;"
				/>
				<p class="description">
					<?php esc_html_e( 'The URL where the bonus button links. For external bonuses, this is the destination. For LearnDash bonuses, this overrides the default course permalink. Leave empty to use the course permalink.', 'ld-roadmap' ); ?>
				</p>
			</td>
		</tr>

		<script>
			(function() {
				const searchInput = document.getElementById('roadmap-level-bonus-course-search');
				const items = document.querySelectorAll('.roadmap-level-bonus-course-item');
				const noResults = document.getElementById('roadmap-level-bonus-no-results');

				if (!searchInput || !noResults) {
					return;
				}

				searchInput.addEventListener('input', function() {
					const query = this.value.toLowerCase().trim();
					let matchFound = false;

					items.forEach(function(item) {
						const title = item.getAttribute('data-title') || '';

						if (title.includes(query)) {
							item.style.display = '';
							matchFound = true;
						} else {
							item.style.display = 'none';
						}
					});

					noResults.style.display = matchFound ? 'none' : 'block';
				});
			})();
		</script>
		<?php
	}

	/**
	 * Save bonus course term meta when a roadmap_level is created or updated.
	 *
	 * Saves five fields from $_POST:
	 * - '_bonus_course_id'      — The selected LearnDash course ID (0 = none).
	 * - '_bonus_display_name'   — Optional override for the bonus course title.
	 * - '_bonus_display_desc'   — Optional override for the bonus course description.
	 * - '_bonus_sale_page_url'  — Optional URL for the bonus course page.
	 * - '_bonus_tag_id'         — Optional Keap tag ID for bonus access via Memberium.
	 *
	 * Hooked to: created_roadmap_level, edited_roadmap_level
	 *
	 * @param int $term_id The term ID being saved.
	 */
	public function save_level_bonus_course_meta( $term_id ) {
		if ( isset( $_POST['bonus_course_id'] ) ) {
			$bonus_course_id = absint( wp_unslash( $_POST['bonus_course_id'] ) );

			if ( $bonus_course_id > 0 ) {
				update_term_meta( $term_id, '_bonus_course_id', $bonus_course_id );
			} else {
				delete_term_meta( $term_id, '_bonus_course_id' );
			}
		}

		if ( isset( $_POST['bonus_display_name'] ) ) {
			$bonus_display_name = sanitize_text_field( wp_unslash( $_POST['bonus_display_name'] ) );

			if ( ! empty( $bonus_display_name ) ) {
				update_term_meta( $term_id, '_bonus_display_name', $bonus_display_name );
			} else {
				delete_term_meta( $term_id, '_bonus_display_name' );
			}
		}

		if ( isset( $_POST['bonus_display_desc'] ) ) {
			$bonus_display_desc = sanitize_textarea_field( wp_unslash( $_POST['bonus_display_desc'] ) );

			if ( ! empty( $bonus_display_desc ) ) {
				update_term_meta( $term_id, '_bonus_display_desc', $bonus_display_desc );
			} else {
				delete_term_meta( $term_id, '_bonus_display_desc' );
			}
		}

		if ( isset( $_POST['bonus_sale_page_url'] ) ) {
			$bonus_sale_page_url = esc_url_raw( wp_unslash( $_POST['bonus_sale_page_url'] ) );

			if ( ! empty( $bonus_sale_page_url ) ) {
				update_term_meta( $term_id, '_bonus_sale_page_url', $bonus_sale_page_url );
			} else {
				delete_term_meta( $term_id, '_bonus_sale_page_url' );
			}
		}

		// v1.7.0: Save bonus tag ID.
		if ( isset( $_POST['bonus_tag_id'] ) ) {
			$bonus_tag_id = absint( wp_unslash( $_POST['bonus_tag_id'] ) );

			if ( $bonus_tag_id > 0 ) {
				update_term_meta( $term_id, '_bonus_tag_id', $bonus_tag_id );
			} else {
				delete_term_meta( $term_id, '_bonus_tag_id' );
			}
		}
	}

	/* =====================================================================
	 * Access & Ownership Logic
	 * ===================================================================== */

	/**
	 * Get access state for the current roadmap item.
	 *
	 * Convenience wrapper that returns 'owned' or 'not_owned'.
	 * Used by the roadmap_access_icon and roadmap_access_class shortcodes.
	 *
	 * @return string 'owned' or 'not_owned'
	 */
	private function get_roadmap_access_state() {
		return $this->is_roadmap_item_owned( $this->get_current_post_id() ) ? 'owned' : 'not_owned';
	}

	/**
	 * Check whether the current user owns a roadmap item.
	 *
	 * Supports two ownership paths:
	 * 1. LearnDash course access (default) — if '_linked_course_ids' is set,
	 *    checks sfwd_lms_has_access() for ALL linked courses.
	 * 2. Memberium tag fallback (v1.7.0) — if no linked courses but
	 *    '_ownership_tag_id' is set, checks memb_hasAnyTags() for the tag.
	 * 3. If neither is set, returns false (not owned).
	 *
	 * @param int $item_id The roadmap_item post ID to check.
	 * @return bool True if user owns the item, false otherwise.
	 */
	private function is_roadmap_item_owned( $item_id ) {
		$item_id    = absint( $item_id );
		$course_ids = get_post_meta( $item_id, '_linked_course_ids', true );
		$user_id    = get_current_user_id();

		if ( ! is_user_logged_in() ) {
			return false;
		}

		// Path 1: LearnDash course access check (existing behavior).
		if ( ! empty( $course_ids ) && is_array( $course_ids ) ) {
			if ( ! function_exists( 'sfwd_lms_has_access' ) ) {
				return false;
			}

			foreach ( $course_ids as $course_id ) {
				$course_id = absint( $course_id );

				if ( ! $course_id || ! sfwd_lms_has_access( $course_id, $user_id ) ) {
					return false;
				}
			}

			return true;
		}

		// Path 2: Memberium tag fallback for non-LearnDash items (v1.7.0).
		$ownership_tag_id = absint( get_post_meta( $item_id, '_ownership_tag_id', true ) );

		if ( $ownership_tag_id > 0 ) {
			if ( ! function_exists( 'memb_hasAnyTags' ) ) {
				return false;
			}

			return (bool) memb_hasAnyTags( array( $ownership_tag_id ) );
		}

		// Neither LearnDash courses nor ownership tag set.
		return false;
	}

	/**
	 * Check whether the current user owns the bonus course for a level.
	 *
	 * Uses sfwd_lms_has_access() to verify the user has LearnDash access
	 * to the bonus course linked to this level.
	 *
	 * Added in v1.5.0.
	 *
	 * @param int $term_id Optional roadmap_level term ID. Defaults to current.
	 * @return bool True if user has access to the bonus course.
	 */
	private function is_bonus_course_owned( $term_id = 0 ) {
		$term_id = $term_id ? absint( $term_id ) : $this->get_current_roadmap_level_term_id();

		if ( ! $term_id || ! is_user_logged_in() ) {
			return false;
		}

		$bonus_course_id = $this->get_level_bonus_course_id( $term_id );

		if ( ! $bonus_course_id || ! function_exists( 'sfwd_lms_has_access' ) ) {
			return false;
		}

		return sfwd_lms_has_access( $bonus_course_id, get_current_user_id() );
	}

	/* =====================================================================
	 * Progress Calculation
	 * ===================================================================== */

	/**
	 * Calculate the completion percentage for a roadmap level.
	 *
	 * Queries all published roadmap_items in the given level, checks how
	 * many the current user owns, and returns a 0-100 percentage.
	 *
	 * @param int $term_id The roadmap_level term ID.
	 * @return int Percentage (0-100) of items owned in this level.
	 */
	private function calculate_level_progress_percentage( $term_id ) {
		$term_id = absint( $term_id );

		if ( ! $term_id ) {
			return 0;
		}

		$items = get_posts(
			array(
				'post_type'      => 'roadmap_item',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => 'roadmap_level',
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( empty( $items ) ) {
			return 0;
		}

		$total_items = count( $items );
		$owned_items = 0;

		foreach ( $items as $item_id ) {
			if ( $this->is_roadmap_item_owned( $item_id ) ) {
				$owned_items++;
			}
		}

		return (int) round( ( $owned_items / $total_items ) * 100 );
	}

	/* =====================================================================
	 * Keap Tag Application via Memberium
	 * ===================================================================== */

	/**
	 * Apply a Keap tag via Memberium when the user completes a level.
	 *
	 * Called from add_roadmap_level_body_classes() after progress is calculated
	 * but before body classes are added. This ensures the tag is applied before
	 * the page renders, so shortcodes see the updated state.
	 *
	 * The tag triggers the existing Keap → Memberium → LearnDash access flow,
	 * keeping all access control consistent with the site's existing model.
	 *
	 * Conditions (ALL must be true for the tag to be applied):
	 * 1. User is logged in
	 * 2. A "Bonus Tag ID" is configured for this level
	 * 3. Level progress is 100%
	 * 4. User does NOT already have the tag (avoids redundant API calls)
	 * 5. Memberium functions are available
	 *
	 * Added in v1.7.0. Replaces maybe_auto_enroll_bonus_course() from v1.6.0.
	 *
	 * @param int $term_id    The roadmap_level term ID.
	 * @param int $percentage The calculated progress percentage (0-100).
	 */
	private function maybe_apply_bonus_tag( $term_id, $percentage ) {
		// Only apply tag when level is fully completed.
		if ( $percentage < 100 ) {
			return;
		}

		// Must be logged in.
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Must have a bonus tag ID configured.
		$bonus_tag_id = absint( get_term_meta( $term_id, '_bonus_tag_id', true ) );
		if ( ! $bonus_tag_id ) {
			return;
		}

		// Check if user already has the tag (avoid redundant API calls).
		if ( function_exists( 'memb_hasAnyTags' ) && memb_hasAnyTags( array( $bonus_tag_id ) ) ) {
			return;
		}

		// Apply the tag via Memberium's [memb_set_tag] shortcode.
		if ( shortcode_exists( 'memb_set_tag' ) ) {
			do_shortcode( '[memb_set_tag tag_id=' . $bonus_tag_id . ']' );
		}
	}

	/* =====================================================================
	 * Frontend Filters (Post Classes, Body Classes)
	 * ===================================================================== */

	/**
	 * Add ownership CSS class to each roadmap_item's post wrapper div.
	 *
	 * Adds either 'rm-item-owned' or 'rm-item-not-owned' to the post_class
	 * output. Used by Elementor's Loop Grid to conditionally show/hide
	 * owned-btn, not-owned-btn, and the lock overlay via CSS.
	 *
	 * Hooked to: post_class (priority 10, 3 args)
	 *
	 * @param array $classes Existing CSS classes on the post wrapper.
	 * @param array $class   Additional classes passed to post_class().
	 * @param int   $post_id The post ID.
	 * @return array Modified classes array.
	 */
	public function add_roadmap_item_post_classes( $classes, $class, $post_id ) {
		if ( 'roadmap_item' !== get_post_type( $post_id ) ) {
			return $classes;
		}

		$classes[] = $this->is_roadmap_item_owned( $post_id ) ? 'rm-item-owned' : 'rm-item-not-owned';

		return $classes;
	}

	/**
	 * Add level status and bonus availability classes to the <body> tag
	 * on roadmap_level taxonomy archive pages.
	 *
	 * Classes added:
	 * - 'level-completed' or 'level-pending' (based on progress %)
	 * - 'has-bonus-course' or 'no-bonus-course' (based on bonus course or URL)
	 * - 'bonus-owned' or 'bonus-not-owned' (based on bonus course ownership)
	 *
	 * Also applies Keap bonus tag via maybe_apply_bonus_tag()
	 * when progress reaches 100%.
	 *
	 * Hooked to: body_class
	 *
	 * @param array $classes Existing body CSS classes.
	 * @return array Modified classes array.
	 */
	public function add_roadmap_level_body_classes( $classes ) {
		if ( ! is_tax( 'roadmap_level' ) ) {
			return $classes;
		}

		$term_id = get_queried_object_id();
		if ( ! $term_id ) {
			return $classes;
		}

		// Calculate progress and add completion class.
		$percentage = $this->calculate_level_progress_percentage( $term_id );
		$classes[]  = $percentage >= 100 ? 'level-completed' : 'level-pending';

		// v1.7.0: Apply Keap tag via Memberium when level is 100% complete.
		// This runs BEFORE the bonus ownership class is set, so the tag
		// triggers the Keap → Memberium → LearnDash flow before the page renders.
		$this->maybe_apply_bonus_tag( $term_id, $percentage );

		// v1.6.0: Check both bonus course ID AND bonus URL to determine
		// if a bonus exists. Previously only checked the course ID,
		// which hid the bonus section when only a URL was set.
		$bonus_id  = $this->get_level_bonus_course_id( $term_id );
		$bonus_url = $this->get_level_bonus_sale_page_url( $term_id );
		$has_bonus = ( $bonus_id > 0 || ! empty( $bonus_url ) );
		$classes[] = $has_bonus ? 'has-bonus-course' : 'no-bonus-course';

		// v1.5.0: Bonus ownership body class.
		if ( $bonus_id > 0 ) {
			$classes[] = $this->is_bonus_course_owned( $term_id ) ? 'bonus-owned' : 'bonus-not-owned';
		}

		return $classes;
	}

	/* =====================================================================
	 * Frontend CSS & JS
	 * ===================================================================== */

	/**
	 * Output CSS rules that toggle element visibility on roadmap pages.
	 *
	 * Only loads on roadmap_item singles, archives, and roadmap_level archives.
	 *
	 * CSS rules handle:
	 * - owned-btn / not-owned-btn visibility based on rm-item-owned/not-owned
	 * - bonus-locked-btn / bonus-unlocked-btn based on level-completed/pending
	 * - Hiding .bonus-section when no bonus course exists
	 * - Teal overlay on .bonus-section when level is pending
	 * - Grey button on .bonus-claim-btn when level is pending
	 * - bonus-owned-btn / bonus-not-owned-btn based on bonus ownership (v1.5.0)
	 *
	 * Hooked to: wp_head
	 */
	public function print_roadmap_visibility_css() {
		if ( ! is_singular( 'roadmap_item' ) && ! is_post_type_archive( 'roadmap_item' ) && ! is_tax( 'roadmap_level' ) ) {
			return;
		}
		?>
		<style id="roadmap-visibility-css">
			/* Loop item: owned/not-owned buttons and badges */
			.rm-item-owned .not-owned-btn,
			.rm-item-not-owned .owned-btn { display: none !important; }

			/* Bonus section: locked/unlocked button */
			.level-completed .bonus-locked-btn,
			.level-pending .bonus-unlocked-btn { display: none !important; }

			/* Hide bonus section entirely when no bonus course is assigned */
			.no-bonus-course .bonus-section { display: none !important; }

			/* Bonus section locked overlay — teal wash when level is pending */
			.level-pending .bonus-section {
				position: relative;
			}
			.level-pending .bonus-section::after {
				content: "";
				position: absolute;
				top: 0;
				left: 0;
				width: 100%;
				height: 100%;
				background: rgb(139 216 229 / 50%);
				border-radius: inherit;
				z-index: 10;
				pointer-events: none;
			}

			/* Grey out the bonus claim button when locked */
			.level-pending .bonus-claim-btn .elementor-button {
				background: #B0B0B0 !important;
				background-image: none !important;
				color: #f1f1f1 !important;
			}

			/* v1.5.0: Bonus ownership button visibility */
			.bonus-owned .bonus-not-owned-btn,
			.bonus-not-owned .bonus-owned-btn { display: none !important; }
		</style>
		<?php
	}

	/**
	 * Output the JavaScript that shows a modal alert when the bonus "Claim
	 * Your Reward" button is clicked while the level is still pending.
	 *
	 * The modal is styled to match the page design (white card, green
	 * gradient OK button, lock icon, fade animation). It prevents the
	 * default link navigation and shows an informational message.
	 *
	 * The click handler targets '.bonus-claim-btn a.elementor-button' and
	 * only fires when the body has the 'level-pending' class — this means
	 * it works on any level page without hardcoding Elementor element IDs.
	 *
	 * Only loads on roadmap_level taxonomy archive pages.
	 *
	 * Hooked to: wp_footer
	 */
	public function print_roadmap_bonus_alert_js() {
		if ( ! is_tax( 'roadmap_level' ) ) {
			return;
		}
		?>
		<style id="roadmap-bonus-modal-css">
		.rm-modal-overlay {
			position: fixed;
			top: 0; left: 0; width: 100%; height: 100%;
			background: rgba(0, 0, 0, 0.45);
			display: flex;
			align-items: center;
			justify-content: center;
			z-index: 99999;
			opacity: 0;
			transition: opacity 0.2s ease;
		}
		.rm-modal-overlay.rm-visible { opacity: 1; }
		.rm-modal-box {
			background: #fff;
			border-radius: 12px;
			padding: 40px 36px 32px;
			max-width: 400px;
			width: 90%;
			text-align: center;
			box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
			transform: scale(0.9);
			transition: transform 0.2s ease;
		}
		.rm-modal-overlay.rm-visible .rm-modal-box { transform: scale(1); }
		.rm-modal-icon {
			font-size: 40px;
			margin-bottom: 16px;
			color: #8D8D8F;
		}
		.rm-modal-msg {
			font-family: "Roboto", sans-serif;
			font-size: 16px;
			font-weight: 500;
			color: #333;
			margin: 0 0 24px;
			line-height: 1.5;
		}
		.rm-modal-close {
			font-family: "Roboto", sans-serif;
			font-size: 14px;
			font-weight: 500;
			letter-spacing: 1px;
			color: #fff;
			background: linear-gradient(90deg, #4E9B4D 0%, #76AB43 100%);
			border: none;
			border-radius: 15px;
			padding: 10px 32px;
			cursor: pointer;
			transition: opacity 0.2s ease;
		}
		.rm-modal-close:hover { opacity: 0.85; }
		</style>
		<script id="roadmap-bonus-alert-js">
		document.addEventListener('click', function(e) {
			var btn = e.target.closest('.bonus-claim-btn a.elementor-button');
			if (btn && document.body.classList.contains('level-pending')) {
				e.preventDefault();
				var overlay = document.createElement('div');
				overlay.className = 'rm-modal-overlay';
				overlay.innerHTML =
					'<div class="rm-modal-box">' +
						'<div class="rm-modal-icon"><i class="fas fa-lock"></i></div>' +
						'<p class="rm-modal-msg">Complete this level to get this bonus</p>' +
						'<button class="rm-modal-close">OK</button>' +
					'</div>';
				document.body.appendChild(overlay);
				requestAnimationFrame(function() { overlay.classList.add('rm-visible'); });
				overlay.querySelector('.rm-modal-close').addEventListener('click', function() {
					overlay.classList.remove('rm-visible');
					setTimeout(function() { overlay.remove(); }, 200);
				});
				overlay.addEventListener('click', function(ev) {
					if (ev.target === overlay) {
						overlay.classList.remove('rm-visible');
						setTimeout(function() { overlay.remove(); }, 200);
					}
				});
			}
		});
		</script>
		<?php
	}

	/* =====================================================================
	 * Data Getters / Helpers
	 * ===================================================================== */

	/**
	 * Get the current post ID safely.
	 *
	 * Tries get_the_ID() first, then falls back to the global $post.
	 * Always returns an absint (0 if no post found).
	 *
	 * @return int Current post ID or 0.
	 */
	private function get_current_post_id() {
		$post_id = get_the_ID();

		if ( ! $post_id ) {
			global $post;

			if ( isset( $post->ID ) ) {
				$post_id = $post->ID;
			}
		}

		return absint( $post_id );
	}

	/**
	 * Get the current roadmap level term ID safely.
	 *
	 * On taxonomy archive pages, returns the queried object's term_id.
	 * On single roadmap_item pages, returns the first assigned level's term_id.
	 *
	 * @return int Term ID or 0 if not found.
	 */
	private function get_current_roadmap_level_term_id() {
		if ( is_tax( 'roadmap_level' ) ) {
			$term = get_queried_object();

			if ( isset( $term->term_id ) ) {
				return absint( $term->term_id );
			}
		}

		$post_id = $this->get_current_post_id();

		if ( $post_id ) {
			$terms = get_the_terms( $post_id, 'roadmap_level' );

			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				return absint( $terms[0]->term_id );
			}
		}

		return 0;
	}

	/**
	 * Get the bonus course ID for a roadmap level.
	 *
	 * Reads from term meta '_bonus_course_id'. Returns 0 if not set.
	 *
	 * @param int $term_id Optional term ID. Defaults to current level.
	 * @return int LearnDash course ID or 0.
	 */
	private function get_level_bonus_course_id( $term_id = 0 ) {
		$term_id = $term_id ? absint( $term_id ) : $this->get_current_roadmap_level_term_id();

		if ( ! $term_id ) {
			return 0;
		}

		return absint( get_term_meta( $term_id, '_bonus_course_id', true ) );
	}

	/**
	 * Get the sale page URL for a roadmap item.
	 *
	 * Reads from post meta '_sale_page_url'. Returns empty string if not set.
	 *
	 * @param int $post_id Optional post ID. Defaults to current post.
	 * @return string URL or empty string.
	 */
	private function get_item_sale_page_url( $post_id = 0 ) {
		$post_id = $post_id ? absint( $post_id ) : $this->get_current_post_id();

		if ( ! $post_id ) {
			return '';
		}

		return (string) get_post_meta( $post_id, '_sale_page_url', true );
	}

	/**
	 * Get the bonus course URL for a roadmap level.
	 *
	 * Reads from term meta '_bonus_sale_page_url'. Returns empty string if not set.
	 * Note: The meta key retains the old name for backwards compatibility.
	 * The admin UI label was changed to "Bonus Course URL" in v1.6.0.
	 *
	 * @param int $term_id Optional term ID. Defaults to current level.
	 * @return string URL or empty string.
	 */
	private function get_level_bonus_sale_page_url( $term_id = 0 ) {
		$term_id = $term_id ? absint( $term_id ) : $this->get_current_roadmap_level_term_id();

		if ( ! $term_id ) {
			return '';
		}

		return (string) get_term_meta( $term_id, '_bonus_sale_page_url', true );
	}

	/**
	 * Get the best content URL for a roadmap item.
	 *
	 * Resolution order:
	 * 1. Single linked LD course → course permalink
	 * 2. _owned_url is set → that URL (v1.7.1, for non-LD items)
	 * 3. Fallback → item permalink
	 *
	 * @param int   $post_id    Roadmap item ID.
	 * @param mixed $course_ids Linked course IDs (array or empty).
	 * @return string Permalink URL.
	 */
	private function get_course_or_item_url( $post_id, $course_ids ) {
		// 1. Single linked LearnDash course → course permalink.
		if ( ! empty( $course_ids ) && is_array( $course_ids ) && 1 === count( $course_ids ) ) {
			$course_link = get_permalink( absint( $course_ids[0] ) );
			if ( $course_link ) {
				return $course_link;
			}
		}

		// 2. Owned URL for non-LearnDash items (v1.7.1).
		$owned_url = get_post_meta( $post_id, '_owned_url', true );
		if ( ! empty( $owned_url ) ) {
			return $owned_url;
		}

		// 3. Fallback to item permalink.
		return get_permalink( $post_id );
	}

	/**
	 * Trim text to a maximum character length, adding ellipsis if truncated.
	 *
	 * Strips HTML tags, normalizes whitespace, and respects multibyte strings
	 * when the mbstring extension is available.
	 *
	 * @param string $text  Source text to trim.
	 * @param int    $limit Maximum number of characters (default 70).
	 * @return string Trimmed text, or empty string if input is blank.
	 */
	private function trim_text_chars( $text, $limit = 70 ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		if ( '' === $text ) {
			return '';
		}

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text ) <= $limit ) {
				return $text;
			}

			return rtrim( mb_substr( $text, 0, $limit - 3 ) ) . '...';
		}

		if ( strlen( $text ) <= $limit ) {
			return $text;
		}

		return rtrim( substr( $text, 0, $limit - 3 ) ) . '...';
	}

	/**
	 * Render the HTML for the roadmap access icon/status badge.
	 *
	 * Returns a div with either a checkmark icon (owned) or empty circle
	 * (not owned) plus a text label.
	 *
	 * @param bool   $is_owned Whether the item is owned.
	 * @param string $label    Status label text (e.g. "Owned", "Not Owned").
	 * @return string HTML markup.
	 */
	private function render_access_html( $is_owned, $label ) {
		$label = esc_html( $label );

		if ( $is_owned ) {
			return '
			<div class="rm-status-container status-owned">
				<span class="rm-icon-check">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<polyline points="20 6 9 17 4 12"></polyline>
					</svg>
				</span>
				<span class="rm-status-text">' . $label . '</span>
			</div>';
		}

		return '
		<div class="rm-status-container status-not-owned">
			<span class="rm-circle-empty"></span>
			<span class="rm-status-text">' . $label . '</span>
		</div>';
	}

	/* =====================================================================
	 * Shortcodes — Item Level
	 * ===================================================================== */

	/**
	 * Shortcode: [roadmap_access_icon]
	 *
	 * Renders an ownership status icon with label for the current roadmap item.
	 * Used in Elementor loop item templates to show "Owned" or "Not Owned".
	 *
	 * @return string HTML with icon and label.
	 */
	public function handle_roadmap_access_icon_shortcode() {
		$access_state = $this->get_roadmap_access_state();

		return $this->render_access_html(
			( 'owned' === $access_state ),
			( 'owned' === $access_state ) ? __( 'Owned', 'ld-roadmap' ) : __( 'Not Owned', 'ld-roadmap' )
		);
	}

	/**
	 * Shortcode: [roadmap_access_class]
	 *
	 * Returns 'rm-item-owned' or 'rm-item-not-owned' for the current item.
	 * Can be used in Elementor dynamic tags or CSS class fields.
	 *
	 * @return string CSS class name.
	 */
	public function handle_roadmap_access_class_shortcode() {
		$access_state = $this->get_roadmap_access_state();

		if ( 'owned' === $access_state ) {
			return 'rm-item-owned';
		}

		return 'rm-item-not-owned';
	}

	/**
	 * Shortcode: [roadmap_item_url]
	 *
	 * Returns the appropriate URL for the current roadmap item based on
	 * ownership state:
	 * - Owned: course permalink (or item permalink if multiple courses)
	 * - Not owned + sale page set: sale page URL
	 * - Not owned + no sale page: course/item permalink as fallback
	 *
	 * @return string Escaped URL.
	 */
	public function handle_roadmap_item_url_shortcode() {
		$post_id = $this->get_current_post_id();

		if ( ! $post_id ) {
			return '';
		}

		$course_ids = get_post_meta( $post_id, '_linked_course_ids', true );
		$is_owned   = $this->is_roadmap_item_owned( $post_id );

		// Owned: send user to the course content.
		if ( $is_owned ) {
			return esc_url( $this->get_course_or_item_url( $post_id, $course_ids ) );
		}

		// Not owned: send to sale page if available.
		$sale_page_url = $this->get_item_sale_page_url( $post_id );
		if ( ! empty( $sale_page_url ) ) {
			return esc_url( $sale_page_url );
		}

		// Fallback: course or item permalink.
		return esc_url( $this->get_course_or_item_url( $post_id, $course_ids ) );
	}

	/**
	 * Shortcode: [roadmap_item_button_text]
	 *
	 * Returns 'Review' if the current user owns the item, 'Buy' otherwise.
	 * Used in Elementor button text fields via shortcode.
	 *
	 * @return string Button label.
	 */
	public function handle_roadmap_item_button_text_shortcode() {
		$access_state = $this->get_roadmap_access_state();

		if ( 'owned' === $access_state ) {
			return 'Review';
		}

		return 'Buy';
	}

	/**
	 * Shortcode: [roadmap_item_sale_page_url]
	 *
	 * Returns the raw sale page URL for the current roadmap item.
	 * Returns empty string if not set.
	 *
	 * @return string Escaped URL or empty string.
	 */
	public function handle_roadmap_item_sale_page_url_shortcode() {
		$url = $this->get_item_sale_page_url();

		return ! empty( $url ) ? esc_url( $url ) : '';
	}

	/**
	 * Shortcode: [roadmap_course_table]
	 *
	 * Renders an HTML table showing all LearnDash courses linked to the
	 * current roadmap item, with their ownership/completion status.
	 *
	 * Columns: Course Name | Status (Completed / Owned / Buy Now)
	 *
	 * @return string HTML table markup.
	 */
	public function render_course_table_shortcode() {
		$post_id    = $this->get_current_post_id();
		$course_ids = get_post_meta( $post_id, '_linked_course_ids', true );
		$user_id    = get_current_user_id();

		if ( empty( $course_ids ) || ! is_array( $course_ids ) ) {
			return '<p>' . esc_html__( 'No courses linked to this item yet.', 'ld-roadmap' ) . '</p>';
		}

		$output  = '<div class="roadmap-table-wrapper">';
		$output .= '<table class="roadmap-simple-table">';
		$output .= '<thead><tr>';
		$output .= '<th>' . esc_html__( 'Course Name', 'ld-roadmap' ) . '</th>';
		$output .= '<th>' . esc_html__( 'Status', 'ld-roadmap' ) . '</th>';
		$output .= '</tr></thead>';
		$output .= '<tbody>';

		foreach ( $course_ids as $course_id ) {
			$course_id    = absint( $course_id );
			$course_title = get_the_title( $course_id );
			$course_link  = get_permalink( $course_id );

			if ( ! $course_id || ! $course_title || ! $course_link ) {
				continue;
			}

			$has_access   = false;
			$is_completed = false;

			if ( is_user_logged_in() && function_exists( 'sfwd_lms_has_access' ) ) {
				$has_access = sfwd_lms_has_access( $course_id, $user_id );
			}

			if ( is_user_logged_in() && function_exists( 'learndash_course_status' ) ) {
				$status       = learndash_course_status( $course_id, $user_id );
				$is_completed = ( 'completed' === strtolower( (string) $status ) );
			}

			$output .= '<tr>';
			$output .= '<td class="col-course-name">';
			$output .= '<a href="' . esc_url( $course_link ) . '">' . esc_html( $course_title ) . '</a>';
			$output .= '</td>';

			$output .= '<td class="col-course-status">';
			if ( $is_completed ) {
				$output .= '<span class="status-tag completed">' . esc_html__( 'Completed', 'ld-roadmap' ) . '</span>';
			} elseif ( $has_access ) {
				$output .= '<span class="status-tag owned">' . esc_html__( 'Owned', 'ld-roadmap' ) . '</span>';
			} else {
				$output .= '<a href="' . esc_url( $course_link ) . '" class="btn-buy-now">' . esc_html__( 'Buy Now', 'ld-roadmap' ) . '</a>';
			}
			$output .= '</td>';
			$output .= '</tr>';
		}

		$output .= '</tbody></table></div>';

		return $output;
	}

	/* =====================================================================
	 * Shortcodes — Level Level
	 * ===================================================================== */

	/**
	 * Shortcode: [roadmap_level_progress]
	 *
	 * Returns the completion percentage for a roadmap level.
	 *
	 * Attributes:
	 * - term_id (int): Optional level term ID. Defaults to current.
	 * - with_symbol (string): 'yes' to append '%' symbol. Default 'no'.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Percentage number (e.g. "66" or "66%").
	 */
	public function handle_roadmap_level_progress_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'term_id'     => 0,
				'with_symbol' => 'no',
			),
			$atts,
			'roadmap_level_progress'
		);

		$term_id = absint( $atts['term_id'] );

		if ( ! $term_id ) {
			$term_id = $this->get_current_roadmap_level_term_id();
		}

		if ( ! $term_id ) {
			return '0';
		}

		$percentage = $this->calculate_level_progress_percentage( $term_id );

		if ( 'yes' === strtolower( $atts['with_symbol'] ) ) {
			return $percentage . '%';
		}

		return (string) $percentage;
	}

	/**
	 * Shortcode: [roadmap_level_message]
	 *
	 * Returns a motivational message based on the level's completion status.
	 *
	 * Messages:
	 * - 100%: "CONGRATULATIONS! You have completed {level_name}..."
	 * - 1-99%: "GREAT START! You are making solid progress..."
	 * - 0%: "LET'S GET STARTED! Begin your journey..."
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Motivational message text.
	 */
	public function handle_roadmap_level_message_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'term_id' => 0,
			),
			$atts,
			'roadmap_level_message'
		);

		$term_id = absint( $atts['term_id'] );

		if ( ! $term_id ) {
			$term_id = $this->get_current_roadmap_level_term_id();
		}

		if ( ! $term_id ) {
			return '';
		}

		$percentage = $this->calculate_level_progress_percentage( $term_id );

		$term       = get_term( $term_id, 'roadmap_level' );
		$level_name = ( $term && ! is_wp_error( $term ) ) ? $term->name : 'this level';

		if ( $percentage >= 100 ) {
			return 'CONGRATULATIONS! You have completed ' . $level_name . '. Keep up the great work!';
		}

		if ( $percentage > 0 ) {
			return 'GREAT START! You are making solid progress in ' . $level_name . '. Keep going!';
		}

		return "LET'S GET STARTED! Begin your journey and unlock your first course in " . $level_name . '.';
	}

	/**
	 * Shortcode: [roadmap_level_bonus_text]
	 *
	 * Returns bonus section heading text based on level completion AND
	 * bonus ownership. Three possible states (updated in v1.5.0):
	 * - Level not complete: "Unlock bonus!"
	 * - Level complete, bonus not owned: "You have unlocked a bonus!"
	 * - Level complete, bonus owned: "Access your bonus!"
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Bonus section heading text.
	 */
	public function handle_roadmap_level_bonus_text_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'term_id' => 0,
			),
			$atts,
			'roadmap_level_bonus_text'
		);

		$term_id = absint( $atts['term_id'] );

		if ( ! $term_id ) {
			$term_id = $this->get_current_roadmap_level_term_id();
		}

		if ( ! $term_id ) {
			return '';
		}

		$percentage = $this->calculate_level_progress_percentage( $term_id );

		if ( $percentage >= 100 ) {
			// v1.5.0: Check if user also owns the bonus course.
			if ( $this->is_bonus_course_owned( $term_id ) ) {
				return 'Access your bonus!';
			}
			return 'You have unlocked a bonus!';
		}

		return 'Unlock bonus!';
	}

	/**
	 * Shortcode: [roadmap_level_bonus_button_text]
	 *
	 * Returns the button label for the bonus course action button.
	 * Three states based on level completion and bonus ownership:
	 * - Level not complete: "Mastery Bonus Locked"
	 * - Level complete, bonus not owned: "Claim Your Reward"
	 * - Level complete, bonus owned: "Go To Course"
	 *
	 * Added in v1.5.0.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Button label text.
	 */
	public function handle_roadmap_level_bonus_button_text_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'term_id' => 0,
			),
			$atts,
			'roadmap_level_bonus_button_text'
		);

		$term_id = absint( $atts['term_id'] );

		if ( ! $term_id ) {
			$term_id = $this->get_current_roadmap_level_term_id();
		}

		if ( ! $term_id ) {
			return 'Complete this level to get this bonus';
		}

		$percentage = $this->calculate_level_progress_percentage( $term_id );

		if ( $percentage >= 100 ) {
			return 'Claim Your Reward';
		}

		return 'Complete this level to get this bonus';
	}

	/**
	 * Shortcode: [roadmap_level_status_class]
	 *
	 * Returns 'level-completed' or 'level-pending' CSS class.
	 * Can be used in Elementor CSS class fields for conditional styling.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string CSS class name.
	 */
	public function handle_roadmap_level_status_class_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'term_id' => 0,
			),
			$atts,
			'roadmap_level_status_class'
		);

		$term_id = absint( $atts['term_id'] );

		if ( ! $term_id ) {
			$term_id = $this->get_current_roadmap_level_term_id();
		}

		if ( ! $term_id ) {
			return 'level-pending';
		}

		$percentage = $this->calculate_level_progress_percentage( $term_id );

		if ( $percentage >= 100 ) {
			return 'level-completed';
		}

		return 'level-pending';
	}

	/**
	 * Shortcode: [roadmap_level_progress_bar_text]
	 *
	 * Returns a human-readable label for the progress bar:
	 * - 100%: "✓ Completed"
	 * - 1-99%: "In Progress"
	 * - 0%: "Not Started"
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Progress status label.
	 */
	public function handle_roadmap_level_progress_bar_text_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'term_id' => 0,
			),
			$atts,
			'roadmap_level_progress_bar_text'
		);

		$term_id = absint( $atts['term_id'] );

		if ( ! $term_id ) {
			$term_id = $this->get_current_roadmap_level_term_id();
		}

		if ( ! $term_id ) {
			return 'Not Started';
		}

		$percentage = $this->calculate_level_progress_percentage( $term_id );

		if ( $percentage >= 100 ) {
			return '✓ Completed';
		}

		if ( $percentage > 0 ) {
			return 'In Progress';
		}

		return 'Not Started';
	}

	/**
	 * Shortcode: [roadmap_level_bonus_course info="name|desc|url"]
	 *
	 * Returns information about the bonus course linked to the current level.
	 *
	 * Info parameter:
	 * - 'name': Bonus display name override, or LD course title as fallback.
	 * - 'desc': Bonus display description override, or LD course excerpt.
	 * - 'url':  v1.6.0 resolution order:
	 *           1. If user owns the bonus course → course permalink
	 *           2. If Bonus Course URL field has a value → that URL
	 *           3. If a bonus LD course is selected → its permalink
	 *           4. Otherwise → empty string
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Requested bonus course info.
	 */
	public function handle_roadmap_level_bonus_course_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'info'    => 'name',
				'term_id' => 0,
			),
			$atts,
			'roadmap_level_bonus_course'
		);

		$info    = strtolower( trim( (string) $atts['info'] ) );
		$term_id = absint( $atts['term_id'] );

		if ( ! $term_id ) {
			$term_id = $this->get_current_roadmap_level_term_id();
		}

		if ( ! $term_id ) {
			return '';
		}

		$bonus_course_id = $this->get_level_bonus_course_id( $term_id );

		// When no LD course is selected, we can still return data if:
		// - info="url" and a bonus URL is set
		// - info="name" and a display name override is set
		// - info="desc" and a display description override is set
		// Only bail out if none of these apply.
		if ( ! $bonus_course_id ) {
			$bonus_url     = $this->get_level_bonus_sale_page_url( $term_id );
			$has_name      = ! empty( get_term_meta( $term_id, '_bonus_display_name', true ) );
			$has_desc      = ! empty( get_term_meta( $term_id, '_bonus_display_desc', true ) );
			$has_any_bonus = ! empty( $bonus_url ) || $has_name || $has_desc;

			if ( ! $has_any_bonus ) {
				return '';
			}
		}

		if ( 'bonus name' === $info ) {
			$info = 'name';
		}

		// v1.6.0: Updated URL resolution with fallback chain.
		// v1.7.1: Return '#' when level is pending to prevent URL exposure.
		if ( 'url' === $info ) {
			$percentage = $this->calculate_level_progress_percentage(
				$term_id ? $term_id : $this->get_current_roadmap_level_term_id()
			);

			// Level not complete — hide the real URL.
			if ( $percentage < 100 ) {
				return '#';
			}

			// 1. If user owns the bonus course, link directly to the course content.
			if ( $bonus_course_id && $this->is_bonus_course_owned( $term_id ) ) {
				$course_link = get_permalink( $bonus_course_id );
				if ( $course_link ) {
					return esc_url( $course_link );
				}
			}

			// 2. If a custom Bonus Course URL is set, use it.
			$custom_url = $this->get_level_bonus_sale_page_url( $term_id );
			if ( ! empty( $custom_url ) ) {
				return esc_url( $custom_url );
			}

			// 3. If a bonus LD course is selected, use its permalink.
			if ( $bonus_course_id ) {
				$course_link = get_permalink( $bonus_course_id );
				if ( $course_link ) {
					return esc_url( $course_link );
				}
			}

			// 4. Nothing available.
			return '#';
		}

		if ( 'desc' === $info ) {
			// Check for display description override first.
			$override_desc = get_term_meta( $term_id, '_bonus_display_desc', true );
			if ( ! empty( $override_desc ) ) {
				return esc_html( $this->trim_text_chars( $override_desc, 70 ) );
			}

			// Fallback to LD course excerpt (only if a course is selected).
			if ( $bonus_course_id ) {
				$excerpt = get_the_excerpt( $bonus_course_id );

				if ( '' === trim( (string) $excerpt ) ) {
					$post = get_post( $bonus_course_id );
					if ( $post && ! empty( $post->post_content ) ) {
						$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 20, '...' );
					}
				}

				return esc_html( $this->trim_text_chars( $excerpt, 70 ) );
			}

			return '';
		}

		// Name: check for display name override first, fallback to LD course title.
		$override_name = get_term_meta( $term_id, '_bonus_display_name', true );
		if ( ! empty( $override_name ) ) {
			return esc_html( $override_name );
		}

		// Fallback to LD course title (only if a course is selected).
		if ( $bonus_course_id ) {
			return esc_html( get_the_title( $bonus_course_id ) );
		}

		return '';
	}

	/**
	 * Shortcode: [roadmap_level_has_bonus_course]
	 *
	 * Returns 'yes' or 'no' indicating whether the current level has a
	 * bonus course assigned. Useful for Elementor conditional display.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string 'yes' or 'no'.
	 */
	public function handle_roadmap_level_has_bonus_course_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'term_id' => 0,
			),
			$atts,
			'roadmap_level_has_bonus_course'
		);

		$term_id = absint( $atts['term_id'] );

		if ( ! $term_id ) {
			$term_id = $this->get_current_roadmap_level_term_id();
		}

		if ( ! $term_id ) {
			return 'no';
		}

		$bonus_course_id = $this->get_level_bonus_course_id( $term_id );

		return $bonus_course_id > 0 ? 'yes' : 'no';
	}

	/**
	 * Shortcode: [roadmap_level_bonus_sale_page_url]
	 *
	 * Returns the raw bonus course URL for the current level.
	 * Returns empty string if not set.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Escaped URL or empty string.
	 */
	public function handle_roadmap_level_bonus_sale_page_url_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'term_id' => 0,
			),
			$atts,
			'roadmap_level_bonus_sale_page_url'
		);

		$term_id = absint( $atts['term_id'] );

		if ( ! $term_id ) {
			$term_id = $this->get_current_roadmap_level_term_id();
		}

		$url = $this->get_level_bonus_sale_page_url( $term_id );

		return ! empty( $url ) ? esc_url( $url ) : '';
	}
}

/* Instantiate the plugin. */
new LD_RoadMap_Core();
