<?php
/*
Plugin Name: WordPress Database Backup for Storage Made Easy
Plugin URI: http://storagemadeeasy.com
Description: On-demand backup of your WordPress database. Based on <a href="http://wordpress.org/extend/plugins/wp-db-backup/">WP-DB-Backup</a> This plugin is licensed under GNU GPL, version 2. Source code is available from <a href="http://code.google.com/p/smestorage/">http://code.google.com/p/smestorage</a>
Author: Storage Made Easy
Author URI: http://storagemadeeasy.com/
Version: 2.2
*/
 session_start();


 add_action( 'admin_menu', create_function( '$a', "remove_action( 'load-plugins.php', 'wp_update_plugins' );"));
 # Why use the admin_menu hook? It's the only one available between the above hook being added and being applied
 add_action( 'admin_init', create_function( '$a', "remove_action( 'admin_init', 'wp_update_plugins' );"), 2);
 add_action( 'init', create_function( '$a', "remove_action( 'init', 'wp_update_plugins' );"), 2);
 add_filter( 'pre_option_update_plugins', create_function( '$a', "return null;" ));

 # 2.8:
 remove_action('load-plugins.php', 'wp_update_plugins');
 remove_action('load-update.php', 'wp_update_plugins');
 remove_action('admin_init', '_maybe_update_plugins');
 remove_action('wp_update_plugins', 'wp_update_plugins');
 add_filter('pre_transient_update_plugins', create_function( '$a', "return null;" ));

 
$rand = substr(md5(md5(DB_PASSWORD)), -5);
global $wpdbb_content_dir, $wpdbb_content_url, $wpdbb_plugin_dir;
$wpdbb_content_dir=(defined('WP_CONTENT_DIR')) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
$wpdbb_content_url=(defined('WP_CONTENT_URL')) ? WP_CONTENT_URL : get_option('siteurl') . '/wp-content';
$wpdbb_plugin_dir =(defined('WP_PLUGIN_DIR') ) ? WP_PLUGIN_DIR : $wpdbb_content_dir . '/plugins';

if(!defined('WP_BACKUP_DIR'))		define('WP_BACKUP_DIR', str_replace('\\', '/', $wpdbb_content_dir) . '/backup-' . $rand . '/');
if(!defined('WP_UPLOADS_DIR'))	define('WP_UPLOADS_DIR', str_replace('\\', '/', $wpdbb_content_dir) . '/uploads/');
if(!defined('WP_PLUGINS_DIR'))	define('WP_PLUGINS_DIR', str_replace('\\', '/', $wpdbb_content_dir) . '/plugins/');
if(!defined('WP_BACKUP_URL'))		define('WP_BACKUP_URL', str_replace('\\', '/', $wpdbb_content_url) . '/backup-' . $rand . '/');
if(!defined('ROWS_PER_SEGMENT'))	define('ROWS_PER_SEGMENT', 200);

/** 
 * Set MOD_EVASIVE_OVERRIDE to true 
 * and increase _STORAGEMADEEASY_MOD_EVASIVE_DELAY 
 * if the backup stops prematurely.
 */
// define('MOD_EVASIVE_OVERRIDE', false);
if(!defined('_STORAGEMADEEASY_MOD_EVASIVE_DELAY'))	define('_STORAGEMADEEASY_MOD_EVASIVE_DELAY', '500');
if(!defined('_STORAGEMADEEASY_API_URL'))	define('_STORAGEMADEEASY_API_URL','http://'. get_option('storagemadeeasy_server') .'/api/');



class wpdbBackup_StorageMadeEasy {
	var $backup_complete = false;
	var $backup_file = '';
	var $backup_filename;
	var $core_table_names = array();
	var $errors = array();
	var $basename;
	var $page_url;
	var $referer_check_key;
	var $version = '2.2';

	function gzip() {
		return function_exists('gzopen');
	}

	function module_check() {
		return false;
		$mod_evasive = false;
		if(true ===MOD_EVASIVE_OVERRIDE) return true;
		if(false===MOD_EVASIVE_OVERRIDE) return false;
		if(function_exists('apache_get_modules')) 
			foreach((array) apache_get_modules() as $mod)
				if( false !== strpos($mod,'mod_evasive') || false !== strpos($mod,'mod_dosevasive') )
					return true;
		return false;
	}

	function makeBackupFilename(){
		$datum = date("Y_m_d__h-i-s");
		$res = DB_NAME . "_$datum.sql";
		if($this->gzip()) $res.='.gz';
		return $res;
	}
	
	function wpdbBackup_StorageMadeEasy() {
		global $table_prefix, $wpdb;
		add_action('wp_ajax_save_backup_time', array(&$this, 'save_backup_time'));
		add_action('init', array(&$this, 'init_textdomain'));
		add_action('load-update-core.php', array(&$this, 'update_notice_action'));
		
		$table_prefix = ( isset( $table_prefix ) ) ? $table_prefix : $wpdb->prefix;

		# try to save options
		add_option('storagemadeeasy_username','');
		add_option('storagemadeeasy_password','');
		
		if(!empty($_REQUEST['username']) && isset($_REQUEST['password'])){
			update_option('storagemadeeasy_username', $_REQUEST['username']);
			update_option('storagemadeeasy_password', $_REQUEST['password']);
		}
		
		if(!empty($_REQUEST['storagemadeeasy_server']))	update_option('storagemadeeasy_server',$_REQUEST['storagemadeeasy_server']);
			
		if(get_option('storagemadeeasy_server', false)==false) update_option('storagemadeeasy_server','storagemadeeasy.com');


		$u=get_option('storagemadeeasy_username');
		$p=get_option('storagemadeeasy_password');
		if(empty($u) && empty($p)){
			$u=get_option('smestorage_username');
			$p=get_option('smestorage_password');
		}

		$this->backup_filename=$this->makeBackupFilename();
		$path=WP_BACKUP_DIR.$this->backup_filename;

#		$src = WP_PLUGIN_URL."/storagemadeeasy-multi-cloud-files-plug-in/sme/index.php?u=".$u."&p=".$p."&path=".$path;
		if(!isset($_GET['_wpnonce']) && isset($_SESSION['path'])){
			$_SESSION['path2']=$_SESSION['path'];
			$_SESSION['path']=$path;
		}
		
#		if(!isset($_REQUEST['status']) || $_REQUEST['status']!="completed")	$_SESSION['testing123'] = $src;
		
		$possible_names = array(
			'categories',
			'comments',
			'link2cat',
			'linkcategories',
			'links',
			'options',
			'post2cat',
			'postmeta',
			'posts',
			'terms',
			'term_taxonomy',
			'term_relationships',
			'users',
			'usermeta',
		);

		foreach( $possible_names as $name ) {
			if(isset($wpdb->{$name}))	$this->core_table_names[] = $wpdb->{$name};
		}
	
		$this->backup_dir = trailingslashit(apply_filters('wp_db_b_backup_storagemadeeasy_dir', WP_BACKUP_DIR));
		$this->basename = 'wp-db-backup-storagemadeeasy';
	
		$this->referer_check_key = $this->basename . '-download_' . DB_NAME;
		$query_args = array( 'page' => $this->basename );
		if( function_exists('wp_create_nonce') )
			$query_args = array_merge( $query_args, array('_wpnonce' => wp_create_nonce($this->referer_check_key)) );

		$base = ( function_exists('site_url') ) ? site_url('', 'admin') : get_option('siteurl');
		$this->page_url = add_query_arg( $query_args, $base . '/wp-admin/edit.php');
#		$this->page_url = add_query_arg( $query_args, $base . '/wp-admin/tools.php');
		if(isset($_POST['do_backup_storagemadeeasy'])){
			$this->wp_secure('fatal');
			check_admin_referer($this->referer_check_key);
			$this->can_user_backup('main');
			// save exclude prefs

			$exc_revisions=array();
			if(isset($_POST['exclude-revisions'])) $exc_revisions = (array) $_POST['exclude-revisions'];
			$exc_spam = array();
			if(isset($_POST['exclude-spam'])) $exc_spam = (array) $_POST['exclude-spam'];
			update_option('wp_db_backup_storagemadeeasy_excs', array('revisions' => $exc_revisions, 'spam' => $exc_spam));
			switch($_POST['do_backup_storagemadeeasy']) {
			case 'backup':
				add_action('init', array(&$this, 'perform_backup'));
				break;
			case 'fragments':
				add_action('admin_menu', array(&$this, 'fragment_menu'));
				break;				
			}
		}elseif(isset($_GET['fragment_storagemadeeasy'])){
			$this->can_user_backup('frame');
			add_action('init', array(&$this, 'init'));
		}elseif(isset($_GET['sendtosme'])){
			$this->can_user_backup();
			add_action('init', array(&$this, 'init'));
		}elseif(isset($_GET['backupcfolder'] )) {
			$this->can_user_backup();
			add_action('init', array(&$this, 'init'));
		}elseif(isset($_GET['backup'] )) {
			$this->can_user_backup();
			add_action('init', array(&$this, 'init'));
		}else{
			add_action('admin_menu', array(&$this, 'admin_menu'));
		}
	}
	
	# this function remove old files from backup folder.
	function removeOldFiles(){
// This function remove files from backup folder.
// But the plugin WordPress Database Backup saves files to this folder.
// So we can remove this files.
		return false;


		if(!file_exists($this->backup_dir)) return false;
		$handle=opendir($this->backup_dir);
		if(!$handle) return false;

		while(false!==($file=readdir($handle))){
			preg_match("/([0-9]){8}_(.+)/i", $file, $m);
			if(!empty($m[1])){
				$f=str_replace('//', '/', $this->backup_dir.'/'.$file);
				if(file_exists($f) && is_file($f) && time()-filemtime($f)>129600){		# 129600=36 hour
					@unlink($f);
				}
			}
		}
	}

	function init(){
#		ini_set('max_execution_time', 36000); 				// increase script timeout value
		if( !ini_get('safe_mode')) @set_time_limit(10000);
		$this->can_user_backup();
		if(isset($_GET['backup'])) {
			$via = isset($_GET['via']) ? $_GET['via'] : 'http';
			
			$this->backup_file = $_GET['backup'];
			$this->validate_file($this->backup_file);

			switch($via) {
			case 'smtp':
			case 'email':
				$success = $this->deliver_backup($this->backup_file, 'smtp', $_GET['recipient'], 'frame');
				$this->error_display( 'frame' );
				if($success){
					echo '
						<!-- ' . $via . ' -->
						<script type="text/javascript"><!--\\
					';
					echo '
						alert("' . __('Backup Complete!','wp-db-backup-storagemadeeasy') . '");
						window.onbeforeunload = null; 
						</script>
					';
				}
				break;
			default:
				$this->deliver_backup($this->backup_file, $via);
				$this->error_display( 'frame' );
			}
			die();
		}
		if(isset($_GET['backupcfolder'])){
			$filename=$_GET['filename'];
			if($_GET['backupcfolder']==1){
				$this->backup_uploads($filename);
			}else{
				$this->backup_plugins($filename);
			}
			exit;
		}
		if(isset($_GET['sendtosme'])){
			$filename=$_GET['filename'];
			$this->send_backup_to_SME($filename);
			exit;
		}
		if(isset($_GET['fragment_storagemadeeasy'])){
			list($table, $segment, $filename) = explode(':', $_GET['fragment_storagemadeeasy']);
			$this->validate_file($filename);
			$this->backup_fragment($table, $segment, $filename);
		}

		die();
	}

