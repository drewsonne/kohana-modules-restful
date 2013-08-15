<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Abstract Controller class for RESTful controller mapping. Supports GET, PUT,
 * POST, and DELETE. By default, these methods will be mapped to these actions:
 *
 * GET
 * :  Mapped to the "get" action, lists all objects
 *
 * POST
 * :  Mapped to the "create" action, creates a new object
 *
 * PUT
 * :  Mapped to the "update" action, update an existing object
 *
 * DELETE
 * :  Mapped to the "delete" action, delete an existing object
 *
 * Additional methods can be supported by adding the method and action to
 * the `$_action_map` property.
 *
 * [!!] Using this class within a website will require heavy modification,
 * due to most web browsers only supporting the GET and POST methods.
 * Generally, this class should only be used for web services and APIs.
 *
 * @package     RESTful
 * @category    Controllers
 * @author      Michał Musiał
 * @copyright   (c) 2012 Michał Musiał
 *
 * @todo        Caching responses
 * @todo        Fallback to $_POST['_method'] if detected
 * @todo        Authentication (Authorization: ... header)
 * @todo        Investigate HEAD/OPTIONS methods
 * @todo        Investigate error messages being displayed even when they shouldn't
 */
abstract class RESTful_Controller extends Controller
{
    /**
     * @var array Array of possible actions.
     */
    protected $_action_map = array(
        HTTP_Request::GET    => 'get',
        HTTP_Request::PUT    => 'update',
        HTTP_Request::POST   => 'create',
        HTTP_Request::DELETE => 'delete',
    );

    /**
     * Array of all acceptable content-types provided with the request's Accept
     * header
     *
     * @var     array
     */
    private $_request_accept_types = array();

    /**
     * Controller Constructor
     *
     * @param   Request     $request
     * @param   Response    $response
     */
    public function __construct(Request $request, Response $response)
    {
        // Enable RESTful internal error handling
        set_exception_handler(array('RESTful_Exception', 'handler'));
        // Enable Kohana error handling, converts all PHP errors to exceptions.
        set_error_handler(array('RESTful', 'error_handler'));

        parent::__construct($request, $response);
    }

    /**
     * Preflight checks.
     */
    public function before()
    {
        parent::before();

        // Defaulting output content type to text/plain - will hopefully be overriden later
        $this->response->headers('Content-Type', 'text/plain');

        $method_override = $this->request->headers('X-HTTP-Method-Override');
        $method = strtoupper((empty($method_override)) ? $this->request->method() : $method_override);

        // Checking requested method
        if ( ! isset($this->_action_map[$method]))
        {
            return $this->request->action('invalid');
        }
        elseif ( ! method_exists($this, 'action_' . $this->_action_map[$method]))
        {
            throw HTTP_Exception::factory(500, 'METHOD_MISCONFIGURED');
        }
        else
        {
            $this->request->action($this->_action_map[$method]);
        }

        // Checking Content-Type. Considering only POST and PUT methods as other
        // shouldn't have any content.
        if (in_array($method, array(HTTP_Request::POST, HTTP_Request::PUT)))
        {
            $request_content_type = $this->request->headers('Content-Type');

            if (empty($request_content_type))
            {
                throw HTTP_Exception::factory(400, 'NO_CONTENT_TYPE_PROVIDED');
            }

            if (RESTful_Request::get_parser($request_content_type) === FALSE)
            {
                throw HTTP_Exception::factory(415);
            }
            else
            {
                $request_body = $this->request->body();

                if (strlen($request_body) > 0)
                {
                    $request_data = call_user_func(RESTful_Request::get_parser($request_content_type), $request_body);
                }
                else
                {
                    $request_data = $_POST;
                }
            }

            if ($request_data !== FALSE AND ! empty($request_data))
            {
                $this->request->body($request_data);
            }
            else
            {
                throw HTTP_Exception::factory(400, 'MALFORMED_REQUEST_BODY');
            }
        }

        // Checking Accept mime-types
        $requested_mime_types = Request::accept_type();
        $config_defaults = Kohana::$config->load('restful.defaults');

        if (count($requested_mime_types) == 0 OR (count($requested_mime_types) == 1 AND isset($requested_mime_types['*/*'])))
        {
            $this->_request_accept_types[] = $config_defaults['content-type'];
        }
        else
        {
            foreach ($requested_mime_types as $type => $q)
            {
                if (RESTful_Response::get_renderer($type) !== FALSE)
                {
                    $this->_request_accept_types[] = $type;
                }
            }
        }

        // Script should fail only if requester expects any content returned,
        // that is when it uses GET method.
        if ($method === HTTP_Request::GET AND empty($this->_request_accept_types))
        {
            throw HTTP_Exception::factory(
                406,
                'This service delivers following types: :types.',
                array(':types' => implode(', ', array_keys($this->_response_types)))
            );
        }
    }

    /**
     * Prevents caching for PUT/POST/DELETE request methods.
     */
    public function after()
    {
        $method_override = $this->request->headers('X-HTTP-Method-Override');
        $method = strtoupper((empty($method_override)) ? $this->request->method() : $method_override);

        // Prevent caching
        if (in_array($method, array(
            HTTP_Request::PUT,
            HTTP_Request::POST,
            HTTP_Request::DELETE)))
        {
            $this->response->headers('Cache-Control', 'no-cache, no-store, max-age=0, must-revalidate');
        }

        parent::after();
    }

    /**
     * Throws a HTTP_Exception_405 as a response with a list of allowed actions.
     */
    public function action_invalid()
    {
        // Send the "Method Not Allowed" response
        $this->response->headers('Allow', implode(', ', array_keys($this->_action_map)));
        throw HTTP_Exception::factory(405);
    }

    /**
     * Gets or sets the body of the response
     *
     * @return  mixed
     */
    public function response($content = NULL)
    {
        if ($content === NULL)
            return $this->response->body();

        $content = $this->_process_orm_object($content);
        $this->response->body($this->_render_response_data($content));
        return $this;
    }

    /**
     * If there are ORM objects in the response, convert to them arrays.
     * @param $content
     */
    protected function _process_orm_object($content)
    {
        if(is_array($content)) {
            return $content;
        }

        switch (get_class($content)) {
            case 'Database_MySQL_Result':
                $content = array_map(function ($item) {
                    return $item->as_array();
                }, $content->as_array());
                break;
            default:
                if ($content instanceof ORM) {
                    if($content->loaded()) {
                        $content = $content->as_array();
                    }
                } else {
                    var_dump($content);
                    die();
                }
                break;
        }
        return $content;
    }

    /**
     * Converts data given to a string using renderer selected during before().
     *
     * @param   mixed   $data
     * @throws  HTTP_Exception_500
     */
    protected function _render_response_data($data)
    {
        $success = FALSE;

        // Render response body
        foreach ($this->_request_accept_types as $type)
        {
            $body = call_user_func(RESTful_Response::get_renderer($type), $data);

            if ($body !== FALSE)
            {
                $this->response->body($body);
                $success = TRUE;
                break;
            }
        }

        if ($success === FALSE)
        {
            throw HTTP_Exception::factory(500, 'RESPONSE_RENDERER_FAILURE');
        }
    }
}
