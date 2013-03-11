<?php

	/*
	Plugin Name: Yet another download counter
	Plugin URI: https://github.com/mazkolain/yet-another-download-counter
	Description: Another download counter plugin. Simple, clean, no new tables.
	Author: Mikel Azkolain
	Version: 0.1
	Author URI: http://azkotoki.org
	License: GPL3
	*/
	

	/*
	 * This file is part of "Yet another download counter".
	 * 
	 * "Yet another download counter" is free software: you can redistribute it and/or modify
	 * it under the terms of the GNU General Public License as published by
	 * the Free Software Foundation, either version 3 of the License, or
	 * (at your option) any later version.
	 * 
	 * "Yet another download counter" is distributed in the hope that it will be useful,
	 * but WITHOUT ANY WARRANTY; without even the implied warranty of
	 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	 * GNU General Public License for more details.
	 * 
	 * You should have received a copy of the GNU General Public License
	 * along with Foobar.  If not, see <http://www.gnu.org/licenses/>
	 */
	
	
	interface YADC_AttachmentLockManager_Interface
	{
		public static function newInstance($att_id);
	
		public function isLocked();
	
		public function acquireLock();
	
		public function releaseLock();
	}
	
	
	class YADC_AttachmentLockManager_File implements YADC_AttachmentLockManager_Interface
	{
		const WaitTimeout = 5;
	
		private $_att_id;
	
		private $_lock_owned;
	
	
		private function __construct($att_id){
			$this->_att_id = $att_id;
			$this->_lock_owned = false;
			$this->_lock_fp = null;
		}
	
		public static function newInstance($att_id){
			return new YADC_AttachmentLockManager_File($att_id);
		}
	
		public function _getTempFile(){
			$upload_info = wp_upload_dir();
			return get_temp_dir().".yadc-write-lock-att-{$this->_att_id}";
		}
	
		public function isLocked(){
			return $this->_lock_owned;
		}
	
		public function acquireLock(){
			if($this->_lock_owned){
				throw new RuntimeException("Attachment '{$this->_att_id}' already locked.");
			}
				
			$wait_time = 0;
			$filename =  $this->_getTempFile();
				
			do{
				//Let's wait if we fail to get the lock
				if(($fp = fopen($filename, 'x')) === false){
						
					//If wait timeout exceeds some amount of time
					if($wait_time > self::WaitTimeout){
						throw new RuntimeException('Failed to get lock, operation timed out.');
					}
						
					//Wait for a quarter of a second
					$wait_time += .25;
					usleep(2.5 * 100000);
	
				//Otherwise tell that the lock is owned
				}else{
					$this->_lock_owned = true;
					fclose($fp);
				}
					
			}while(!$this->_lock_owned);
		}
	
		public function releaseLock(){
			if(!$this->_lock_owned){
				throw new RuntimeException('Cannot release a not owned lock.');
			}
				
			unlink($this->_getTempFile());
			$this->_lock_owned = false;
		}
	
		public function __destruct(){
			if($this->isLocked()){
				$this->releaseLock();
			}
		}
	}
	
	
	class YADC_Model
	{
		const CounterMetaName = '_yadc_counter';
		
		public function getCount($attachment_id){
			return get_post_meta($attachment_id, self::CounterMetaName, true);
		}
		
		public function incrementCount($attachment_id){
			$lm = YADC_AttachmentLockManager_File::newInstance($attachment_id);
			$lm->acquireLock();
			clean_post_cache($attachment_id);
			update_post_meta(
				$attachment_id,
				self::CounterMetaName,
				$this->getCount($attachment_id) + 1
			);
			$lm->releaseLock();
		}
	}
	
	function yadc_is_client_cache_valid($path){
		if(!isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])){
			return false;
	
		}else{
			$client_date = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
			$file_date = filemtime($path);
			return $client_date == $file_date;
		}
	}
	
	function yadc_handle_downlad(){
		$q = new WP_Query(array(
			'post_type' => 'attachment',
			'p' => $_GET['p'],
		));
	
		if($q->have_posts()){
			$post = $q->get_queried_object();
			$m = new YADC_Model();
			$m->incrementCount($post->ID);
			$file = get_attached_file($post->ID);
			session_cache_limiter(false);
				
			//Client cache is valid
			if(yadc_is_client_cache_valid($file)){
				header($_SERVER['SERVER_PROTOCOL'].' 304 Not Modified');
					
			//We have to send the file again
			}else{
				$filename = basename($file);
				header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT', true);
				header('Content-Type: '.get_post_mime_type($post->ID));
				header('Content-Disposition: attachment; filename='.$filename);
				header('Content-Length: '.filesize($file));
				readfile($file);
			}
		}
	}
	
	function yadc_get_attachment_id($url){
		$query = array(
			'post_type' => 'attachment',
			'fields' => 'ids',
			'meta_query' => array(
				array(
					'value' => basename($url),
					'key' => '_wp_attached_file',
					'compare' => 'LIKE',
				)
			)
		);
		
		foreach(get_posts($query) as $id){
			$current_url = wp_get_attachment_url($id);
			if($url == $current_url){
				return $id;
			}
		}
	
		return false;
	}
	
	function yadc_is_filtered_url($att_id){
		
		//TODO: Make this configurable
		$filtered_exts = 'zip,rar,7z,dmg,tgz,tar.gz,exe,deb,rpm';
		
		$file = get_attached_file($att_id);
		$ext = pathinfo($file, PATHINFO_EXTENSION);
		
		return in_array($ext,explode(',', $filtered_exts));
	}
	
	function yadc_attachment_url_filter($url){
		static $filter_running = false;
		
		if(!$filter_running){
			$filter_running = true;
			$att_id = yadc_get_attachment_id($url);
			if(yadc_is_filtered_url($att_id)){
				$att_info = get_post($att_id);
				$url = site_url("/download-file/{$att_id}/{$att_info->post_name}");
			}
			$filter_running = false;
		}
		
		return $url;
	}
	
	//Filter that rewrites the attachment urls
	add_filter('wp_get_attachment_url', 'yadc_attachment_url_filter');
	
	function yadc_add_media_details($form_fields, $post) {
		$m = new YADC_Model();
		$form_fields['yadc_download_count'] = array(
			'label' => __('Download count', 'yadc'),
			'input' => 'text',
			'value' => $m->getCount($post->ID),
			'helps' => __('Download count', 'yadc'),
		);
		return $form_fields;
	}
	
	//Add the edit field on the attachment details
	add_filter('attachment_fields_to_edit', 'yadc_add_media_details', null, 2);
	
	function yadc_save_media_details($post, $attachment){
		if(isset($attachment['yadc_download_count'])){
			update_post_meta(
				$post['ID'],
				YADC_Model::CounterMetaName,
				$attachment['yadc_download_count']
			);
		}
		
		return $post;
	}
	
	//Catch the custom field and save it
	add_filter('attachment_fields_to_save', 'yadc_save_media_details', null, 2);
	
	function yadc_column_header($cols) {
		$cols["yadc_downloads"] = "Downloads";
		return $cols;
	}
	
	function yadc_column_value($column_name, $att_id) {
		if($column_name == 'yadc_downloads'){
			$m = new YADC_Model();
			if($count = $m->getCount($att_id)){
				echo $count;
			
			}elseif(!yadc_is_filtered_url($att_id)){
				echo '<em>Not tracked</em>';
			
			}else{
				echo 0;
			}
		}
	}
	
	//Add custom columns to media manager
	add_filter('manage_media_columns', 'yadc_column_header');
	add_action('manage_media_custom_column', 'yadc_column_value', 10, 2);
	
	function yadc_register_rewrite_rules(){
		$plugin_dir = basename(plugin_dir_path(__FILE__));
		add_rewrite_rule(
			'download-file/([0-9]+)/([^/]*)/?$',
			'wp-content/plugins/'.$plugin_dir.'/download.php?post_type=attachment&p=$1&name=$2',
			'top'
		);
	}
	
	//Register the url rewriter rules
	add_action('init', 'yadc_register_rewrite_rules');
	
	function yadc_rewrite_flush(){
		yadc_register_rewrite_rules();
		flush_rewrite_rules();
	}
	
	register_activation_hook(__FILE__, 'yadc_rewrite_flush');