	function send_backup_to_SME($filename){
		$this->removeOldFiles();
		$this->includeLibs();
		
		if(!is_writable($this->backup_dir)){
			$error=(__('Could not open the backup file for writing!','wp-db-backup-storagemadeeasy'));
		}

		$u=get_option('storagemadeeasy_username');
		$p=get_option('storagemadeeasy_password');


		$path0='';
		$path=$this->backup_dir . $filename;
	
		$path2=array();
		$path2[]=substr($path, 0, strrpos($path, '.sql')) .'_uploads.zip';
		$path2[]=substr($path, 0, strrpos($path, '.sql')) .'_plugins.zip';

		for($i=0; isset($path2[$i]); $i++){		# we remove files that don't exists
			if(file_exists($path2[$i])) continue;
			array_splice($path2, $i, 1);
			$i--;
		}

		if(file_exists($path) && count($path2)>0){ // make 1 file
			$path0=$path;
			if(substr($path0, strlen($path0)-strlen('.sql.gz'))=='.sql.gz')	$path0=substr($path0, 0, strlen($path0)-strlen('.sql.gz')). '.zip';
			if(substr($path0, strlen($path0)-strlen('.sql'))=='.sql')	$path0=substr($path0, 0, strlen($path0)-strlen('.sql')). '.zip';
			$path2[]=$path;
			$res=$this->make_one_backup_file($path0, $path2, 1);

			if($res===false || (isset($res['fail']) && $res['fail']!=0) || (isset($res['error']) && $res['error']!='')){
				$path0='';
				if(isset($res['error']) && $res['error']!=''){
					$error=$res['error'];
				}else{
					$error='Cannot create one file with the backup';
				}
			}else{
				$path='';
				unset($path2);
			}
		}else{
			$path0=$path;
		}
		
		if($error=='' && $path0==''){
			$error='Backup not created';
		}
		
		if($error==''){
			$a=processRequest('*/gettoken/'.encodeArgs(array($u,$p)));
			
			$token='';
			$error='';
			$folderid='';
			if($a[0]=='' || empty($a[1]['token'])){
				$token=$a[1]['token'];
			}else
				$error='Wrong storagemadeeasy login';
		}

		if($error==''){
			//searching for folder
			$a=processRequest($token.'/checkPathExists/'.encodeArgs(array('My WordPress backup', '0')));
			if(!empty($a[1]['file']['fi_id'])){
				$folderid=$a[1]['file']['fi_id'];
			}
		}
			
		if($error=='' && $folderid==''){
			//create folder
			$a=processRequest($token.'/doCreateNewFolder/'.encodeArgs(array('My WordPress backup',	'',	'0')));
			if(!empty($a[1]['file']['fi_id'])){
				$folderid=$a[1]['file']['fi_id'];
			}else{
				$error='Can not create folder';
				if(!empty($a[1]['statusmessage']) && $a[1]['statusmessage']!='Success') $error=$a[1]['statusmessage'];
			}
		}
			
		if($error==''){
			$argname='fi_pid';
#			if(isset($_REQUEST['saveornot']) && $_REQUEST['saveornot']=='y') $argname='fi_id';
			#$files=array('file_1'=>$_GET['path']);
			if(!empty($path0) && file_exists($path0)){
				$r=$this->doUploadFile($path0, $token, $folderid, basename($path0), 'Wordpress Uploads Backup', 'wordpress, backup, uploads', '');
				if($r['error']!==false){
					if($r['errormessage']!=''){
						$error=$r['errormessage'];
					}else{
						$error='Error 11. Cannot upload file to SME';
					}
				}
			}else{
				if(file_exists($path)){
					$r=$this->doUploadFile($path, $token, $folderid, basename($path), 'Wordpress Uploads Backup', 'wordpress, backup, uploads', '');
					if($r['error']!==false){
						if($r['errormessage']!=''){
							$error=$r['errormessage'];
						}else{
							$error='Error 12. Cannot upload file to SME';
						}
					}
				}
		
				if(file_exists($path2)){
					$r=$this->doUploadFile($path2, $token, $folderid, basename($path2), 'Wordpress Uploads Backup', 'wordpress, backup, uploads','');
					if($r['error']!==false){
						if($r['errormessage']!=''){
							$error=$r['errormessage'];
						}else{
							$error='Error 13. Cannot upload file to SME';
						}
					}
				}
			}
		}

		/*
		if(isset($a[1]) && isset($a[1]['status']) && $a[1]['status']=='ok'){
			sendResponse('Finished');
		}
		*/
		#print_r($a);
		if(file_exists($path))  unlink($path);
		if(file_exists($path2)) unlink($path2);
		if(file_exists($path0)) unlink($path0);

		if(empty($error)){
			$mess='Finished';
		}else{
			$mess=$error;
		}

		echo '<script type="text/javascript"><!--//
			window.parent.SMEBackingUpFolder=0;
			var msg="'. $mess .'";
			window.parent.setProgress(msg);
			';
		if(empty($error)) echo 'window.parent.nextStep();';
	
		if(strlen($error)>1){
			echo 'alert("'. $error .'");';
		}else{
			echo 'alert("'. $mess .'");';
		}
	
		echo '//--></script>';
		exit;
	}

