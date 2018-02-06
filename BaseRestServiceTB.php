<?php

require(dirname(__FILE__) . '/RestServiceTB.php');

/*
 * Base class for REST services
 * @author mathias@tela-botanica.org
 * @date 08/2015
 */
abstract class BaseRestServiceTB implements RestServiceTB {

	/** Configuration given at construct time */
	protected $config;

	/** Set to true if the script is called over HTTPS */
	protected $isHTTPS;

	/** HTTP verb received (GET, POST, PUT, DELETE, OPTIONS) */
	protected $verb;

	/** Resources (URI elements) */
	protected $resources = array();

	/** Request parameters (GET or POST) */
	protected $params = array();

	/** Domain root (to build URIs) */
	protected $domainRoot;

	/** Base URI (to parse resources) */
	protected $baseURI;

	/** First resource separator (to parse resources) */
	protected $firstResourceSeparator;

	public function __construct($config) {
		$this->config = $config;

		// Is the script called over HTTPS ? Tricky !
		$this->isHTTPS = (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] != 'off'));

		// HTTP method
		$this->verb = $_SERVER['REQUEST_METHOD'];

		// server config
		$this->domainRoot = $this->config['domain_root'];
		$this->baseURI = $this->config['base_uri'];
		$this->firstResourceSeparator = "/";
		if (!empty ($this->config['first_resource_separator'])) {
			$this->firstResourceSeparator = $this->config['first_resource_separator'];
		}

		// initialization
		$this->getResources();
		$this->getParams();

		$this->init();
	}

  /**
	 * Responds to an HTTP request issued with the GET method/verb.
	 * Returns the JSON representation of a single resource (or of all resources)
	 * depending on wether the resource ID is provided in the URL path (or not).
	 */
	abstract protected function get();

	/**
	 * Responds to an HTTP request issued with the POST method/verb.
	 * Creates a new resource built using the POST parameters passed.
	 */
	abstract protected function post();

	/**
	 * Responds to an HTTP request issued with the PUT method/verb.
	 * Updates all parameters of the resource identified by the ID provided in
	 * the URL path if it exists (using the parameters passed). Responds with a
	 * 404 HTTP response if not.
	 */
	abstract protected function put();

	/**
	 * Responds to an HTTP request issued with the PATCH method/verb.
	 * Updates parts of the parameters of the resource identified by the ID
	 * provided in the URL path if it exists  (using the parameters passed).
	 * Responds with a 404 HTTP response if not.
	 */
	abstract protected function patch();

	/**
	 * Responds to an HTTP request issued with the DELETE method/verb.
	 * Removes the resource identified by the ID provided in the URL path if it
	 * exists. Responds with a 404 HTTP response if not.
	 */
	abstract protected function delete();

	/**
	 * Responds to an HTTP request issued with the OPTIONS method/verb.
	 * Responds with a 200 status with an 'Allow' header listing the HTTP methods
	 * that may be used on this resource.
	 */
	abstract protected function options();

	/** Post-constructor adjustments */
	protected function init() {
	}

	/**
	 * Reads the request and runs the appropriate method; catches library
	 * exceptions and turns them into HTTP errors with message
	 */
	public function run() {
		try {
			switch($this->verb) {
				case "GET":
					$this->get();
					break;
				case "POST":
					$this->post();
					break;
				case "PUT":
					$this->put();
					break;
				case "PATCH":
					$this->patch();
					break;
				case "DELETE":
					$this->delete();
					break;
				case "OPTIONS":
					// @WARNING will break CORS if you implement it
					$this->options();
					break;
				default:
					$this->sendError("unsupported method: $this->verb");
			}
		} catch(Exception $e) {
			// catches lib exceptions and turns them into error 500
			$this->sendError($e->getMessage(), 500);
		}
	}

	/**
	 * Sends a JSON message indicating a success and exits the program
	 * @param type $json the message
	 * @param type $code defaults to 200 (HTTP OK)
	 */
	protected function sendJson($json, $code=200) {
		header('Content-type: application/json');
		http_response_code($code);
		echo json_encode($json, JSON_UNESCAPED_UNICODE);
		exit;
	}

	/**
	 * Sends a JSON message indicating an error and exits the program
	 * @param type $error a string explaining the reason for this error
	 * @param type $code defaults to 400 (HTTP Bad Request)
	 */
	protected function sendError($error, $code=400) {
		header('Content-type: application/json');
		http_response_code($code);
		echo json_encode(array("error" => $error));
		exit;
	}

	/**
	 * Compares request URI to base URI to extract URI elements (resources)
	 */
	protected function getResources() {
		$uri = $_SERVER['REQUEST_URI'];
		// slicing URI
		$baseURI = $this->baseURI . $this->firstResourceSeparator;
		if ((strlen($uri) > strlen($baseURI)) && (strpos($uri, $baseURI) !== false)) {
			$baseUriLength = strlen($baseURI);
			$posQM = strpos($uri, '?');
			if ($posQM != false) {
				$resourcesString = substr($uri, $baseUriLength, $posQM - $baseUriLength);
			} else {
				$resourcesString = substr($uri, $baseUriLength);
			}
			// decoding special characters
			$resourcesString = urldecode($resourcesString);
			//echo "Resources: $resourcesString" . PHP_EOL;
			$this->resources = explode("/", $resourcesString);
			// in case of a final /, gets rid of the last empty resource
			$nbRessources = count($this->resources);
			if (empty($this->resources[$nbRessources - 1])) {
				unset($this->resources[$nbRessources - 1]);
			}
		}
	}

	/**
	 * Gets the GET or POST request parameters
	 */
	protected function getParams() {
		$this->params = $_REQUEST;
	}

	/**
	 * Searches for parameter $name in $this->params; if defined (even if
	 * empty), returns its value; if undefined, returns $default; if
	 * $collection is a non-empty array, parameters will be searched among
	 * it rather than among $this->params (2-in-1-dirty-mode)
	 */
	protected function getParam($name, $default=null, $collection=null) {
		$arrayToSearch = $this->params;
		if (is_array($collection) && !empty($collection)) {
			$arrayToSearch = $collection;
		}
		if (isset($arrayToSearch[$name])) {
			return $arrayToSearch[$name];
		} else {
			return $default;
		}
	}
 
	/**
	 * Reads and returns request body contents
	 */
	protected function readRequestBody() {
		// @TODO beware of memory consumption
		$contents = file_get_contents('php://input');
		return $contents;
	}

	protected function sendFile($file, $name, $size, $mimetype='application/octet-stream') {
		if (! file_exists($file)) {
			$this->sendError("file does not exist");
		}
		header('Content-Type: ' . $mimetype);
		header('Content-Disposition: attachment; filename="' . $name . '"');
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
		header('Content-Length: ' . $size);
		// progressive sending
		// http://www.media-division.com/the-right-way-to-handle-file-downloads-in-php/
		set_time_limit(0);
		$f = @fopen($file,"rb");
		while(!feof($f)) {
			print(fread($f, 1024*8));
			ob_flush();
			flush();
		}
	}
}
