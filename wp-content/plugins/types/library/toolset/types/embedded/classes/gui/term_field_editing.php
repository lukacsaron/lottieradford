<?php

/**
 * Adds support for displaying and updating term fields on Edit Term page.
 *
 * Hooks into taxonomy-specific actions if there are some term field groups associated. Handles rendering fields
 * through toolset-forms (hidden inside the renderer).
 *
 * @since 1.9
 */
final class WPCF_GUI_Term_Field_Editing {


	// This class is a singleton.
	private static $instance = null;


	/**
	 * ID of the form for toolset-forms.
	 *
	 * The value is not arbitrary, it must match the actual ID of the form tag, otherwise JS validation will break
	 * (and who knows what else). In this case the ID is dictated by the add and edit term pages.
	 */
	const EDIT_FORM_ID = 'edittag';
	const ADD_FORM_ID = 'addtag';


	public static function initialize() {
		if( null == self::$instance ) {
			self::$instance = new self();
		}
	}


	private function __construct() {
		$this->add_hooks();
	}


	/**
	 * Hooks into taxonomy-specific actions if there are some term field groups associated.
	 */
	private function add_hooks() {

		$factory = WPCF_Field_Group_Term_Factory::get_instance();
		$groups_by_taxonomies = $factory->get_groups_by_taxonomies();

		$is_toolset_forms_support_needed = false;

		// Hooks for editing term fields
		foreach( $groups_by_taxonomies as $taxonomy => $groups ) {
			if( !empty( $groups ) ) {

				add_action( "{$taxonomy}_add_form_fields", array( $this, 'on_term_add' ) );
				add_action( "{$taxonomy}_edit_form_fields", array( $this, 'on_term_edit' ), 10, 2 );
				// add_action( "create_{$taxonomy}", array( $this, 'on_term_update' ), 10, 2 );
				add_action( "edit_{$taxonomy}", array( $this, 'on_term_update' ), 10, 2 );

				$is_toolset_forms_support_needed = true;
			}
		}

		// Columns on the term listing
		$is_term_listing_page = ( 'edit' != wpcf_getget( 'action' ) );
		if( $is_term_listing_page ) {
			$screen = get_current_screen();
			add_action( "manage_{$screen->id}_columns", array( $this, 'manage_term_listing_columns' ) );
			add_filter( "manage_{$screen->taxonomy}_custom_column", array( $this, 'manage_term_listing_cell'), 10, 3 );
		}

		if( $is_toolset_forms_support_needed ) {
			$this->add_toolset_forms_support();
		}
	}


	public function on_term_add( $taxonomy_slug ) {
		$groups = WPCF_Field_Group_Term_Factory::get_instance()->get_groups_by_taxonomy( $taxonomy_slug );

		if( !empty( $groups ) ) {
			printf(
				'<div class="wpcf-add-term-page-box"><strong>%s</strong></div>',
				__( 'This taxonomy has custom fields. You will be able to edit them after the term is saved.', 'wpcf' )
			);
		}

		/*if( empty( $groups ) ) {
			return;
		}

		foreach( $groups as $group ) {
			$this->render_field_group_add_page( $group, null );
		}*/
	}


	/**
	 * This will be called when editing an existing term.
	 *
	 * Renders term field groups associated with the taxonomy with all their fields, via toolset-forms.
	 *
	 * @link https://developer.wordpress.org/reference/hooks/taxonomy_edit_form_fields/
	 *
	 * @param WP_Term $term Term that is being edited.
	 * @param string $taxonomy_slug Taxonomy where the term belongs.
	 */
	public function on_term_edit( $term, $taxonomy_slug ) {
		$groups = WPCF_Field_Group_Term_Factory::get_instance()->get_groups_by_taxonomy( $taxonomy_slug );

		if( empty( $groups ) ) {
			return;
		}

		foreach( $groups as $group ) {
			$this->render_field_group_edit_page( $group, $term->term_id );
		}
	}


	public function on_term_update( $term_id, $tt_id ) {

		// Get an array of fields that we need to update. We don't care about their groups here.
		$term = get_term_by( 'term_taxonomy_id', $tt_id );
		if( ! $term instanceof WP_Term ) {
			return;
		}
		$groups = WPCF_Field_Group_Term_Factory::get_instance()->get_groups_by_taxonomy( $term->taxonomy );
		if( empty( $groups ) ) {
			return;
		}
		$field_definitions = Types_Field_Utils::get_field_definitions_from_groups( $groups );

		$update_errors = $this->update_term_fields( $term_id, $field_definitions );

		// Display errors if we have any.
		if( !empty( $update_errors ) ) {
			foreach( $update_errors as $update_error ) {
				wpcf_admin_message_store( $update_error->get_error_message(), 'error' );
			}
			wpcf_admin_message_store(
				sprintf(
					'<strong>%s</strong>',
					__( 'There has been a problem while saving custom fields. Please fix it and try again.', 'wpcf' )
				),
				'error'
			);
		}

	}


