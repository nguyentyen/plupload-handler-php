<?php


define('PLUPLOAD_MOVE_ERR', 103);
define('PLUPLOAD_INPUT_ERR', 101);
define('PLUPLOAD_OUTPUT_ERR', 102);
define('PLUPLOAD_TMPDIR_ERR', 100);
define('PLUPLOAD_TYPE_ERR', 104);
define('PLUPLOAD_UNKNOWN_ERR', 111);


class PluploadHandler {

	public $conf;

	private $_error = null;


	private $_errors = array(
		PLUPLOAD_MOVE_ERR => "Failed to move uploaded file.",
		PLUPLOAD_INPUT_ERR => "Failed to open input stream.",
		PLUPLOAD_OUTPUT_ERR => "Failed to open output stream.",
		PLUPLOAD_TMPDIR_ERR => "Failed to open temp directory.",
		PLUPLOAD_TYPE_ERR => "File type not allowed.",
		PLUPLOAD_UNKNOWN_ERR => "Failed due to unknown error."
	);


	/**
	 * Retrieve the error code
	 *
	 * @return int Error code
	 */
	function get_error_code()
	{
		if (!self::$_error) {
			return null;
		} 

		if (!isset(self::$_errors[self::$_error])) {
			return PLUPLOAD_UNKNOWN_ERR;
		}

		return self::$_error;
	}


	/**
	 * Retrieve the error message
	 *
	 * @return string Error message
	 */
	function get_error_message()
	{
		if ($code = self::get_error_code()) {
			return self::$_errors[$code];
		}
		return '';
	}


