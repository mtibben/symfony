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
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class LoggingExceptionListener implements EventSubscriberInterface
{
    protected $logger;
    protected $httpStatusCodeLogLevel;
    private $isDisabled;

    public function __construct(LoggerInterface $logger = null, array $httpStatusCodeLogLevel = array()/*, ExceptionListener $exceptionListener = null */)
    {
        $this->logger = $logger ?: new NullLogger();
        $this->httpStatusCodeLogLevel = $httpStatusCodeLogLevel;

        if (func_num_args() >= 3) {
            $exceptionListener = func_get_arg(2);
            $this->isDisabled = ($exceptionListener instanceof ExceptionListener) ? $exceptionListener->isLoggingExceptions() : false;
            if ($this->isDisabled) {
                $this->logger->debug('Logging disabled for backwards compatibility, ExceptionListener is already logging exceptions.');
            }
        }
    }

    public function logKernelException(GetResponseForExceptionEvent $event)
    {
        if ($this->isDisabled) {
            return;
        }

        $exception = $event->getException();
        $level = $this->getExceptionLogLevel($exception);
        $message = sprintf('Uncaught PHP Exception %s: "%s" at %s line %s', get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine());

        $this->logger->log($level, $message, array('exception' => $exception));
    }

    public static function getSubscribedEvents(): array
    {
        return array(
            KernelEvents::EXCEPTION => array(
                array('logKernelException', 2048),
            ),
        );
    }

    protected function getExceptionLogLevel(\Exception $exception): string
    {
        $logLevel = LogLevel::CRITICAL;
        if ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
            if (isset($this->httpStatusCodeLogLevel[$statusCode])) {
                $logLevel = $this->httpStatusCodeLogLevel[$statusCode];
            } elseif ($statusCode >= 400 && $statusCode < 500) {
                $logLevel = LogLevel::WARNING;
            }
        }

        return $logLevel;
    }
}