	/**
	 * Update fields for given term.
	 *
	 * @param int $term_id
	 * @param WPCF_Field_Definition[] $field_definitions
	 * @return WP_Error[]
	 */
	private function update_term_fields( $term_id, $field_definitions ) {
		$update_results = array();
		foreach( $field_definitions as $field_definition ) {
			$update_results[] = $this->update_single_field( $field_definition, $term_id );
		}
		return $this->filter_wp_errors_flat( $update_results );
	}


	/**
	 * From an array that can contain booleans, WP_Error and arrays of WP_Error, create an array containing all
	 * WP_Error instances only.
	 *
	 * @param array $update_results
	 * @return WP_Error[]
	 */
	private function filter_wp_errors_flat( $update_results ) {
		$errors = array();
		foreach( $update_results as $update_result ) {
			if( $update_result instanceof WP_Error ) {
				$errors[] = $update_result;
			} else if( is_array( $update_result ) ) {
				foreach( $update_result as $error ) {
					if( $error instanceof WP_Error ) {
						$errors[] = $error;
					}
				}
			}
		}
		return $errors;
	}


	/**
	 * @param WPCF_Field_Definition $field_definition
	 * @param int $term_id
	 *
	 * @return WP_Error|WP_Error[]|true
	 */
	private function update_single_field( $field_definition, $term_id ) {
		$field = new WPCF_Field_Instance_Term( $field_definition, $term_id );
		$saver = new WPCF_Field_Data_Saver( $field, self::EDIT_FORM_ID );

		$validation_results = $saver->validate_field_data();

		$errors = array();
		foreach( $validation_results as $index => $validation_result ) {

			if( $validation_result instanceof WP_Error ) {
				$error_message = sprintf( '%s %s',
					sprintf( __( 'Field "%s" not updated:', 'wpcf' ), $field_definition->get_name() ),
					implode( ', ', $validation_result->get_error_data() )
				);
				$errors[] = new WP_Error( 'wpcf_field_not_updated', $error_message );
			}
		}

		if( !empty( $errors ) ) {
			return $errors;
		}

		$saving_result = $saver->save_field_data();

		return $saving_result;
	}


	/**
	 * Load various assets needed by the toolset-forms blob.
	 *
	 * @since 1.9
	 */
	private function add_toolset_forms_support() {
		// JS and CSS assets related to fields - mostly generic ones.
		wpcf_edit_post_screen_scripts();

		// Needed for fields that have something to do with files
		WPToolset_Field_File::file_enqueue_scripts();

		// Extra enqueuing of media assets needed since WPToolset_Field_File doesn't know about termmeta.
		wp_enqueue_media();

		// We need to append form-specific data for the JS validation script.
		add_action( 'admin_footer', array( $this, 'render_js_validation_data' ) );

		// Pretend we're about to create new form via toolset-forms, even if we're not going to.
		// This will load some assets needed for image field preview (specifically the 'wptoolset-forms-admin' style).
		// Hacky, but better than re-registering the toolset-forms stylesheet elsewhere.
		$faux_form_bootstrap = new WPToolset_Forms_Bootstrap();
		$faux_form_bootstrap->form( 'faux' );
	}


	/**
	 * Appends form-specific data for the JS validation script.
	 *
	 * @since 1.9
	 */
	public function render_js_validation_data() {
		wpcf_form_render_js_validation( '.validate' );
	}


	/**
	 * Render table rows with individual field group.
	 *
	 * @param WPCF_Field_Group_Term $field_group
	 * @param int|null $term_id ID of the term whose fields are being rendered.
	 */
	private function render_field_group_edit_page( $field_group, $term_id ) {
		$field_definitions = $field_group->get_field_definitions();

		printf(
			'<tr><th scope="row" colspan="2"><hr /><strong>%s</strong></th></tr>',
			$field_group->get_display_name()
		);

		/** @var WPCF_Field_Definition_Term $field_definition */
		foreach( $field_definitions as $field_definition ) {
			printf(
				'<tr class="form-field"><th scope="row">%s</th><td>%s</td></tr>',
				$field_definition->get_display_name(),
				$this->get_toolset_forms_field( $field_definition, self::EDIT_FORM_ID, $term_id, true )
			);
		}
	}