	/**
	 * 
	 */
	function handle($conf = array())
	{
		// 5 minutes execution time
		@set_time_limit(5 * 60);

		self::$conf = array_merge(array(
			'file_data_name' => 'file',
			'target_dir' => ini_get("upload_tmp_dir") . DIRECTORY_SEPARATOR . "plupload",
			'cleanup' => true,
			'max_file_age' => 5 * 3600,
			'chunk' => isset($_REQUEST['chunk']) ? intval($_REQUEST['chunk']) : 0,
			'chunks' => isset($_REQUEST['chunks']) ? intval($_REQUEST['chunks']) : 0,
			'file_name' => isset($_REQUEST['name']) ? $_REQUEST['name'] : uniqid('file_'),
			'allow_extensions' => false,
			'allow_origin' => false,
			'delay' => 0
		), $conf);

		self::$_error = null; // start fresh

		try {
			// Check if target dir exists and is writeable
			if (!file_exists(self::$conf['target_dir']) && !@mkdir(self::$conf['target_dir'])) {
				throw new Exception('', PLUPLOAD_TMPDIR_ERR);
			}

			// Cleanup outdated temp files and folders
			if (self::$conf['cleanup']) {
				self::cleanup();
			}

			// Fake network congestion
			if (self::$conf['delay']) {
				usleep(self::$conf['delay']);
			}

			$file_name = self::sanitize_file_name(self::$conf['file_name']);

			// Check if file type is allowed
			if (self::$conf['allow_extensions']) {
				$ext = pathinfo($file_name, PATHINFO_EXTENSION);

				if (is_string(self::$conf['allow_extensions'])) {
					self::$conf['allow_extensions'] = preg_split('{\s*,\s*}', self::$conf['allow_extensions']);
				}

				if (!in_array($ext, self::$conf['allow_extensions'])) {
					throw new Exception('', PLUPLOAD_TYPE_ERR);
				}
			}

			$file_path = rtrim(self::$conf['target_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file_name;
			$tmp_path = $file_path . ".part";

			// Write file or chunk to appropriate temp location
			if (self::$conf['chunks']) {
				$chunk_dir = $file_path . "_d.part";
				if (!file_exists($chunk_dir)) {
					@mkdir($chunk_dir);
				}
				
				self::write_file_to($chunk_dir . DIRECTORY_SEPARATOR . self::$conf['chunk']);

				// Check if all chunks already uploaded
				if (self::$conf['chunks'] == self::$conf['chunk'] - 1) { 
					self::write_chunks_to_file($chunk_dir, $tmp_path);
				}
			} else {
				self::write_file_to($tmp_path);
			}

			// Finalize
			if (!self::$conf['chunks'] || self::$conf['chunks'] == self::$conf['chunk'] - 1) {
				rename($tmp_path, $file_path);
			}
		} catch (Exception $ex) {
			self::$_error = $ex->getCode();
			return false;
		}

		return true;
	}


	/**
	 * Writes either a multipart/form-data message or a binary stream 
	 * to the specified file.
	 *
	 * @throws Exception In case of error generates exception with the corresponding code
	 *
	 * @param string $file_path The path to write the file to
	 * @param string [$file_data_name='file'] The name of the multipart field
	 */
	function write_file_to($file_path, $file_data_name = false)
	{
		if (!$file_data_name) {
			$file_data_name = self::$config['file_data_name'];
		}

		if (!empty($_FILES)) {
			if ($_FILES[$file_data_name]["error"] || !is_uploaded_file($_FILES[$file_data_name]["tmp_name"])) {
				throw new Exception('', PLUPLOAD_MOVE_ERR);
			}
			move_uploaded_file($_FILES[$file_data_name]["tmp_name"], $file_path);
		} else {	
			// Handle binary streams
			if (!$in = @fopen("php://input", "rb")) {
				throw new Exception('', PLUPLOAD_INPUT_ERR);
			}

			if (!$out = @fopen($file_path, "wb")) {
				throw new Exception('', PLUPLOAD_OUTPUT_ERR);
			}

			while ($buff = fread($in, 4096)) {
				fwrite($out, $buff);
			}

			@fclose($out);
			@fclose($in);
		}
	}


	/**
	 * Combine chunks from the specified folder into the single file.
	 *
	 * @throws Exception In case of error generates exception with the corresponding code
	 *
	 * @param string $chunk_dir Temp directory with the chunks
	 * @param string $file_path The file to write the chunks to
	 */
	function write_chunks_to_file($chunk_dir, $file_path)
	{
		if (!$out = @fopen($file_path, "wb")) {
			throw new Exception('', PLUPLOAD_OUTPUT_ERR);
		}

		for ($i = 0; $i < self::$conf['chunks']; $i++) {
			$chunk_path = $chunk_dir . DIRECTORY_SEPARATOR . $i;
			if (!file_exists($chunk_path)) {
				throw new Exception('', PLUPLOAD_MOVE_ERR);
			}

			if (!$in = @fopen($chunk_path, "rb")) {
				throw new Exception('', PLUPLOAD_INPUT_ERR);
			}

			while ($buff = fread($in, 4096)) {
				fwrite($out, $buff);
			}
			@fclose($in);
		}
		@fclose($out);

		// Cleanup
		self::rrmdir($chunk_dir);
	}


	function no_cache_headers() 
	{
		// Make sure this file is not cached (as it might happen on iOS devices, for example)
		header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
		header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
		header("Cache-Control: no-store, no-cache, must-revalidate");
		header("Cache-Control: post-check=0, pre-check=0", false);
		header("Pragma: no-cache");
	}


	function cors_headers($headers = array(), $origin = '*')
	{
		if (!empty($headers)) {
			foreach ($headers as &$header => $value) {
				$header = strtolower($header); // normalize
				header("$header: $value");
			}

			if ($origin && !array_key_exists('access-control-allow-origin', $headers)) {
				header("access-control-allow-origin: $origin");
			}
		}

		// other CORS headers if any...
		if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
			exit; // finish preflight CORS requests here
		}
	}


	private function cleanup() 
	{
		// Remove old temp files	
		foreach(glob(self::$_target_dir . '/*.part') as $tmpFile) {
			if (time() - filemtime($tmpFile) < self::$_max_file_age) {
				continue;
			}
			if (is_dir($tmpFile)) {
				self::rrmdir($tmpFile);
			} else {
				@unlink($tmpFile);
			}
		}
	}


	/**
	 * Sanitizes a filename replacing whitespace with dashes
	 *
	 * Removes special characters that are illegal in filenames on certain
	 * operating systems and special characters requiring special escaping
	 * to manipulate at the command line. Replaces spaces and consecutive
	 * dashes with a single dash. Trim period, dash and underscore from beginning
	 * and end of filename.
	 *
	 * @author WordPress
	 *
	 * @param string $filename The filename to be sanitized
	 * @return string The sanitized filename
	 */
	private function sanitize_file_name($filename) 
	{
	    $special_chars = array("?", "[", "]", "/", "\\", "=", "<", ">", ":", ";", ",", "'", "\"", "&", "$", "#", "*", "(", ")", "|", "~", "`", "!", "{", "}");
	    $filename = str_replace($special_chars, '', $filename);
	    $filename = preg_replace('/[\s-]+/', '-', $filename);
	    $filename = trim($filename, '.-_');
	    return $filename;
	}


	/** 
	 * Concise way to recursively remove a directory 
	 * http://www.php.net/manual/en/function.rmdir.php#108113
	 *
	 * @param string $dir Directory to remove
	 */
	private function rrmdir($dir) 
	{
		foreach(glob($dir . '/*') as $file) {
			if(is_dir($file))
				self::rrmdir($file);
			else
				unlink($file);
		}
		rmdir($dir);
	}
}

