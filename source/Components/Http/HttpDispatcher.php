<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Spiral\Components\Debug\Snapshot;
use Spiral\Components\Http\Router\RouterTrait;
use Spiral\Components\Http\Router\Router;
use Spiral\Components\View\ViewManager;
use Spiral\Core\Component;
use Spiral\Core\ConfiguratorInterface;
use Spiral\Core\Container;
use Spiral\Core\CoreInterface;
use Spiral\Core\Dispatcher\ClientException;
use Spiral\Core\DispatcherInterface;

class HttpDispatcher extends Component implements DispatcherInterface
{
    /**
     * Required traits.
     */
    use Component\SingletonTrait,
        Component\LoggerTrait,
        Component\EventsTrait,
        Component\ConfigurableTrait,
        RouterTrait;

    /**
     * Declares to IoC that component instance should be treated as singleton.
     */
    const SINGLETON = __CLASS__;

    /**
     * Default spiral router with known constructor. This constant helps to speed up router creation.
     */
    const DEFAULT_ROUTER = 'Spiral\Components\Http\Router\Router';

    /**
     * Original server request generated by spiral while starting HttpDispatcher.
     *
     * @var Request
     */
    protected $request = null;

    /**
     * Set of middleware layers built to handle incoming Request and return Response. Middleware
     * can be represented as class, string (DI), closure or array (callable method). HttpDispatcher
     * layer middlewares will be called in start() method. This set of middleware(s) used to filter
     * http request and response on application layer.
     *
     * @var array|MiddlewareInterface[]|callable[]
     */
    protected $middlewares = array();

    /**
     * Endpoints is a set of middleware or callback used to handle some application parts separately
     * from application controllers and routes. Such Middlewares can perform their own routing,
     * mapping, render and etc and only have to return ResponseInterface object.
     *
     * You can use add() method to create new endpoint. Every endpoint should be specified as path
     * with / and in lower case.
     *
     * Example (in bootstrap):
     * $this->http->add('/forum', 'Vendor\Forum\Forum');
     *
     * P.S. Router middleware automatically assigned to base path of application.
     *
     * @var array|MiddlewareInterface[]
     */
    protected $endpoints = array();

    /**
     * New HttpDispatcher instance.
     *
     * @param ConfiguratorInterface $configurator
     * @param Container             $container
     */
    public function __construct(
        ConfiguratorInterface $configurator,
        Container $container
    )
    {
        $this->container = $container;
        $this->config = $configurator->getConfig('http');

        $this->middlewares = $this->config['middlewares'];
        $this->endpoints = $this->config['endpoints'];
    }

    /**
     * Application base path.
     *
     * @return string
     */
    public function getBasePath()
    {
        return $this->config['basePath'];
    }

    /**
     * Register new endpoint or middleware inside HttpDispatcher. HttpDispatcher will execute such
     * enterpoint only with URI path matched to specified value. The rest of http flow will be
     * given to this enterpoint.
     *
     * Example (in bootstrap):
     * $this->http->add('/forum', 'Vendor\Forum\Forum');
     * $this->http->add('/blog', new Vendor\Module\Blog());
     *
     * @param string                              $path Http Uri path with / and in lower case.
     * @param string|callable|MiddlewareInterface $endpoint
     * @return static
     */
    public function add($path, $endpoint)
    {
        $this->endpoints[$path] = $endpoint;

        return $this;
    }

    /**
     * Letting dispatcher to control application flow and functionality.
     *
     * @param CoreInterface $core
     */
    public function start(CoreInterface $core)
    {
        if (empty($this->endpoints[$this->config['basePath']]))
        {
            //Base path wasn't handled, let's attach our router
            $this->endpoints[$this->config['basePath']] = $this->createRouter();
        }

        $this->dispatch(
            $this->event('dispatch', $this->perform(
                $this->getRequest()
            ))
        );
    }

    /**
     * Getting instance of default http router to be attached ot http base path (usually /).
     *
     * @return Router
     */
    protected function createRouter()
    {
        $router = $this->config['router']['class'];

        if ($router == self::DEFAULT_ROUTER)
        {
            return new $router(
                $this->container,
                $this->routes,
                $this->config['router']['primaryRoute']
            );
        }

        return $this->container->get(
            $router,
            array(
                'container'    => $this->container,
                'routes'       => $this->routes,
                'primaryRoute' => $this->config['router']['primaryRoute']
            )
        );
    }

    /**
     * Get initial request generated by HttpDispatcher. This is untouched request object, all
     * cookies will be encrypted and other values will not be pre-processed.
     *
     * @return Request|null
     */
    public function getRequest()
    {
        if (empty($this->request))
        {
            $this->request = Request::castRequest(array(
                'basePath'     => $this->config['basePath'],
                'activePath'   => $this->config['basePath'],
                'exposeErrors' => $this->config['exposeErrors']
            ));
        }

        return $this->request;
    }