	# This function send file to SME
	function doUploadFile($path, $token, $pid, $name, $descr='', $tags='', $encryptphrase=''){
		$res=array('error'=>false, 'id'=>'', 'errormessage'=>'');
		$this->includeLibs();
		$data=array(
			'fi_name'=>$name,
			'fi_description'=>$descr,
			'fi_tags'=>$tags,
			'fi_pid'=>$pid,
			'fi_filename'=>$name,
			'responsetype'=>'xml',
			'responsedata'=>'',
			'encryptphrase'=>$encryptphrase,
			'fi_structtype'=>'g',
			'fi_id'=>'',
			'chunkifbig'=>'',
			'fi_localtime'=>''
		);

		$a=processRequest($token.'/doInitUpload/'.encodeArgs($data));
		if(empty($a[1]['uploadcode'])){
			$res['error']=true;
			if(!empty($a[1]['statusmessage']) && $a[1]['statusmessage']!='Success'){
				$res['errormessage']=$a[1]['statusmessage'];
			}else{
				$res['errormessage']='Cannot get uploadcode';
			}
			return $res;
		}
		$uploadcode=$a[1]['uploadcode'];

		$a='';
		$url='http://'. get_option('storagemadeeasy_server') .'/cgi-bin/uploader/uploader1.cgi?'. $uploadcode .',0,0';
		if(function_exists('curl_init')){		# Try to use cURL.
			$process = curl_init($url);
			#curl_setopt($process, CURLOPT_HTTPHEADER, $headers); 
			curl_setopt($process, CURLOPT_HEADER, 1); 
			curl_setopt($process, CURLOPT_USERAGENT, 'Mozilla (WordPress Database Backup for SME)');

			curl_setopt($process, CURLOPT_CONNECTTIMEOUT, 120);
			curl_setopt($process, CURLOPT_TIMEOUT, 10000);

			curl_setopt($process, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($process, CURLOPT_RETURNTRANSFER, 1);

			curl_setopt($process, CURLOPT_POST, 1);
			curl_setopt($process, CURLOPT_POSTFIELDS, array('file1'=>'@'. $path));
			curl_setopt($process, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($process, CURLOPT_FOLLOWLOCATION, 1);

			$return=curl_exec($process);
			curl_close($process);
		}else{	# This way can take lots of memory
			$files=array('file_1'=>$path);
			$a=processRequest($url,0,array(),$files);
		}
		if($a!=''){
			$res['error']=true;
			$res['errormessage']='Error 1. Cannot upload file';
			return $res;
		}

		$a=processRequest($token.'/doCompleteUpload/'.encodeArgs(array('uploadcode'=>$uploadcode)));
		if(!empty($a[1]['file']['fi_id'])){
			$res['id']=$a[1]['file']['fi_id'];
			return $res;
		}else{
			$res['error']=true;
			if(!empty($a[1]['statusmessage']) && $a[1]['statusmessage']!='Success'){
				$res['errormessage']=$a[1]['statusmessage'];
			}else{
				$res['errormessage']='Error 2. Cannot upload file';
			}
			return $res;
		}
	}

	function includeLibs(){
		if(file_exists(WP_PLUGIN_URL.'/storagemadeeasy-multi-cloud-files-plug-in/http.php')){
			$path=WP_PLUGIN_URL.'/storagemadeeasy-multi-cloud-files-plug-in/';
		}elseif(file_exists('../wp-content/plugins/storagemadeeasy-multi-cloud-files-plug-in/http.php')){
			$path='../wp-content/plugins/storagemadeeasy-multi-cloud-files-plug-in/';
		}else{	# it is need for scheduler
			$path='./wp-content/plugins/storagemadeeasy-multi-cloud-files-plug-in/';
		}

		if(file_exists('../wp-content/plugins/storagemadeeasy-multi-cloud-files-plug-in/pclzip.lib.php')){
			include_once('../wp-content/plugins/storagemadeeasy-multi-cloud-files-plug-in/pclzip.lib.php');
		}else{	# it is need for scheduler
			include_once('./wp-content/plugins/storagemadeeasy-multi-cloud-files-plug-in/pclzip.lib.php');
		}
		include_once($path.'http.php');
		include_once($path.'class.xmltoarray.php');
		include_once($path.'lib.php');
	}

	function make_one_backup_file($newPath, $files, $delete_old=0){
		if(!ini_get('safe_mode')){
			@set_time_limit(15000);
		}

		$this->includeLibs();

		$res=array();
		$tmp_path=$newPath . '_tmp';

		$csuccess=0;
		$cerror=0;
		$L=0;
		$archive = new PclZip($tmp_path);
		
		$e=$archive->create($files, PCLZIP_OPT_REMOVE_PATH, dirname($files[$i]), PCLZIP_OPT_NO_COMPRESSION);
		if($e===0) $res['error']='Cannot create archive. '.$e;

/*
		for($i=0; !empty($files[$i]); $i++){
			if(!file_exists($files[$i])) continue;
			if($L==0){
				$e=$archive->create($files[$i], PCLZIP_OPT_REMOVE_PATH, dirname($files[$i]), PCLZIP_OPT_NO_COMPRESSION);
#				$e=$archive->create($files[$i], PCLZIP_OPT_REMOVE_PATH, dirname($files[$i]));
				$L=1;
			}else{
				$e=$archive->add($files[$i], PCLZIP_OPT_REMOVE_PATH, dirname($files[$i]), PCLZIP_OPT_NO_COMPRESSION);
#				$e=$archive->add($files[$i], PCLZIP_OPT_REMOVE_PATH, dirname($files[$i]));
			}

			if($e==0){
				$cerror++;
			}else{
				$csuccess++;
			}
		}
*/
		if($delete_old){
			for($i=0; !empty($files[$i]); $i++){
				if(file_exists($files[$i]))  unlink($files[$i]);
			}
		}

#		if($L==0){
#			return false;
		if(isset($res['error']) && $res['error']!=''){
			return $res;
		}elseif(!rename($tmp_path, $newPath)){
			$res['error']='Can not rename file $tmp_path to $newPath';
		}else{
			$res['success']=$csuccess;
			$res['fail']=$cerror;
			return $res;
		}
	}

	function folderToArchive($archive, $dir, $ignorePath){
#echo "folderToArchive($archive, $dir, $ignorePath)<br>";
		$hdl=opendir($dir);
	
		$a=new PclZip($archive);
	
		$countElements=0;	
		while($file = readdir($hdl)){
			if($file=='.' || $file=='..') continue;

			$countElements++;
			$fname=str_replace('//','/',$dir.'/'.$file);
			if(is_file($fname)){
				if(!file_exists($archive)){
					$e=$a->create($fname, PCLZIP_OPT_REMOVE_PATH, $ignorePath, PCLZIP_OPT_NO_COMPRESSION);
#					$e=$a->create($fname, PCLZIP_OPT_REMOVE_PATH, $ignorePath);
				}else{
					$e=$a->add($fname, PCLZIP_OPT_REMOVE_PATH, $ignorePath, PCLZIP_OPT_NO_COMPRESSION);
#					$e=$a->add($fname, PCLZIP_OPT_REMOVE_PATH, $ignorePath);
				}
			}else{
				$this->folderToArchive($archive, $fname, $ignorePath);
			}
		}
	
		closedir($hdl);
		if($countElements<1){
			if(!file_exists($archive)){
				$e=$a->create($dir, PCLZIP_OPT_REMOVE_PATH, $ignorePath, PCLZIP_OPT_NO_COMPRESSION);
#				$e=$a->create($dir, PCLZIP_OPT_REMOVE_PATH, $ignorePath);
			}else{
				$e=$a->add($dir, PCLZIP_OPT_REMOVE_PATH, $ignorePath, PCLZIP_OPT_NO_COMPRESSION);
#				$e=$a->add($dir, PCLZIP_OPT_REMOVE_PATH, $ignorePath);
			}
		}
		
		return true;
	}

	function backup_plugins($filename){
		if(!ini_get('safe_mode')){
			@set_time_limit(10000);
			@ini_set('memory_limit', '64M');
		}
		$this->includeLibs();

		$error='';

		$path=$this->backup_dir . $filename;
		$path=substr($path, 0, strrpos($path, '.sql')) .'_plugins.zip';

		if(!is_writable($this->backup_dir))	$error=(__('Could not open the backup file for writing!','wp-db-backup-storagemadeeasy'));

		if(strlen($error)<1){
			$archive = new PclZip($path);
			$e=$archive->create('../wp-content/plugins', PCLZIP_OPT_REMOVE_PATH, '../wp-content/plugins');
			if($e===0) $error='Cannot create archive';
#			$adir='../wp-content/plugins';
#			$this->folderToArchive($path, $adir, $adir);
		}
		
		if(strlen($error)<1){
			$mess='Plugins Backup Complete';
		}else{
			$mess=$error;
		}

		echo '<script type="text/javascript"><!--//
			var msg="'. $mess .'";
			window.parent.setProgress(msg);
			window.parent.nextStep();';
	
		if(strlen($error)>1){
			echo 'alert("'. $error .'");';
		}
	
		echo '//--></script>';
		exit;
	}
	
	function backup_uploads($filename){
		if(!ini_get('safe_mode')) @set_time_limit(10000);
		$this->includeLibs();

		$error='';

		$path=$this->backup_dir . $filename;
		$path=substr($path, 0, strrpos($path, '.sql')) .'_uploads.zip';

		if(!is_writable($this->backup_dir))	$error=(__('Could not open the backup file for writing!','wp-db-backup-storagemadeeasy'));

		if(strlen($error)<1){
			$archive = new PclZip($path);
			$e=$archive->create('../wp-content/uploads', PCLZIP_OPT_REMOVE_PATH, '../wp-content/uploads');
			if($e===0) $error='Cannot create archive';
#			$adir='../wp-content/uploads';
#			$this->folderToArchive($path, $adir, $adir);
		}
		
		if(strlen($error)<1){
			$mess='Uploads folder Backup Complete';
		}else{
			$mess=$error;
		}

		echo '<script type="text/javascript"><!--//
			var msg="'. $mess .'";
			window.parent.setProgress(msg);
			window.parent.nextStep();';
	
		if(strlen($error)>1){
			echo 'alert("'. $error .'");';
		}
	
		echo '//--></script>';
		exit;
	}


	function init_textdomain() {
		load_plugin_textdomain('wp-db-backup-storagemadeeasy', str_replace(ABSPATH, '', dirname(__FILE__)), dirname(plugin_basename(__FILE__)));
	}

	/*
	 * Add a link to back up your database when doing a core upgrade 
	 */
	function update_notice_action() {
		if( 'upgrade-core' == $_REQUEST['action'] ) :
			ob_start(array(&$this, 'update_notice'));
			add_action('admin_footer', create_function('', 'ob_end_flush();'));
		endif;
	}
		function update_notice($text = '') {
			$pattern = '#(<a href\="' . __('http://codex.wordpress.org/WordPress_Backups') . '">.*?</p>)#';
			$replace = '$1' . "\n<p>" . sprintf(__('Click <a href="%s" target="_blank">here</a> to back up your database using the WordPress Database Backup plugin. <strong>Note:</strong> WordPress Database Backup does <em>not</em> back up your files, just your database.', 'wp-db-backup-storagemadeeasy'), 'tools.php?page=wp-db-backup-storagemadeeasy') . "</p>\n"; 
			$text = preg_replace($pattern, $replace, $text);
			return $text;
		}

	function build_backup_script() {
		global $table_prefix, $wpdb;

		$nameWithoutExtension = basename($this->backup_filename);
		if(substr($nameWithoutExtension, strlen($nameWithoutExtension)-strlen('.sql.gz'))=='.sql.gz'){
			$nameWithoutExtension=substr($nameWithoutExtension, 0, strlen($nameWithoutExtension)-strlen('.sql.gz'));
		}elseif(substr($nameWithoutExtension, strlen($nameWithoutExtension)-strlen('.sql'))=='.sql'){
			$nameWithoutExtension=substr($nameWithoutExtension, 0, strlen($nameWithoutExtension)-strlen('.sql'));
		}elseif(substr($nameWithoutExtension, strlen($nameWithoutExtension)-strlen('.tar.gz'))=='.tar.gz'){
			$nameWithoutExtension=substr($nameWithoutExtension, 0, strlen($nameWithoutExtension)-strlen('.tar.gz'));
		}elseif(substr($nameWithoutExtension, strlen($nameWithoutExtension)-strlen('.tgz'))=='.tgz'){
			$nameWithoutExtension=substr($nameWithoutExtension, 0, strlen($nameWithoutExtension)-strlen('.tgz'));
		}elseif(substr($nameWithoutExtension, strlen($nameWithoutExtension)-strlen('.tar'))=='.tar'){
			$nameWithoutExtension=substr($nameWithoutExtension, 0, strlen($nameWithoutExtension)-strlen('.tar'));
		}

		$lurl=get_option('siteurl') . '/wp-admin/';
		echo "<div class='wrap'>";
		echo 	'<fieldset class="options"><legend>' . __('Progress','wp-db-backup-storagemadeeasy') . '</legend>
			<div id="illegalActions"><p><strong>' .
				__('DO NOT DO THE FOLLOWING AS IT WILL CAUSE YOUR BACKUP TO FAIL:','wp-db-backup-storagemadeeasy').
			'</strong></p>
			<ol>
				<li>'.__('Close this browser','wp-db-backup-storagemadeeasy').'</li>
				<li>'.__('Reload this page','wp-db-backup-storagemadeeasy').'</li>
				<li>'.__('Click the Stop or Back buttons in your browser','wp-db-backup-storagemadeeasy').'</li>
			</ol>
			</div>
			<p><strong>' . __('Progress:','wp-db-backup-storagemadeeasy') . '</strong></p>
			<div id="meterbox" style="height:11px;width:80%;padding:3px;border:1px solid #659fff;"><div id="meter" style="height:11px;background-color:#659fff;width:0%;text-align:center;font-size:6pt;">&nbsp;</div></div>
			<div id="progress_message"></div>
			<div id="errors"></div>
			</fieldset>
			<iframe id="backuploader" src="about:blank" style="visibility:hidden;border:none;height:1em;width:1px;"></iframe>
			<script type="text/javascript">
			//<![CDATA[
			var SMEBackingUpFolder=0;
			setTimeout(function(){SMEbinder()}, 1000);
			function SMEbinder(){
				var el = document.getElementById("backuploader");
				if(el.attachEvent){
					el.attachEvent("onload", SMEcheckResponse);
				}else{
					el.onload = SMEcheckResponse;
				}
			}
			function SMEcheckResponse(){
				if(SMEBackingUpFolder==0) return 0;
				SMEBackingUpFolder=0;

				var iFrame =  document.getElementById("backuploader");
				var iFrameBody;
				if(iFrame.contentDocument){ // FF
					iFrameBody = iFrame.contentDocument.getElementsByTagName("body")[0];
				}else if(iFrame.contentWindow){ // IE
					iFrameBody = iFrame.contentWindow.document.getElementsByTagName("body")[0];
				}

				if(typeof(iFrameBody)!="undefined" && typeof(iFrameBody.innerHTML)!="undefined"
					&& (iFrameBody.innerHTML=="" || iFrameBody.innerHTML.indexOf("Allowed memory size of")>0 || (SMEBackingUpFolder!=-1 && iFrameBody.innerHTML.indexOf("window.parent.setProgress")<0))){

					var m="";
					if(SMEBackingUpFolder==1){
						m="uploads";
					}else if(SMEBackingUpFolder==2){
						m="plugins";
					}
					if(m==""){
						m="Unknown error   ";
					}else{
						m="Unknown error. Cannot create backup of the folder \""+ m +"\".";
					}
					
					setProgress(m);
					window.onbeforeunload = function(){}
					alert(m);
				}
			}
			window.onbeforeunload = function() {
				return "' . __('Navigating away from this page will cause your backup to fail.', 'wp-db-backup-storagemadeeasy') . '";
			}
			function setMeter(pct,src_1) {
				var meter = document.getElementById("meter");
				meter.style.width = pct + "%";
				meter.innerHTML = Math.floor(pct) + "%";
				if((pct==100) && (src_1!=1))
				{
//					alert("Backup Complete");
					document.getElementById("illegalActions").style.display = "none";
					//document.location.href="'. $lurl .'tools.php?page=wp-db-backup-storagemadeeasy&status=completed";
				}
			}
			function setProgress(str) {
				var progress = document.getElementById("progress_message");
				
				progress.innerHTML = str;
			}
			function addError(str) {
				var errors = document.getElementById("errors");
				errors.innerHTML = errors.innerHTML + str + "<br />";
			}

			function backup(table, segment){
				var fram = document.getElementById("backuploader");
				fram.src = "' . $this->page_url . '&fragment_storagemadeeasy=" + table + ":" + segment + ":' . $this->backup_filename . ':";
			}';
			
		if(isset($_POST['uploads_folder'])){
			echo '
			function backup_uploads(){
				var fram = document.getElementById("backuploader");
				setProgress("Backing up folder \"uploads\"...");
				SMEBackingUpFolder=1;
				fram.src = "'. $this->page_url .'&backupcfolder=1&filename='. $this->backup_filename .'";
			}
			';
		}

		if(isset($_POST['plugins_folder'])){
			echo '
			function backup_plugins(){
				var fram = document.getElementById("backuploader");
				setProgress("Backing up folder \"plugins\"...");
				SMEBackingUpFolder=2;
				fram.src = "'. $this->page_url .'&backupcfolder=2&filename='. $this->backup_filename .'";
			}
			';
		}

		echo '
			var curStep = 0;
			
			function nextStep() {
				backupStep(curStep);
				curStep++;
			}
			
			function sendToSME(){
				var fram = document.getElementById("backuploader");
				setProgress("Sending to your cloud...");
				SMEBackingUpFolder=-1;

				fram.src = "' . $this->page_url . '&sendtosme=1&filename=' . $this->backup_filename . '";
			}

			function finishBackup(src_1) {
				window.onbeforeunload = null; 
				var fram = document.getElementById("backuploader");				
				setMeter(100,1);';
		$download_uri = add_query_arg('backup', $this->backup_filename, $this->page_url);
		switch($_POST['deliver']) {
		case 'http':
			echo '
//				setProgress("' . sprintf(__("Backup complete, preparing <a href=\\\"%s\\\">backup</a> for download...",'wp-db-backup-storagemadeeasy'), $download_uri) . '");
				setProgress("' . sprintf(__("Backup complete, preparing backup for download...",'wp-db-backup-storagemadeeasy'), $download_uri) . '");
				window.onbeforeunload = null; 
				fram.src = "' . $download_uri . '";
			';
			break;
		case 'smtp':
			echo '
//				setProgress("' . sprintf(__("Backup complete, sending <a href=\\\"%s\\\">backup</a> via email...",'wp-db-backup-storagemadeeasy'), $download_uri) . '");
				setProgress("' . sprintf(__("Backup complete, sending backup via email...",'wp-db-backup-storagemadeeasy'), $download_uri) . '");
				window.onbeforeunload = null; 
				fram.src = "' . $download_uri . '&via=email&recipient=' . $_POST['backup_recipient'] . '";
			';
			break;
		default:
		
		//get uname and pass from options table and assign 
			//$status = "show";
			echo '
				setProgress("' . sprintf(__("Backup complete, (". $nameWithoutExtension .")",'wp-db-backup-storagemadeeasy'), $download_uri) . '")
				window.onbeforeunload = null; 
			';
			//echo '<script language="javascript">window.href.location="tools.php?page=wp-db-backup-storagemadeeasy?status=show";<script>';
		}
		
		echo '
			}
			
			function backupStep(step) {
				var L=0;
				switch(step) {
				case 0: backup("", 0); break;
		';
		
		$also_backup = array();
		if(isset($_POST['other_tables'])) {
			$also_backup = $_POST['other_tables'];
		} else {
			$also_backup = array();
		}
		$core_tables = $_POST['core_tables'];
		$tables = array_merge($core_tables, $also_backup);
		$step_count = 1;
		foreach ($tables as $table) {
			$rec_count = $wpdb->get_var("SELECT count(*) FROM {$table}");
			$rec_segments = ceil($rec_count / ROWS_PER_SEGMENT);
			$table_count = 0;
			if( $this->module_check() ) {
				$delay = "setTimeout('";
				$delay_time = "', " . (int) _STORAGEMADEEASY_MOD_EVASIVE_DELAY . ")";
			}
			else { $delay = $delay_time = ''; }
			do {
				echo "case {$step_count}: {$delay}backup(\"{$table}\", {$table_count}){$delay_time}; break;\n";
				$step_count++;
				$table_count++;
			} while($table_count < $rec_segments);
			echo "case {$step_count}: {$delay}backup(\"{$table}\", -1){$delay_time}; break;\n";
			$step_count++;
		}

		if(isset($_POST['uploads_folder'])){
			echo "case {$step_count}: backup_uploads(); break;";
			$step_count++;
		}

		if(isset($_POST['plugins_folder'])){
			echo "case {$step_count}: backup_plugins(); break;";
			$step_count++;
		}

		if(empty($_POST['deliver']) || $_POST['deliver']=='none'){
			echo "case {$step_count}: sendToSME(); break;";
			$step_count++;
		}
		echo "case {$step_count}: 
				{
					";
		if(!empty($_POST['deliver']) && $_POST['deliver']=='http'){
			echo "L=1;";
		}
		echo "finishBackup('http://go.com/');
					break;
				}";
		
		echo '
				}
				if(L==1){
					setMeter(100 * step / ' . $step_count . ', 1);
				}else{
					if(step != 0) setMeter(100 * step / ' . $step_count . ',"'.$this->backup_filename.'");
				}
			}

			nextStep();
			// ]]>
			</script>
	</div>
		';	
		//echo '<script language="javascript">window.href.location="tools.php?page=wp-db-backup-storagemadeeasy?status=show";<script>';
		//$status = $_REQUEST['status'];
		//$path = WP_CONTENT_DIR."/backup-88456/".$this->backup_filename;
		//$status = $_REQUEST['status'];
/*
		$u = get_option('storagemadeeasy_username');
		$p = get_option('storagemadeeasy_password');
		if(strlen($path)>0)
		{
			if(strlen($status)>0)
			{
#				echo "<iframe src='".WP_PLUGIN_URL."/wp-db-backup-storagemadeeasy/sme/index.php?u=".$u."&p=".$p."&path=".$path."' width=300 height=300 ></iframe>";
			}			
		}		
*/
		$this->backup_menu();
	}
	function backup_fragment($table, $segment, $filename) {
		global $table_prefix, $wpdb;
			
		echo "$table:$segment:$filename";
		
		if($table == '') {
			$msg = __('Creating backup file...','wp-db-backup-storagemadeeasy');
		} else {
			if($segment == -1) {
				$msg = sprintf(__('Finished backing up table \\"%s\\".','wp-db-backup-storagemadeeasy'), $table);
			} else {
				$msg = sprintf(__('Backing up table \\"%s\\"...','wp-db-backup-storagemadeeasy'), $table);
			}
		}
		
		if(is_writable($this->backup_dir)) {
			$this->fp = $this->open($this->backup_dir . $filename, 'a');
			if(!$this->fp) {
				$this->error(__('Could not open the backup file for writing!','wp-db-backup-storagemadeeasy'));
				$this->error(array('loc' => 'frame', 'kind' => 'fatal', 'msg' =>  __('The backup file could not be saved.  Please check the permissions for writing to your backup directory and try again.','wp-db-backup-storagemadeeasy')));
			}
			else {
				if($table == '') {		
					//Begin new backup of MySql
					$this->stow("# " . __('WordPress MySQL database backup','wp-db-backup-storagemadeeasy') . "\n");
					$this->stow("#\n");
					$this->stow("# " . sprintf(__('Generated: %s','wp-db-backup-storagemadeeasy'),date("l j. F Y H:i T")) . "\n");
					$this->stow("# " . sprintf(__('Hostname: %s','wp-db-backup-storagemadeeasy'),DB_HOST) . "\n");
					$this->stow("# " . sprintf(__('Database: %s','wp-db-backup-storagemadeeasy'),$this->backquote(DB_NAME)) . "\n");
					$this->stow("# --------------------------------------------------------\n");
				} else {
					if($segment == 0) {
						// Increase script execution time-limit to 15 min for every table.
						if( !ini_get('safe_mode')) @set_time_limit(15*60);
						// Create the SQL statements
						$this->stow("# --------------------------------------------------------\n");
						$this->stow("# " . sprintf(__('Table: %s','wp-db-backup-storagemadeeasy'),$this->backquote($table)) . "\n");
						$this->stow("# --------------------------------------------------------\n");
					}			
					$this->backup_table($table, $segment);
				}
			}
		} else {
			$this->error(array('kind' => 'fatal', 'loc' => 'frame', 'msg' => __('The backup directory is not writeable!  Please check the permissions for writing to your backup directory and try again.','wp-db-backup-storagemadeeasy')));
		}

		if($this->fp) $this->close($this->fp);
		
		$this->error_display('frame');

		echo '<script type="text/javascript"><!--//
		var msg = "' . $msg . '";
		window.parent.setProgress(msg);
		window.parent.nextStep();
		//--></script>
		';
		die();
	}

	function perform_backup() {
		// are we backing up any other tables?
		$also_backup = array();
		if(isset($_POST['other_tables']))
			$also_backup = $_POST['other_tables'];
		$core_tables = $_POST['core_tables'];
		$this->backup_file = $this->db_backup($core_tables, $also_backup);
		if(false !== $this->backup_file) {
			if('smtp' == $_POST['deliver']) {
				$this->deliver_backup($this->backup_file, $_POST['deliver'], $_POST['backup_recipient'], 'main');
				wp_redirect($this->page_url);
			} elseif('http' == $_POST['deliver']) {
				$download_uri = add_query_arg('backup',$this->backup_file,$this->page_url);
				wp_redirect($download_uri); 
				exit;
			}
			// we do this to say we're done.
			$this->backup_complete = true;
		}
	}

	function admin_header() {
		?>
		<script type="text/javascript">
		//<![CDATA[
		if( 'undefined' != typeof addLoadEvent ) {
			addLoadEvent(function() {
				var t = {'extra-tables-list':{name: 'other_tables[]'}, 'include-tables-list':{name: 'wp_storagemadeeasy_cron_backup_tables[]'}};

				for ( var k in t ) {
					t[k].s = null;
					var d = document.getElementById(k);
					if( ! d )
						continue;
					var ul = d.getElementsByTagName('ul').item(0);
					if( ul ) {
						var lis = ul.getElementsByTagName('li');
						if( 3 > lis.length )
							return;
						var text = document.createElement('p');
						text.className = 'instructions';
						text.innerHTML = '<?php _e('Click and hold down <code>[SHIFT]</code> to toggle multiple checkboxes', 'wp-db-backup-storagemadeeasy'); ?>';
						ul.parentNode.insertBefore(text, ul);
					}
					t[k].p = d.getElementsByTagName("input");
					for(var i=0; i < t[k].p.length; i++)
						if(t[k].name == t[k].p[i].getAttribute('name')) {
							t[k].p[i].id = k + '-table-' + i;
							t[k].p[i].onkeyup = t[k].p[i].onclick = function(e) {
								e = e ? e : event;
								if( 16  == e.keyCode ) 
									return;
								var match = /([\w-]*)-table-(\d*)/.exec(this.id);
								var listname = match[1];
								var that = match[2];
								if( null === t[listname].s )
									t[listname].s = that;
								else if( e.shiftKey ) {
									var start = Math.min(that, t[listname].s) + 1;
									var end = Math.max(that, t[listname].s);
									for( var j=start; j < end; j++)
										t[listname].p[j].checked = t[listname].p[j].checked ? false : true;
									t[listname].s = null;
								}
							}
						}
				}

				<?php if( function_exists('wp_schedule_event') ) : // needs to be at least WP 2.1 for ajax ?>
				if( 'undefined' == typeof XMLHttpRequest ) 
					var xml = new ActiveXObject( navigator.userAgent.indexOf('MSIE 5') >= 0 ? 'Microsoft.XMLHTTP' : 'Msxml2.XMLHTTP' );
				else
					var xml = new XMLHttpRequest();

				var initTimeChange = function() {
					var timeWrap = document.getElementById('backup-time-wrap');
					var backupTime = document.getElementById('next-backup-time');
					if( !! timeWrap && !! backupTime ) {
						var span = document.createElement('span');
						span.className = 'submit';
						span.id = 'change-wrap';
						span.innerHTML = '<input type="submit" id="change-backup-time" name="change-backup-time" value="<?php _e('Change','wp-db-backup-storagemadeeasy'); ?>" />';
						timeWrap.appendChild(span);
						backupTime.ondblclick = function(e) { span.parentNode.removeChild(span); clickTime(e, backupTime); };
						span.onclick = function(e) { span.parentNode.removeChild(span); clickTime(e, backupTime); };
					}
				}

				var clickTime = function(e, backupTime) {
					var tText = backupTime.innerHTML;
					backupTime.innerHTML = '<input type="text" value="' + tText + '" name="backup-time-text" id="backup-time-text" /> <span class="submit"><input type="submit" name="save-backup-time" id="save-backup-time" value="<?php _e('Save', 'wp-db-backup-storagemadeeasy'); ?>" /></span>';
					backupTime.ondblclick = null;
					var mainText = document.getElementById('backup-time-text');
					mainText.focus();
					var saveTButton = document.getElementById('save-backup-time');
					if( !! saveTButton )
						saveTButton.onclick = function(e) { saveTime(backupTime, mainText); return false; };
					if( !! mainText )
						mainText.onkeydown = function(e) { 
							e = e || window.event;
							if( 13 == e.keyCode ) {
								saveTime(backupTime, mainText);
								return false;
							}
						}
				}

				var saveTime = function(backupTime, mainText) {
					var tVal = mainText.value;

					xml.open('POST', 'admin-ajax.php', true);
					xml.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
					if( xml.overrideMimeType )
						xml.setRequestHeader('Connection', 'close');
					xml.send('action=save_backup_time&_wpnonce=<?php echo wp_create_nonce($this->referer_check_key); ?>&backup-time='+tVal);
					xml.onreadystatechange = function() {
						if( 4 == xml.readyState && '0' != xml.responseText ) {
							backupTime.innerHTML = xml.responseText;
							initTimeChange();
						}
					}
				}

				initTimeChange();
				<?php endif; // wp_schedule_event exists ?>
			});
		}
		//]]>
		</script>
		<style type="text/css">
			.wp-db-backup-storagemadeeasy-updated {
				margin-top: 1em;
			}

			fieldset.options {
				border: 1px solid;
				margin-top: 1em;
				padding: 1em;
			}
				fieldset.options div.tables-list {
					float: left;
					padding: 1em;
				}

				fieldset.options input {
				}

				fieldset.options legend {
					font-size: larger;
					font-weight: bold;
					margin-bottom: .5em;
					padding: 1em;
				}
		
				fieldset.options .instructions {
					font-size: smaller;
				}

				fieldset.options ul {
					list-style-type: none;
				}
					fieldset.options li {
						text-align: left;
					}

				fieldset.options .submit {
					border-top: none;
				}
		</style>
		<?php 
	}

	function admin_load() {
		add_action('admin_head', array(&$this, 'admin_header'));
	}

	function admin_menu() {
		$_page_hook = add_management_page(__('Cloud Backup','wp-db-backup-storagemadeeasy'), __('Cloud Backup','wp-db-backup-storagemadeeasy'), 'import', $this->basename, array(&$this, 'backup_menu'));
		add_action('load-' . $_page_hook, array(&$this, 'admin_load'));
		if( function_exists('add_contextual_help') ) {
			$text = $this->help_menu();
			add_contextual_help($_page_hook, $text);
		}
	}

	function fragment_menu() {
		$page_hook = add_management_page(__('Cloud Backup','wp-db-backup-storagemadeeasy'), __('Cloud Backup','wp-db-backup-storagemadeeasy'), 'import', $this->basename, array(&$this, 'build_backup_script'));
		add_action('load-' . $page_hook, array(&$this, 'admin_load'));
	}

	/** 
	 * Add WP-DB-Backup-specific help options to the 2.7 =< WP contextual help menu
	 * return string The text of the help menu.
	 */
	function help_menu() {
		$text = "\n<a href=\"http://wordpress.org/extend/plugins/storagemadeeasy-multi-cloud-files-plug-in/\" target=\"_blank\">" . __('FAQ', 'wp-db-backup-storagemadeeasy') . '</a>';
		$text .= "\n<br />\n<a href=\"http://storagemadeeasy.com\" target=\"_blank\">" . __('Support', 'wp-db-backup-storagemadeeasy') . '</a>';
		return $text;
	}

	function save_backup_time() {
		if( $this->can_user_backup() ) {
			// try to get a time from the input string
			$time = strtotime(strval($_POST['backup-time']));
			if( ! empty( $time ) && time() < $time ) {
				wp_clear_scheduled_hook( 'wp_storagemadeeasy_backup_cron' ); // unschedule previous
				$scheds = (array) wp_get_schedules();
				$name = get_option('wp_storagemadeeasy_backup_cron');
				if( 0 != $time ) {
					wp_schedule_event($time, $name, 'wp_storagemadeeasy_backup_cron');
					echo gmdate(get_option('date_format') . ' ' . get_option('time_format'), $time + (get_option('gmt_offset') * 3600));
					exit;
				}
			}
		} else {
			die(0);
		}
	}

	/**
	 * Better addslashes for SQL queries.
	 * Taken from phpMyAdmin.
	 */
	function sql_addslashes($a_string = '', $is_like = false) {
		if($is_like) $a_string = str_replace('\\', '\\\\\\\\', $a_string);
		else $a_string = str_replace('\\', '\\\\', $a_string);
		return str_replace('\'', '\\\'', $a_string);
	} 

	/**
	 * Add backquotes to tables and db-names in
	 * SQL queries. Taken from phpMyAdmin.
	 */
	function backquote($a_name) {
		if(!empty($a_name) && $a_name != '*') {
			if(is_array($a_name)) {
				$result = array();
				reset($a_name);
				while(list($key, $val) = each($a_name)) 
					$result[$key] = '`' . $val . '`';
				return $result;
			} else {
				return '`' . $a_name . '`';
			}
		} else {
			return $a_name;
		}
	} 

	function open($filename = '', $mode = 'w') {
		if('' == $filename) return false;
		if($this->gzip()) 
			$fp = @gzopen($filename, $mode);
		else
			$fp = @fopen($filename, $mode);
		return $fp;
	}

	function close($fp) {
		if($this->gzip()) gzclose($fp);
		else fclose($fp);
	}

	/**
	 * Write to the backup file
	 * @param string $query_line the line to write
	 * @return null
	 */
	function stow($query_line) {
		if($this->gzip()) {
			if(! @gzwrite($this->fp, $query_line))
				$this->error(__('There was an error writing a line to the backup script:','wp-db-backup-storagemadeeasy') . '  ' . $query_line . '  ' . $php_errormsg);
		} else {
			if(false === @fwrite($this->fp, $query_line))
				$this->error(__('There was an error writing a line to the backup script:','wp-db-backup-storagemadeeasy') . '  ' . $query_line . '  ' . $php_errormsg);
		}
	}
	
	/**
	 * Logs any error messages
	 * @param array $args
	 * @return bool
	 */
	function error($args = array()) {
		if( is_string( $args ) ) 
			$args = array('msg' => $args);
		$args = array_merge( array('loc' => 'main', 'kind' => 'warn', 'msg' => ''), $args);
		$this->errors[$args['kind']][] = $args['msg'];
		if( 'fatal' == $args['kind'] || 'frame' == $args['loc'])
			$this->error_display($args['loc']);
		return true;
	}

	/**
	 * Displays error messages 
	 * @param array $errs
	 * @param string $loc
	 * @return string
	 */
	function error_display($loc = 'main', $echo = true) {
		$errs = $this->errors;
		unset( $this->errors );
		if( ! count($errs) ) return;
		$msg = '';
		$err_list = array_slice(array_merge( (array) $errs['fatal'], (array) $errs['warn']), 0, 10);
		if( 10 == count( $err_list ) )
			$err_list[9] = __('Subsequent errors have been omitted from this log.','wp-db-backup-storagemadeeasy');
		$wrap = ( 'frame' == $loc ) ? "<script type=\"text/javascript\">\n var msgList = ''; \n %1\$s \n if ( msgList ) alert(msgList); \n </script>" : '%1$s';
		$line = ( 'frame' == $loc ) ? 
			"try{ window.parent.addError('%1\$s'); } catch(e) { msgList += ' %1\$s';}\n" :
			"%1\$s<br />\n";
		foreach( (array) $err_list as $err )
			$msg .= sprintf($line,str_replace(array("\n","\r"), '', addslashes($err)));
		$msg = sprintf($wrap,$msg);
		if( count($errs['fatal'] ) ) {
			if( function_exists('wp_die') && 'frame' != $loc ) wp_die(stripslashes($msg));
			else die($msg);
		}
		else {
			if( $echo ) echo $msg;
			else return $msg;
		}
	}

	/**
	 * Taken partially from phpMyAdmin and partially from
	 * Alain Wolf, Zurich - Switzerland
	 * Website: http://restkultur.ch/personal/wolf/scripts/db_backup/
	
	 * Modified by Scott Merrill (http://www.skippy.net/) 
	 * to use the WordPress $wpdb object
	 * @param string $table
	 * @param string $segment
	 * @return void
	 */
	function backup_table($table, $segment = 'none') {
		global $wpdb;

		$table_structure = $wpdb->get_results("DESCRIBE $table");
		if(! $table_structure) {
			$this->error(__('Error getting table details','wp-db-backup-storagemadeeasy') . ": $table");
			return false;
		}
	
		if(($segment == 'none') || ($segment == 0)) {
			// Add SQL statement to drop existing table
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('Delete any existing table %s','wp-db-backup-storagemadeeasy'),$this->backquote($table)) . "\n");
			$this->stow("#\n");
			$this->stow("\n");
			$this->stow("DROP TABLE IF EXISTS " . $this->backquote($table) . ";\n");
			
			// Table structure
			// Comment in SQL-file
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('Table structure of table %s','wp-db-backup-storagemadeeasy'),$this->backquote($table)) . "\n");
			$this->stow("#\n");
			$this->stow("\n");
			
