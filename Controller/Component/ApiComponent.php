<?php
App::uses('ApiUtility', 'Api.Lib');
App::uses('ApiListener', 'Api.Controller/Event');

/**
 * API component
 *
 * Handles the automatic transformation of HTTP requests to API responses
 *
 * @see https://github.com/nodesagency/Platform-API-plugin/blob/master/README.md
 * @see http://book.cakephp.org/2.0/en/controllers/components.html#Component
 * @copyright Nodes ApS, 2011
 */
class ApiComponent extends Component {

	/**
	* Reference to the current controller
	*
	* @var Controller
	*/
	protected $controller;

	/**
	* Reference to the current request
	*
	* @var CakeRequest
	*/
	protected $request;

	/**
	* Reference to the current response
	*
	* @var CakeResponse
	*/
	protected $response;

	/**
	 * @var boolean
	 */
	protected $allowJsonp = false;

	/**
	* initialize callback
	*
	* @param Controller $controller
	* @return void
	*/
	public function initialize(Controller $controller) {
		// Ensure we can detect API requests
		$this->setup($controller);
	}

	/**
	* Deny public access to an action
	*
	* @param string $action
	* @return boolean
	*/
	public function denyPublic($action) {
		$pos = array_search($action, $this->publicActions);
		if (false === $pos) {
			return false;
		}
		unset($this->publicActions[$pos]);
		return true;
	}

	/**
	* Allow jsonp
	*
	* @param boolean
	* @return void
	*/
	public function allowJsonp($value = true) {
		$this->allowJsonp = (boolean)$value;
	}

	/**
	* beforeRender callback
	*
	* @return void
	*/
	public function beforeRender(Controller $controller) {
		if (!$this->controller) {
			$this->setup($controller);
		}

		if (!$this->request->is('api')) {
			return;
		}

		Configure::write('ResponseObject', $this->response);

		// Switch to the API view class
		$this->controller->viewClass = 'Api.Api';
		if (Configure::read('debug')) {
			$this->controller->helpers[] = 'Api.JsonFormat';
		}

		// Ensure we output data as JSON
		if ($this->hasError()) {
			$this->controller->layout = 'Api.json/error';
		} else {
			$this->controller->layout = 'Api.json/default';
		}

		// Override RequestHandler messing around with my layoutPaths
		// If not set to null it may do json/json/default.ctp as layout in non-crud actions
		$this->controller->layoutPath = null;
		$this->controller->set('allowJsonp', $this->allowJsonp);

		$showPaginationLinks = isset($this->settings['showPaginationLinks']) ? $this->settings['showPaginationLinks'] : true;
		$this->controller->set(compact('showPaginationLinks'));
	}

	/**
	* Is the current controller an Error controller?
	*
	* @return boolean
	*/
	public function hasError() {
		return get_class($this->controller) == 'CakeErrorController';
	}

	/**
	* beforeRedirection
	*
	* @param Controller $controller
	* @param mixed $url
	* @param integer $status
	* @param boolean $exit
	* @return void
	*/
	public function beforeRedirect(Controller $controller, $url, $status = null, $exit = true) {
		if ($controller->request->is('api')) {
			if (empty($status)) {
				$status = 302;
			}

			// Make sure URls always is absolute
			$url = Router::url($url, true);

			$controller->view = 'redirect';
			switch($status) {
				case 404:
					$controller->response->statusCode(404);
					$controller->response->send();
					$this->_stop();
				case 301:
				case 302:
					$controller->response->statusCode($status);
					$controller->response->header(array('location' => $url));
					break;
				default:
					break;
			}

			$success = true;

			// Render the redirect view
			$controller->set(compact('success', 'url', 'status'));
			$controller->render();

			// Send the result and stop the request
			$controller->response->send();
			$this->_stop();
		}
	}

	/**
	* Setup method
	*
	* @param Controller $controller
	* @return void
	*/
	protected function setup(Controller $controller) {
		// Cache local properties from the controller
		$this->controller	= $controller;
		$this->request		= $controller->request;
		$this->response		= $controller->response;

		// Configure detectors
		$this->configureRequestDetectors();

		// Don't do anything if the request isn't considered API
		if (!$this->request->is('api')) {
			return;
		}

		// Bind Crud Event Api
		$this->controller->getEventManager()->attach(new ApiListener());

		// Copy publicActions from the controller if set and no actions has been defined already
		// @todo: This is legacy, remove it
		if (isset($this->controller->publicActions) && empty($this->publicActions)) {
			$this->publicActions = $this->controller->publicActions;
		}

		// Change Exception.renderer so output isn't forced to HTML
		Configure::write('Exception.renderer', 'Api.ApiExceptionRenderer');

		// Always repond as JSON
		$this->controller->response->type('json');
	}

	/**
	* Configure detectors for API requests
	*
	* Add detectors for ->is('api') and ->is('json') on CakeRequest
	*
	* @return void
	*/
	protected function configureRequestDetectors() {
		// Add detector for json
		$this->request->addDetector('json', array('callback' => function(CakeRequest $request) {
			// The sure solution is to check if the extension is "json"
			if (isset($request->params['ext']) && $request->params['ext'] === 'json') {
				return true;
			}

			// Or try to sniff out the accept header
			return $request->accepts('application/json');
		}));

		// Generic API check
		$this->request->addDetector('api', array('callback' => function(CakeRequest $request) {
			// Currently only checks if a request is JSON - but allows us to easily add other request formats
			return $request->is('json');
		}));
	}
}