    /**
     * Execute given request and return response. Request Uri will be passed thought Http routes
     * to find appropriate endpoint. By default this method will be called at the end of middleware
     * pipeline inside HttpDispatcher->start() method, however method can be called manually with
     * custom or altered request instance.
     *
     * Every request passed to perform method will be registered in Container scope under "request"
     * and class name binding.
     *
     * Http component middlewares will be applied to request and response.
     *
     * @param ServerRequestInterface $request
     * @return array|ResponseInterface
     * @throws ClientException
     */
    public function perform(ServerRequestInterface $request)
    {
        if (!$endpoint = $this->findEndpoint($request->getUri(), $activePath))
        {
            //This should never happen as request should be handled at least by Router middleware
            throw new ClientException(Response::SERVER_ERROR, 'Unable to select endpoint');
        }

        $pipeline = new HttpPipeline($this->container, $this->middlewares);

        return $pipeline->target($endpoint)->run(
            $request->withAttribute('activePath', $activePath)
        );
    }

    /**
     * Locate appropriate middleware endpoint based on Uri part.
     *
     * @param UriInterface $uri     Request Uri.
     * @param string       $uriPath Selected path.
     * @return null|MiddlewareInterface
     */
    protected function findEndpoint(UriInterface $uri, &$uriPath = null)
    {
        $uriPath = strtolower($uri->getPath());
        if (empty($uriPath))
        {
            $uriPath = '/';
        }
        elseif ($uriPath[0] !== '/')
        {
            $uriPath = '/' . $uriPath;
        }

        if (isset($this->endpoints[$uriPath]))
        {
            return $this->endpoints[$uriPath];
        }
        else
        {
            foreach ($this->endpoints as $path => $middleware)
            {
                if (strpos($uriPath, $path) === 0)
                {
                    $uriPath = $path;

                    return $middleware;
                }
            }
        }

        return null;
    }

    /**
     * Dispatch provided request to client. Application will stop after this method call.
     *
     * @param ResponseInterface $response
     */
    public function dispatch(ResponseInterface $response)
    {
        while (ob_get_level())
        {
            ob_get_clean();
        }

        /**
         * For our needs we will overwrite protocol version with value provided by client browser.
         */
        $statusHeader = "HTTP/{$this->request->getProtocolVersion()} {$response->getStatusCode()}";
        header(rtrim("{$statusHeader} {$response->getReasonPhrase()}"));

        $defaultHeaders = $this->config['headers'];
        foreach ($response->getHeaders() as $header => $values)
        {
            unset($defaultHeaders[$header]);

            $replace = true;
            foreach ($values as $value)
            {
                header("{$header}: {$value}", $replace);
                $replace = false;
            }
        }

        if (!empty($defaultHeaders))
        {
            //We can force some header values if no replacement specified
            foreach ($defaultHeaders as $header => $value)
            {
                header("{$header}: {$value}");
            }
        }

        if ($response->getStatusCode() == 204)
        {
            return;
        }

        $this->sendStream($response->getBody());
    }

    /**
     * Send stream content to client.
     *
     * @param StreamInterface $stream
     */
    protected function sendStream(StreamInterface $stream)
    {
        if (!$stream->isSeekable())
        {
            echo $stream->__toString();
        }
        else
        {
            ob_implicit_flush(true);
            $stream->rewind();

            while (!$stream->eof())
            {
                echo $stream->read(Stream::READ_BLOCK_SIZE);
            }
        }
    }

    /**
     * Every dispatcher should know how to handle exception snapshot provided by Debugger.
     *
     * @param Snapshot $snapshot
     * @return void
     */
    public function handleException(Snapshot $snapshot)
    {
        $exception = $snapshot->getException();
        if ($exception instanceof ClientException)
        {
            $uri = $this->request->getUri();

            self::logger()->warning(
                "{scheme}://{host}{path} caused the error {code} ({message}) by client {remote}.",
                array(
                    'scheme'  => $uri->getScheme(),
                    'host'    => $uri->getHost(),
                    'path'    => $uri->getPath(),
                    'code'    => $exception->getCode(),
                    'message' => $exception->getMessage() ?: '-not specified-',
                    'remote'  => InputManager::getInstance($this->container)->getRemoteAddr()
                )
            );

            $this->dispatch($this->errorResponse($exception->getCode()));

            return;
        }

        if (!$this->config['exposeErrors'])
        {
            $this->dispatch($this->errorResponse(500));

            return;
        }

        if ($this->request->getHeaderLine('Accept') == 'application/json')
        {
            $content = array('status' => 500) + $snapshot->packException();
            $this->dispatch(new Response(json_encode($content), 500, array(
                'Content-Type' => 'application/json'
            )));

            return;
        }
        else
        {
            //Regular HTML snapshot
            $this->dispatch(new Response($snapshot->renderSnapshot(), 500));
        }
    }

    /**
     * Get response dedicated to represent server or client error.
     *
     * @param int $code
     * @return Response
     */
    protected function errorResponse($code)
    {
        $content = '';

        if ($this->request->getHeaderLine('Accept') == 'application/json')
        {
            $content = array('status' => $code);

            return new Response(json_encode($content), $code, array(
                'Content-Type' => 'application/json'
            ));
        }

        if (isset($this->config['httpErrors'][$code]))
        {
            //We can render some content
            $content = ViewManager::getInstance($this->container)->render(
                $this->config['httpErrors'][$code],
                array('request' => $this->request)
            );
        }

        return new Response($content, $code);
    }
}