			$create_table = $wpdb->get_results("SHOW CREATE TABLE $table", ARRAY_N);
			if(false === $create_table) {
				$err_msg = sprintf(__('Error with SHOW CREATE TABLE for %s.','wp-db-backup-storagemadeeasy'), $table);
				$this->error($err_msg);
				$this->stow("#\n# $err_msg\n#\n");
			}
			$this->stow($create_table[0][1] . ' ;');
			
			if(false === $table_structure) {
				$err_msg = sprintf(__('Error getting table structure of %s','wp-db-backup-storagemadeeasy'), $table);
				$this->error($err_msg);
				$this->stow("#\n# $err_msg\n#\n");
			}
		
			// Comment in SQL-file
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow('# ' . sprintf(__('Data contents of table %s','wp-db-backup-storagemadeeasy'),$this->backquote($table)) . "\n");
			$this->stow("#\n");
		}
		
		if(($segment == 'none') || ($segment >= 0)) {
			$defs = array();
			$ints = array();
			foreach ($table_structure as $struct) {
				if( (0 === strpos($struct->Type, 'tinyint')) ||
					(0 === strpos(strtolower($struct->Type), 'smallint')) ||
					(0 === strpos(strtolower($struct->Type), 'mediumint')) ||
					(0 === strpos(strtolower($struct->Type), 'int')) ||
					(0 === strpos(strtolower($struct->Type), 'bigint')) ) {
						$defs[strtolower($struct->Field)] = ( null === $struct->Default ) ? 'NULL' : $struct->Default;
						$ints[strtolower($struct->Field)] = "1";
				}
			}
			
			
			// Batch by $row_inc
			
			if($segment == 'none') {
				$row_start = 0;
				$row_inc = ROWS_PER_SEGMENT;
			} else {
				$row_start = $segment * ROWS_PER_SEGMENT;
				$row_inc = ROWS_PER_SEGMENT;
			}
			
			do {	
				// don't include extra stuff, if so requested
				$excs = (array) get_option('wp_db_backup_storagemadeeasy_excs');
				$where = '';
				if( is_array($excs['spam'] ) && in_array($table, $excs['spam']) ) {
					$where = ' WHERE comment_approved != "spam"';
				} elseif( is_array($excs['revisions'] ) && in_array($table, $excs['revisions']) ) {
					$where = ' WHERE post_type != "revision"';
				}
				
				if( !ini_get('safe_mode')) @set_time_limit(15*60);
				$table_data = $wpdb->get_results("SELECT * FROM $table $where LIMIT {$row_start}, {$row_inc}", ARRAY_A);

				$entries = 'INSERT INTO ' . $this->backquote($table) . ' VALUES (';	
				//    \x08\\x09, not required
				$search = array("\x00", "\x0a", "\x0d", "\x1a");
				$replace = array('\0', '\n', '\r', '\Z');
				if($table_data) {
					foreach ($table_data as $row) {
						$values = array();
						foreach ($row as $key => $value) {
#							if($ints[strtolower($key)]) {
							if(isset($ints[strtolower($key)])){
								// make sure there are no blank spots in the insert syntax,
								// yet try to avoid quotation marks around integers
								$value = ( null === $value || '' === $value) ? $defs[strtolower($key)] : $value;
								$values[] = ( '' === $value ) ? "''" : $value;
							} else {
								$values[] = "'" . str_replace($search, $replace, $this->sql_addslashes($value)) . "'";
							}
						}
						$this->stow(" \n" . $entries . implode(', ', $values) . ');');
					}
					$row_start += $row_inc;
				}
			} while((count($table_data) > 0) and ($segment=='none'));
		}
		
		if(($segment == 'none') || ($segment < 0)) {
			// Create footer/closing comment in SQL-file
			$this->stow("\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('End of data contents of table %s','wp-db-backup-storagemadeeasy'),$this->backquote($table)) . "\n");
			$this->stow("# --------------------------------------------------------\n");
			$this->stow("\n");
		}
	} // end backup_table()
	
	function db_backup($core_tables, $other_tables) {
		global $table_prefix, $wpdb;
		
		if(is_writable($this->backup_dir)) {
			$this->fp = $this->open($this->backup_dir . $this->backup_filename);
			if(!$this->fp) {
				$this->error(__('Could not open the backup file for writing!','wp-db-backup-storagemadeeasy'));
				return false;
			}
		} else {
			$this->error(__('The backup directory is not writeable!','wp-db-backup-storagemadeeasy'));
			return false;
		}
		
		//Begin new backup of MySql
		$this->stow("# " . __('WordPress MySQL database backup','wp-db-backup-storagemadeeasy') . "\n");
		$this->stow("#\n");
		$this->stow("# " . sprintf(__('Generated: %s','wp-db-backup-storagemadeeasy'),date("l j. F Y H:i T")) . "\n");
		$this->stow("# " . sprintf(__('Hostname: %s','wp-db-backup-storagemadeeasy'),DB_HOST) . "\n");
		$this->stow("# " . sprintf(__('Database: %s','wp-db-backup-storagemadeeasy'),$this->backquote(DB_NAME)) . "\n");
		$this->stow("# --------------------------------------------------------\n");
		
			if( (is_array($other_tables)) && (count($other_tables) > 0) )
			$tables = array_merge($core_tables, $other_tables);
		else
			$tables = $core_tables;
		
		foreach ($tables as $table) {
			// Increase script execution time-limit to 15 min for every table.
			if( !ini_get('safe_mode')) @set_time_limit(15*60);
			// Create the SQL statements
			$this->stow("# --------------------------------------------------------\n");
			$this->stow("# " . sprintf(__('Table: %s','wp-db-backup-storagemadeeasy'),$this->backquote($table)) . "\n");
			$this->stow("# --------------------------------------------------------\n");
			$this->backup_table($table);
		}
				
		$this->close($this->fp);
		
		if(count($this->errors)) {
			return false;
		} else {
			return $this->backup_filename;
		}
		
	} //wp_db_backup_storagemadeeasy

	/**
	 * Sends the backed-up file via email
	 * @param string $to
	 * @param string $subject
	 * @param string $message
	 * @return bool
	 */
	function send_mail( $to, $subject, $message, $diskfile) {
		global $phpmailer;

		$filename = basename($diskfile);

		extract( apply_filters( 'wp_mail', compact( 'to', 'subject', 'message' ) ) );

		if( !is_object( $phpmailer ) || ( strtolower(get_class( $phpmailer )) != 'phpmailer' ) ) {
			if( file_exists( ABSPATH . WPINC . '/class-phpmailer.php' ) )
				require_once ABSPATH . WPINC . '/class-phpmailer.php';
			if( file_exists( ABSPATH . WPINC . '/class-smtp.php' ) )
				require_once ABSPATH . WPINC . '/class-smtp.php';
			if( class_exists( 'PHPMailer') )
				$phpmailer = new PHPMailer();
		}

		// try to use phpmailer directly (WP 2.2+)
		if( is_object( $phpmailer ) && ( strtolower(get_class( $phpmailer )) == 'phpmailer' ) ) {
			
			// Get the site domain and get rid of www.
			$sitename = strtolower( $_SERVER['SERVER_NAME'] );
			if( substr( $sitename, 0, 4 ) == 'www.' ) {
				$sitename = substr( $sitename, 4 );
			}
			$from_email = 'wordpress@' . $sitename;
			$from_name = 'WordPress';

			// Empty out the values that may be set
			$phpmailer->ClearAddresses();
			$phpmailer->ClearAllRecipients();
			$phpmailer->ClearAttachments();
			$phpmailer->ClearBCCs();
			$phpmailer->ClearCCs();
			$phpmailer->ClearCustomHeaders();
			$phpmailer->ClearReplyTos();

			$phpmailer->AddAddress( $to );
			$phpmailer->AddAttachment($diskfile, $filename);
			$phpmailer->Body = $message;
			$phpmailer->CharSet = apply_filters( 'wp_mail_charset', get_bloginfo('charset') );
			$phpmailer->From = apply_filters( 'wp_mail_from', $from_email );
			$phpmailer->FromName = apply_filters( 'wp_mail_from_name', $from_name );
			$phpmailer->IsMail();
			$phpmailer->Subject = $subject;

			do_action_ref_array( 'phpmailer_init', array( &$phpmailer ) );
			
			$result = @$phpmailer->Send();

		// old-style: build the headers directly
		} else {
			$randomish = md5(time());
			$boundary = "==WPBACKUP-$randomish";
			$fp = fopen($diskfile,"rb");
			$file = fread($fp,filesize($diskfile)); 
			$this->close($fp);
			
			$data = chunk_split(base64_encode($file));
			
			$headers .= "MIME-Version: 1.0\n";
			$headers = 'From: wordpress@' . preg_replace('#^www\.#', '', strtolower($_SERVER['SERVER_NAME'])) . "\n";
			$headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\n";
		
			// Add a multipart boundary above the plain message
			$message = "This is a multi-part message in MIME format.\n\n" .
		        	"--{$boundary}\n" .
				"Content-Type: text/plain; charset=\"" . get_bloginfo('charset') . "\"\n" .
				"Content-Transfer-Encoding: 7bit\n\n" .
				$message . "\n\n";

			// Add file attachment to the message
			$message .= "--{$boundary}\n" .
				"Content-Type: application/octet-stream;\n" .
				" name=\"{$filename}\"\n" .
				"Content-Disposition: attachment;\n" .
				" filename=\"{$filename}\"\n" .
				"Content-Transfer-Encoding: base64\n\n" .
				$data . "\n\n" .
				"--{$boundary}--\n";
			
			$result = @wp_mail($to, $subject, $message, $headers);
		}
		return $result;

	}

	function deliver_backup($filename = '', $delivery = 'http', $recipient = '', $location = 'main') {
		if(empty($filename)) return false;

		$diskfile = $this->backup_dir . $filename;
		$path0='';
		$path=$diskfile;
		
		$path2=array();
		$path2[]=substr($path, 0, strrpos($path, '.sql')) .'_uploads.zip';
		$path2[]=substr($path, 0, strrpos($path, '.sql')) .'_plugins.zip';

		for($i=0; isset($path2[$i]); $i++){		# we remove files that don't exists
			if(file_exists($path2[$i])) continue;
			array_splice($path2, $i, 1);
			$i--;
		}

		if(file_exists($path) && count($path2)>0){ // make 1 file
			$path0=$path;
			if(substr($path0, strlen($path0)-strlen('.sql.gz'))=='.sql.gz')	$path0=substr($path0, 0, strlen($path0)-strlen('.sql.gz')). '.zip';
			if(substr($path0, strlen($path0)-strlen('.sql'))=='.sql')	$path0=substr($path0, 0, strlen($path0)-strlen('.sql')). '.zip';
			$path2[]=$path;
			$res=$this->make_one_backup_file($path0, $path2, 1);

			if($res===false || (isset($res['fail']) && $res['fail']!=0) || (isset($res['error']) && $res['error']!='')){
				$path0='';
				if(isset($res['error']) && $res['error']!=''){
					$error=$res['error'];
				}else{
					$error='Cannot create one file with the backup';
				}
			}else{
				$path='';
				unset($path2);
			}
		}else{
			$path0=$path;
		}

		if(strlen($path0)>1){
			$diskfile=$path0;
			$newfilename=basename($diskfile);
			if(strlen($newfilename)>0) $filename=$newfilename;
		}

		if('http'==$delivery) {
			if(! file_exists($diskfile)) 
				$this->error(array('kind' => 'fatal', 'msg' => sprintf(__('File not found:%s','wp-db-backup-storagemadeeasy'), "&nbsp;<strong>$filename</strong><br />") . '<br /><a href="' . $this->page_url . '">' . __('Return to Backup','wp-db-backup-storagemadeeasy') . '</a>'));
			header('Content-Description: File Transfer');
			header('Content-Type: application/octet-stream');
			header('Content-Length: ' . filesize($diskfile));
			header("Content-Disposition: attachment; filename=$filename");

			$success=readfile($diskfile);
			unlink($diskfile);
		}elseif('smtp' == $delivery){
			if(!file_exists($diskfile)){
				$msg = sprintf(__('File %s does not exist!','wp-db-backup-storagemadeeasy'), $diskfile);
				$this->error($msg);
				return false;
			}
			if(!is_email($recipient)){
				$recipient=get_option('admin_email');
			}
			$message = sprintf(__("Attached to this email is\n   %1s\n   Size:%2s kilobytes\n",'wp-db-backup-storagemadeeasy'), $filename, round(filesize($diskfile)/1024));
			$success = $this->send_mail($recipient, get_bloginfo('name') . ' ' . __('Database Backup','wp-db-backup-storagemadeeasy'), $message, $diskfile);

			if(false===$success){
				$msg = __('The following errors were reported:','wp-db-backup-storagemadeeasy') . "\n ";
				if( function_exists('error_get_last') ) {
					$err = error_get_last();
					$msg .= $err['message'];
				} else {
					$msg .= __('ERROR: The mail application has failed to deliver the backup.','wp-db-backup-storagemadeeasy'); 
				}
				$this->error(array('kind' => 'fatal', 'loc' => $location, 'msg' => $msg));
			} else {
				unlink($diskfile);
			}
		}
		return $success;
	}

	function backup_menu() {
		if(isset($_REQUEST['status']) && $_REQUEST['status']=="completed"){
#	 		echo "<iframe src='".$_SESSION['testing123']."' width=0 height=0></iframe>";
		}

		global $table_prefix, $wpdb;
		$feedback = '';
		$whoops = false;
		
		// did we just do a backup?  If so, let's report the status
		if( $this->backup_complete ) {
			$feedback = '<div class="updated wp-db-backup-storagemadeeasy-updated"><p>' . __('Backup Successful','wp-db-backup-storagemadeeasy') . '!';
			$file = $this->backup_file;
			switch($_POST['deliver']) {
			case 'http':
				$feedback .= '<br />' . sprintf(__('Your backup file: <a href="%1s">%2s</a> should begin downloading shortly.','wp-db-backup-storagemadeeasy'), WP_BACKUP_URL . "{$this->backup_file}", $this->backup_file);
				break;
			case 'smtp':
				if(! is_email($_POST['backup_recipient'])) {
					$feedback .= get_option('admin_email');
				} else {
					$feedback .= $_POST['backup_recipient'];
				}
				$feedback = '<br />' . sprintf(__('Your backup has been emailed to %s','wp-db-backup-storagemadeeasy'), $feedback);
				break;
			case 'none':
				$feedback .= '<br />' . __('Your backup file has been saved on the server. If you would like to download it now, right click and select "Save As"','wp-db-backup-storagemadeeasy');
				$feedback .= ':<br /> <a href="' . WP_BACKUP_URL . "$file\">$file</a> : " . sprintf(__('%s bytes','wp-db-backup-storagemadeeasy'), filesize($this->backup_dir . $file));
			}
			$feedback .= '</p></div>';
		}
	
		// security check
		$this->wp_secure();  

		if(count($this->errors)) {
			$feedback .= '<div class="updated wp-db-backup-storagemadeeasy-updated error"><p><strong>' . __('The following errors were reported:','wp-db-backup-storagemadeeasy') . '</strong></p>';
			$feedback .= '<p>' . $this->error_display( 'main', false ) . '</p>';
			$feedback .= "</p></div>";
		}

		// did we just save options for wp-cron?
#		if( (function_exists('wp_schedule_event') || function_exists('wp_cron_init')) 
		if(function_exists('wp_schedule_event') && isset($_POST['wp_cron_backup_storagemadeeasy_options']) ) :
			wp_clear_scheduled_hook('wp_storagemadeeasy_backup_cron'); // unschedule previous
				$scheds = (array) wp_get_schedules();
				$name = strval($_POST['wp_cron_schedule']);
				$interval = ( isset($scheds[$name]['interval']) ) ? 
					(int) $scheds[$name]['interval'] : 0;
				update_option('wp_cron_backup_schedule', $name, false);


#			if(0!==$interval && !empty($name) && $name!='never'){
			if(!empty($name) && $name!='never'){
				update_option('storagemadeeasySchedulerInt',$name);
				wp_schedule_event(time() + $interval, $name, 'wp_storagemadeeasy_backup_cron');
			}else{
				update_option('storagemadeeasySchedulerInt','');
			}

			$buTables='';
			if(isset($_POST['wp_storagemadeeasy_cron_backup_tables'])) $buTables=$_POST['wp_storagemadeeasy_cron_backup_tables'];
			update_option('wp_storagemadeeasy_cron_backup_tables', $buTables);
			$feedback .= '<div class="updated wp-db-backup-storagemadeeasy-updated"><p>' . __('Scheduled Backup Options Saved!','wp-db-backup-storagemadeeasy') . '</p></div>';
		endif;
		
		$other_tables = array();
		$also_backup = array();
	
		// Get complete db table list	
		$all_tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
		$all_tables = array_map(create_function('$a', 'return $a[0];'), $all_tables);
		// Get list of WP tables that actually exist in this DB (for 1.8 compat!)
		$wp_backup_default_tables = array_intersect($all_tables, $this->core_table_names);
		// Get list of non-WP tables
		$other_tables = array_diff($all_tables, $wp_backup_default_tables);
		
		if('' != $feedback)
			echo $feedback;

		if( ! $this->wp_secure() ) 	
			return;

		// Give the new dirs the same perms as wp-content.
//		$stat = stat( ABSPATH . 'wp-content' );
//		$dir_perms = $stat['mode'] & 0000777; // Get the permission bits.
		$dir_perms = '0777';

		// the file doesn't exist and can't create it
		if( ! file_exists($this->backup_dir) && ! @mkdir($this->backup_dir) ) {
			?><div class="updated wp-db-backup-storagemadeeasy-updated error"><p><?php _e('WARNING: Your backup directory does <strong>NOT</strong> exist, and we cannot create it.','wp-db-backup-storagemadeeasy'); ?></p>
			<p><?php printf(__('Using your FTP client, try to create the backup directory yourself: %s', 'wp-db-backup-storagemadeeasy'), '<code>' . $this->backup_dir . '</code>'); ?></p></div><?php
			$whoops = true;
		// not writable due to write permissions
		} elseif( !is_writable($this->backup_dir) && ! @chmod($this->backup_dir, $dir_perms) ) {
			?><div class="updated wp-db-backup-storagemadeeasy-updated error"><p><?php _e('WARNING: Your backup directory is <strong>NOT</strong> writable! We cannot create the backup files.','wp-db-backup-storagemadeeasy'); ?></p>
			<p><?php printf(__('Using your FTP client, try to set the backup directory&rsquo;s write permission to %1$s or %2$s: %3$s', 'wp-db-backup-storagemadeeasy'), '<code>777</code>', '<code>a+w</code>', '<code>' . $this->backup_dir . '</code>'); ?>
			</p></div><?php 
			$whoops = true;
		} else {
			$this->fp = $this->open($this->backup_dir . 'test' );
			if( $this->fp ) { 
				$this->close($this->fp);
				@unlink($this->backup_dir . 'test' );
			// the directory is not writable probably due to safe mode
			} else {
				?><div class="updated wp-db-backup-storagemadeeasy-updated error"><p><?php _e('WARNING: Your backup directory is <strong>NOT</strong> writable! We cannot create the backup files.','wp-db-backup-storagemadeeasy'); ?></p><?php 
				if( ini_get('safe_mode') ){
					?><p><?php _e('This problem seems to be caused by your server&rsquo;s <code>safe_mode</code> file ownership restrictions, which limit what files web applications like WordPress can create.', 'wp-db-backup-storagemadeeasy'); ?></p><?php 
				}
				?><?php printf(__('You can try to correct this problem by using your FTP client to delete and then re-create the backup directory: %s', 'wp-db-backup-storagemadeeasy'), '<code>' . $this->backup_dir . '</code>');
				?></div><?php 
				$whoops = true;
			}
		}

		if( !file_exists($this->backup_dir . 'index.php') )
			@ touch($this->backup_dir . 'index.php');
		?><div class='wrap'>
		<h2><?php _e('Cloud Backup','wp-db-backup-storagemadeeasy') ?></h2>
		<h3>StorageMadeEasy Cloud Backup</h3>
		<p>This plugin enables you to backup your WordPress database and content to StorageMadeEasy which uses its cloud gateway to enable you to store your files directly on the storage clouds of your choice. These include Amazon S3, RackSpace Cloud Files, SkyDrive, DropBox, Gmail, Box.net, RackSpace Cloud Files, FTP & WebDAV. Contact us <a href="mailto:sales@storagemadeeasy.com">sales@storagemadeeasy.com</a> about our professional option which includes the ability to encrypt the backup and to also schedule it.</p>
		<p>If total size of files that you want to backup is bigger than 4GB then archive cannot be created.</p>
		<?php
			$username = get_option('storagemadeeasy_username');
			$password = get_option('storagemadeeasy_password');

			if(!empty($_POST['username']) || !empty($_POST['password'])){
				echo "Storage Made Easy Settings Saved";	
			}
		?>
		<form method="post" action="">

		<?php if( function_exists('wp_nonce_field') ) wp_nonce_field($this->referer_check_key); ?>
		<fieldset class="options" style="margin:0px;"><legend><?php _e('Backup Content','wp-db-backup-storagemadeeasy') ?></legend>
      <fieldset class="options" style="margin:0px;"><legend><?php _e('Tables','wp-db-backup-storagemadeeasy') ?></legend>
      <div class="tables-list core-tables alternate">
      <h4><?php _e('These core WordPress tables will always be backed up:','wp-db-backup-storagemadeeasy') ?></h4><ul><?php
      $excs = (array) get_option('wp_db_backup_storagemadeeasy_excs');
      foreach ($wp_backup_default_tables as $table) {
        if( $table == $wpdb->comments ) {
          $checked = ( isset($excs['spam']) && is_array($excs['spam'] ) && in_array($table, $excs['spam']) ) ? ' checked=\'checked\'' : '';
          echo "<li><input type='hidden' name='core_tables[]' value='$table' /><code>$table</code> <span class='instructions'> <input type='checkbox' name='exclude-spam[]' value='$table' $checked /> " . __('Exclude spam comments', 'wp-db-backup-storagemadeeasy') . '</span></li>';
        } elseif( function_exists('wp_get_post_revisions') && $table == $wpdb->posts ) {
            $checked = ( isset($excs['revisions']) && is_array($excs['revisions'] ) && in_array($table, $excs['revisions']) ) ? ' checked=\'checked\'' : '';
          echo "<li><input type='hidden' name='core_tables[]' value='$table' /><code>$table</code> <span class='instructions'> <input type='checkbox' name='exclude-revisions[]' value='$table' $checked /> " . __('Exclude post revisions', 'wp-db-backup-storagemadeeasy') . '</span></li>';
        } else {
          echo "<li><input type='hidden' name='core_tables[]' value='$table' /><code>$table</code></li>";
        }
      }
      ?></ul>
      </div>
      <div class="tables-list extra-tables" id="extra-tables-list">
      <?php 
      if(count($other_tables) > 0) { 
        ?>
        <h4><?php _e('You may choose to include any of the following tables:','wp-db-backup-storagemadeeasy'); ?></h4>
        <ul>
        <?php
        foreach ($other_tables as $table) {
          ?>
          <li><label><input type="checkbox" name="other_tables[]" value="<?php echo $table; ?>" /> <code><?php echo $table; ?></code></label>
          <?php 
        }
        ?></ul><?php 
      }
      ?></div>
      </fieldset>

      <fieldset class="options" style="margin:0px;"><legend><?php _e('Folders','wp-db-backup-storagemadeeasy') ?></legend>
      <div class="tables-list core-tables alternate">
				<label style="padding-bottom:15px;"><input type="checkbox" name="uploads_folder" id="uploads_folder" <?php if(isset($_POST['uploads_folder'])){ echo 'checked="checked"'; } ?> value=""> <code>Uploads</code></label><br>
				<label><input type="checkbox" name="plugins_folder" id="plugins_folder" <?php if(isset($_POST['plugins_folder'])){ echo 'checked="checked"'; } ?> value=""> <code>Plugins</code></label>
      </div>
      </fieldset>
		</fieldset>
		
		<fieldset class="options">
			<legend><?php _e('Backup Options','wp-db-backup-storagemadeeasy'); ?></legend>
			<p><?php  _e('What to do with the backup file:','wp-db-backup-storagemadeeasy'); ?></p>
			<ul>
			<li><label for="do_save">
				<input type="radio" id="do_save" checked="checked" name="deliver" value="none" style="border:none;" />
				<?php _e('Save to Storage Made Easy','wp-db-backup-storagemadeeasy');

				//echo " (<code>" . $this->backup_dir . "</code>)"; ?>
			</label></li>
			<li><label for="do_download">
				<input type="radio" id="do_download" name="deliver" value="http" style="border:none;" />
				<?php _e('Download to your computer','wp-db-backup-storagemadeeasy'); ?>
			</label></li>
			<li><label for="do_email">
				<input type="radio" name="deliver" id="do_email" value="smtp" style="border:none;" />
				<?php _e('Email backup to:','wp-db-backup-storagemadeeasy'); ?>
				<input type="text" name="backup_recipient" size="40" value="<?php echo str_replace('"', '&quot;', get_option('admin_email')); ?>" />
			</label></li>
			</ul>
			<?php if( ! $whoops ) : ?>
			<input type="hidden" name="do_backup_storagemadeeasy" id="do_backup_storagemadeeasy" value="backup" /> 
			<p class="submit">
				<input type="submit" name="submit" onclick="document.getElementById('do_backup_storagemadeeasy').value='fragments';" value="<?php _e('Backup now!','wp-db-backup-storagemadeeasy'); ?>" />
			</p>
			<?php else : ?>
				<div class="updated wp-db-backup-storagemadeeasy-updated error"><p><?php _e('WARNING: Your backup directory is <strong>NOT</strong> writable!','wp-db-backup-storagemadeeasy'); ?></p></div>
			<?php endif; // ! whoops ?>
		</fieldset>
		<?php do_action('wp_db_b_backup_storagemadeeasy_opts'); ?>
		</form>
		<?php
			$username=str_replace('"', '&quot;', get_option('storagemadeeasy_username'));
			$password=str_replace('"', '&quot;', get_option('storagemadeeasy_password'));
			$storagemadeeasy_server=get_option('storagemadeeasy_server');
			if(empty($storagemadeeasy_server)) $storagemadeeasy_server='storagemadeeasy.com';
			$pathToImage='./';
			if(file_exists(WP_PLUGIN_URL.'/storagemadeeasy-multi-cloud-files-plug-in/http.php')){
				$pathToImage=WP_PLUGIN_URL.'/storagemadeeasy-multi-cloud-files-plug-in/';
			}elseif(file_exists('../wp-content/plugins/storagemadeeasy-multi-cloud-files-plug-in/http.php')){
				$pathToImage='../wp-content/plugins/storagemadeeasy-multi-cloud-files-plug-in/';
			}else{	# it is need for scheduler
				$pathToImage='./wp-content/plugins/storagemadeeasy-multi-cloud-files-plug-in/';
			}
		?>
		<fieldset class="options"><legend>SME Details</legend>
		<table>
		 <form name="form3" action="tools.php?page=wp-db-backup-storagemadeeasy" method="post">
		 <tr><td>Server</td><td>
				<table border="0" cellpadding="0" cellspacing="0" style="margin-top:2px; margin-bottom:2px;">
					<tr>
						<td><input type="text" id="storagemadeeasy_server" name="storagemadeeasy_server" value="<?php echo $storagemadeeasy_server; ?>" style="width:232px; height:22px; margin-right:0px; padding-right:0px"></td>
						<td style="margin:0px; padding:0px; background-image:url(<?php echo $pathToImage;?>selectButton.png); background-repeat:no-repeat; overflow: hidden;">
							<select size="1" id="storagemadeeasy_server2" style="width:18px; margin-left:0px; padding-left:0px; height:22px; opacity:0.0;" onClick="updateServer();" onChange="updateServer();">
								<option value="" selected="selected"> </option>
								<option value="storagemadeeasy.com">storagemadeeasy.com</option>
								<option value="eu.storagemadeeasy.com">eu.storagemadeeasy.com</option>
							</select>
						</td>
					</tr>
				</table>
				<script>
				function updateServer(){
					if(document.getElementById("storagemadeeasy_server2").value=='') return ;
					document.getElementById("storagemadeeasy_server").value=document.getElementById("storagemadeeasy_server2").value;
				}
				</script>
         </td></tr>

		 <tr><td>Username</td><td><input type="text" name="username" style="width:250px" value="<?php echo $username;?>" /></td></tr>
		 <tr><td>Password</td><td><input type="password" name="password" style="width:250px" value="<?php echo $password;?>" /></td></tr>
		 <tr><td colspan="2">Don't have account? <a href="http://storagemadeeasy.com/pricing/#free" target="_blank">Sign Up for Free</a></td></tr>
		 <tr height="2px"><td colspan="2"></td></tr>
		 <tr><td colspan="2"><input type="submit" value=" Submit " /></td></tr>
		 </form>
		 </table></fieldset>
		<?php

#		echo '</div><!-- .wrap -->';
		
	} // end wp_backup_menu()


	/**
	 * Checks that WordPress has sufficient security measures 
	 * @param string $kind
	 * @return bool
	 */
	function wp_secure($kind = 'warn', $loc = 'main') {
		global $wp_version;
		if(function_exists('wp_verify_nonce')){
			return true;
		}else{
			$msg=sprintf(__('Your WordPress version, %1s, lacks important security features without which it is unsafe to use the WP-DB-Backup plugin.  Hence, this plugin is automatically disabled.  Please consider <a href="%2s">upgrading WordPress</a> to a more recent version.','wp-db-backup-storagemadeeasy'),$wp_version,'http://wordpress.org/download/');
			$msg=str_replace('WP-DB-Backup', 'StorageMadeEasy-Multi-Cloud-Files', $msg);
			$this->error(array('kind' => $kind, 'loc' => $loc, 'msg' => $msg));
			return false;
		}
	}

	/**
	 * Checks that the user has sufficient permission to backup
	 * @param string $loc
	 * @return bool
	 */
	function can_user_backup($loc = 'main') {
		$can = false;
		// make sure WPMU users are site admins, not ordinary admins
		if( function_exists('is_site_admin') && ! is_site_admin() )
			return false;
		if( ( $this->wp_secure('fatal', $loc) ) && current_user_can('import') )
			$can = $this->verify_nonce($_REQUEST['_wpnonce'], $this->referer_check_key, $loc);
		if( false == $can ) 
			$this->error(array('loc' => $loc, 'kind' => 'fatal', 'msg' => __('You are not allowed to perform backups.','wp-db-backup-storagemadeeasy')));
		return $can;
	}

	/**
	 * Verify that the nonce is legitimate
	 * @param string $rec 	the nonce received
	 * @param string $nonce	what the nonce should be
	 * @param string $loc 	the location of the check
	 * @return bool
	 */
	function verify_nonce($rec = '', $nonce = 'X', $loc = 'main') {
		if( wp_verify_nonce($rec, $nonce) )
			return true;
		else 
			$this->error(array('loc' => $loc, 'kind' => 'fatal', 'msg' => sprintf(__('There appears to be an unauthorized attempt from this site to access your database located at %1s.  The attempt has been halted.','wp-db-backup-storagemadeeasy'),get_option('home'))));
	}

	/**
	 * Check whether a file to be downloaded is  
	 * surreptitiously trying to download a non-backup file
	 * @param string $file
	 * @return null
	 */ 
	function validate_file($file) {
		if( (false !== strpos($file, '..')) || (false !== strpos($file, './')) || (':' == substr($file, 1, 1)) )
			$this->error(array('kind' => 'fatal', 'loc' => 'frame', 'msg' => __("Cheatin' uh ?",'wp-db-backup-storagemadeeasy')));
	}

}

function wpdbBackup_StorageMadeEasy_init() {
	global $mywpdbbackup;
	$mywpdbbackup = new wpdbBackup_StorageMadeEasy(); 	
}

add_action('plugins_loaded', 'wpdbBackup_StorageMadeEasy_init');



?>