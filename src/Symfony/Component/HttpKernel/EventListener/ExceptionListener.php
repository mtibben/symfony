<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel\EventListener;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Debug\Exception\FlattenException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * ExceptionListener.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class ExceptionListener implements EventSubscriberInterface
{
    protected $controller;
    protected $logger;
    protected $httpStatusCodeLogLevel = [];

    public function __construct($controller, LoggerInterface $logger = null)
    {
        $this->controller = $controller;
        $this->logger = $logger;
    }

    public function logKernelException(GetResponseForExceptionEvent $event)
    {
        $exception = $event->getException();
        $request = $event->getRequest();

        $this->logException($exception, sprintf('Uncaught PHP Exception %s: "%s" at %s line %s', get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine()));
    }

    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        $exception = $event->getException();
        $request = $this->duplicateRequest($exception, $event->getRequest());
        $eventDispatcher = func_num_args() > 2 ? func_get_arg(2) : null;

        try {
            $response = $event->getKernel()->handle($request, HttpKernelInterface::SUB_REQUEST, false);
        } catch (\Exception $e) {
            $this->logException($e, sprintf('Exception thrown when handling an exception (%s: %s at %s line %s)', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

            $wrapper = $e;

            while ($prev = $wrapper->getPrevious()) {
                if ($exception === $wrapper = $prev) {
                    throw $e;
                }
            }

            $prev = new \ReflectionProperty('Exception', 'previous');
            $prev->setAccessible(true);
            $prev->setValue($wrapper, $exception);

            throw $e;
        }

        $event->setResponse($response);

        if ($eventDispatcher instanceof EventDispatcherInterface) {
            $cspRemovalListener = function (FilterResponseEvent $event) use (&$cspRemovalListener, $eventDispatcher) {
                $event->getResponse()->headers->remove('Content-Security-Policy');
                $eventDispatcher->removeListener(KernelEvents::RESPONSE, $cspRemovalListener);
            };
            $eventDispatcher->addListener(KernelEvents::RESPONSE, $cspRemovalListener, -128);
        }
    }

    public static function getSubscribedEvents()
    {
        return array(
            KernelEvents::EXCEPTION => array(
                array('logKernelException', 2048),
                array('onKernelException', -128),
            ),
        );
    }

    protected function getExceptionLogLevel(\Exception $exception)
    {
        $logLevel = LogLevel::CRITICAL;
        if ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
            if (isset($this->httpStatusCodeLogLevel[$statusCode])) {
                $logLevel = $this->httpStatusCodeLogLevel[$statusCode];
            } else if ($statusCode < 500) {
                $logLevel = LogLevel::ERROR;
            }
        }

        return $logLevel;
    }

    /**
     * Logs an exception.
     *
     * @param \Exception $exception The \Exception instance
     * @param string     $message   The error message to log
     */
    protected function logException(\Exception $exception, $message)
    {
        if (null !== $this->logger) {
            $this->logger->log($this->getExceptionLogLevel($exception), $message, array('exception' => $exception));
        }
    }

    /**
     * Clones the request for the exception.
     *
     * @param \Exception $exception The thrown exception
     * @param Request    $request   The original request
     *
     * @return Request $request The cloned request
     */
    protected function duplicateRequest(\Exception $exception, Request $request)
    {
        $attributes = array(
            '_controller' => $this->controller,
            'exception' => FlattenException::create($exception),
            'logger' => $this->logger instanceof DebugLoggerInterface ? $this->logger : null,
        );
        $request = $request->duplicate(null, null, $attributes);
        $request->setMethod('GET');

        return $request;
    }
}
