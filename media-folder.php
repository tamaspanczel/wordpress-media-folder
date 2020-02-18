<?php
/*
Plugin Name: Wordpress Media Folder
Plugin URI: https://github.com/tamaspanczel/wordpress-media-folder
Description: Media Folder
Version: 1.0.0
Author: @tamaspanczel
Author URI: https://github.com/tamaspanczel
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/
if(!defined('ABSPATH')) exit;

if(!class_exists('MediaFolder')) :

	class MediaFolder
	{

		public function __construct()
		{
			register_activation_hook(__FILE__, [$this, 'activate']);
			register_deactivation_hook(__FILE__, [$this, 'deactivate']);
			register_uninstall_hook(__FILE__, [MediaFoldel::class, 'uninstall']);

			add_filter('manage_media_columns', [$this, 'filter_manage_media_columns'], 10, 2);
			add_action('manage_media_custom_column', [$this, 'action_manage_media_custom_column'], 10, 2);
			add_filter('wp_handle_upload_prefilter', [$this, 'filter_wp_handle_upload_prefilter']);
			add_filter('wp_unique_filename', [$this, 'filter_wp_unique_filename'], 10, 4);
			add_action('pre-upload-ui', [$this, 'action_pre_upload_ui']);
			add_action('admin_footer', [$this, 'action_admin_footer']);
			add_filter('ajax_query_attachments_args', [$this, 'filter_ajax_query_attachments_args']);
			add_filter('attachment_fields_to_edit', [$this, 'filter_attachment_fields_to_edit'], 10, 2);
		}

		public function activate()
		{
		}

		public function deactivate()
		{
		}

		public static function uninstall()
		{
		}

		public function filter_manage_media_columns($posts_columns, $detached = false)
		{
			unset($posts_columns['parent']);
			unset($posts_columns['comments']);
			$posts_columns['folder'] = _x('Folder', 'folder');
			return $posts_columns;
		}

		public function action_manage_media_custom_column($column_name, $post_id)
		{
			if ($column_name === 'folder') {
				$metadata = wp_get_attachment_metadata($post_id);
				$folder = rtrim($metadata['file'], wp_basename($metadata['file']));
				echo $folder;
			}
		}

		public function filter_wp_handle_upload_prefilter($file)
		{
			return $file;
		}

		public function filter_wp_unique_filename($filename, $ext, $dir, $unique_filename_callback)
		{
			$uploadDir = wp_upload_dir()['basedir'];
			if (!empty($_POST['folder']) && !empty($_POST['subfolder'])) {
				@mkdir($uploadDir . $_POST['folder'] . $_POST['subfolder'], 0755, true);
				return ltrim($_POST['folder'] . trim($_POST['subfolder'], '/') . '/' . $filename, '/');
			} else if (!empty($_POST['folder'])) {
				return ltrim($_POST['folder'] . $filename, '/');
			}

			return $filename;
		}

		public function filter_ajax_query_attachments_args($query)
		{
			if ((($_REQUEST['action'] ?? '') === 'query-attachments') && isset($_REQUEST['query']['folder'])) {
				$query['meta_query'][] = [
					'key' => '_wp_attached_file',
					'value' => ltrim($_REQUEST['query']['folder'], '/'),
					'compare' => 'LIKE'
				];
			}
			return $query;
		}

		public function filter_attachment_fields_to_edit($form_fields, $post)
		{
			if (isset($post->ID)) {
				$path = pathinfo(get_post_meta($post->ID, '_wp_attached_file', true));
				$form_fields['text_color'] = [
					'label' => 'Folder',
					'input' => 'html',
					'html'  => "<input type='text' class='text urlfield' readonly='readonly' value='" . esc_attr($path['dirname'] ?? '') . "' /><br />",
					'value' => $path['dirname'] ?? ''
				];
			}
			return $form_fields;
		}

		private function getFolderTree()
		{
			$uploadDir = wp_upload_dir()['basedir'];
			$tree = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($uploadDir,
					FilesystemIterator::KEY_AS_PATHNAME | FilesystemIterator::CURRENT_AS_FILEINFO));
			$items = [];
			foreach(($tree) as $item) {
				if ($item->isDir() && (substr((string) $item, -3) !== '/..')) {
					$items[] = substr($item, strlen($uploadDir), -1);
				}
			};
			return $items;
		}

		public function action_pre_upload_ui()
		{
			$tree = $this->getFolderTree();
			?>
<div class="wp-filter media-folder">
	<div class="filter-items view-switch">
		<span><?= _('Select upload folder') ?>:</span>
		<select name="folder">
			<?php foreach ($tree as $t) { ?>
			<option value="<?= $t ?>"><?= $t ?></option>
			<?php } ?>
		</select>
		<span><?= _('or create new subfolder') ?>:</span>
		<input type="text" name="subfolder" value=""/>
	</div>
</div>
			<?php
		}

		public function action_admin_footer()
		{
			$this->extend_uploader();
			$this->extend_filter();
		}

		public function extend_uploader()
		{
			?>
<script>
(function($) {
	$(window).load(function() {
		var uploadFileCallback = function(up, file) {
			up.settings.multipart_params.folder = $("select[name='folder']").val();
			up.settings.multipart_params.subfolder = $("input[name='subfolder']").val();
		};

		if (typeof wp.Uploader === 'function') {
			$.extend(wp.Uploader.prototype, {
				init: function() {
					this.uploader.bind('FilesAdded', uploadFileCallback, null, 999);
				}
			});
		} else if (typeof uploader === 'object') {
			uploader.bind('UploadFile', uploadFileCallback, null, 999);
		}
	});
})(jQuery);
</script>
			<?php
		}

		public function extend_filter()
		{
			$tree = $this->getFolderTree();
			?>
<script>
(function($) {
	$(window).load(function() {
		var mediaFoldersFilter = wp.media.view.AttachmentFilters.extend({
			id: 'media-folders-filter',

			createFilters: function() {
				var filters = {};

				<?php foreach ($tree as $t) { ?>
				filters.<?= $t === '/' ? 'all' : uniqid('folder') ?> = {
					text:  '<?= $t ?>',
					props: {
						status:  null,
						type:    'image',
						uploadedTo: '',
						folder: '<?= $t ?>',
						orderby: 'date',
						order:   'DESC'
					},
					priority: 10
				};
				<?php } ?>
				this.filters = filters;
			}
		});

		var AttachmentsBrowser = wp.media.view.AttachmentsBrowser;
		wp.media.view.AttachmentsBrowser = wp.media.view.AttachmentsBrowser.extend({
			createToolbar: function() {
				AttachmentsBrowser.prototype.createToolbar.call( this );
				this.toolbar.set('mediaFoldersFilter', new mediaFoldersFilter({
					controller: this.controller,
					model:      this.collection.props,
					priority:   -100
				}).render());
			}
		});
	});
})(jQuery);
</script>
<style>
.media-modal-content .media-frame select.attachment-filters {
	width: 30% !important;
	width: calc(30% - 12px);
}
</style>
			<?php
		}

	}

	new MediaFolder();

endif;