	/**
	 * Render table rows with individual field group.
	 *
	 * @param WPCF_Field_Group_Term $field_group
	 * @param int|null $term_id ID of the term whose fields are being rendered.
	 */
	/*private function render_field_group_add_page( $field_group, $term_id ) {
		$field_definitions = $field_group->get_field_definitions();

		printf(
			'<hr /><h4>%s</h4>',
			$field_group->get_title()
		);

		/// @var WPCF_Field_Definition_Term $field_definition
		foreach( $field_definitions as $field_definition ) {
			printf(
				'<div class="form-field wpcf-add-term-form-field">%s</div>',
				$this->get_toolset_forms_field( $field_definition, self::ADD_FORM_ID, $term_id, false )
			);
		}
	}*/


	/**
	 * Get the toolset-forms markup for an individual field.
	 *
	 * @param WPCF_Field_Definition_Term $field_definition
	 * @param string $form_id ID of the form for toolset-forms.
	 * @param int|null $term_id ID of the term whose fields are being rendered.
	 * @param bool $hide_field_title Determine if toolset-forms title above the field should be displayed.
	 *
	 * @return string Markup with the field.
	 */
	private function get_toolset_forms_field( $field_definition, $form_id, $term_id, $hide_field_title ) {

		if( null == $term_id ) {
			$field = new WPCF_Field_Instance_Unsaved( $field_definition );
		} else {
			$field = new WPCF_Field_Instance_Term( $field_definition, $term_id );
		}

		$tf_renderer = new WPCF_Field_Renderer_Toolset_Forms( $field, $form_id );
		$tf_renderer->setup( array( 'hide_field_title' => (bool) $hide_field_title ) );

		return $tf_renderer->render( false );

	}


	/** Prefix for column names so we have no conflicts beyond any doubt. */
	const LISTING_COLUMN_PREFIX = 'wpcf_field_';


	/**
	 * Add a column for each term field on the term listing page.
	 *
	 * @param string[string] $columns Column definitions (column name => display name).
	 * @return string[string] Updated column definitions.
	 * @link https://make.wordpress.org/docs/plugin-developer-handbook/10-plugin-components/custom-list-table-columns/
	 * @since 1.9.1
	 */
	public function manage_term_listing_columns( $columns ) {

		$taxonomy_slug = wpcf_getget( 'taxonomy' );
		$groups = WPCF_Field_Group_Term_Factory::get_instance()->get_groups_by_taxonomy( $taxonomy_slug );

		$columns_to_insert = array();
		foreach( $groups as $group ) {
			foreach( $group->get_field_definitions() as $field_definition ) {
				$columns_to_insert[ self::LISTING_COLUMN_PREFIX . $field_definition->get_slug() ] = $field_definition->get_display_name();
			}
		}

		// Insert before the last column, which displays counts of posts using the term (that's probably why column
		// has the label "Count" and name "posts" :-P).
		$columns = WPCF_Utils::insert_at_position( $columns, $columns_to_insert, array( 'key' => 'posts', 'where' => 'before' ) );
		return $columns;
	}


	/**
	 * Render single cell in a term listing table.
	 *
	 * Catch field columns by their name prefix and render field values with preview renderer.
	 *
	 * @param mixed $value ""
	 * @param string $column_name
	 * @param int $term_id
	 * @link https://make.wordpress.org/docs/plugin-developer-handbook/10-plugin-components/custom-list-table-columns/
	 * @return string Rendered HTML with the table cell content.
	 * @since 1.9.1
	 */
	public function manage_term_listing_cell( $value, $column_name, $term_id ) {

		// Deal only with our custom columns.
		$is_term_field_cell = ( substr( $column_name, 0, strlen( self::LISTING_COLUMN_PREFIX ) ) == self::LISTING_COLUMN_PREFIX );

		if( $is_term_field_cell ) {

			try {

				$field_slug = substr( $column_name, strlen( self::LISTING_COLUMN_PREFIX ) );
				$field_definition = WPCF_Field_Definition_Factory_Term::get_instance()->load_field_definition( $field_slug );
				$field = new WPCF_Field_Instance_Term( $field_definition, $term_id );

				$renderer_args = array(
					'maximum_item_count' => 5,
					'maximum_item_length' => 30,
					'maximum_total_length' => 100
				);

				$renderer = WPCF_Field_Renderer_Factory::get_instance()->create_preview_renderer( $field, $renderer_args );

				$value = $renderer->render();

			} catch( Exception $e ) {
				// Do nothing when we're unable to load the field.
			}

		}

		return $value;
	}
}