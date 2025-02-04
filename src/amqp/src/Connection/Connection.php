<?php declare(strict_types=1);

namespace Swoft\Amqp\Connection;

use Closure;
use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSocketConnection;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Swoft\Amqp\Client;
use Swoft\Amqp\Contract\ConnectionInterface;
use Swoft\Amqp\Exception\AMQPException;
use Swoft\Amqp\Pool;
use Swoft\Bean\BeanFactory;
use Swoft\Connection\Pool\AbstractConnection;
use Swoft\Stdlib\Helper\Arr;

/**
 * Class Connection
 *
 * @since   2.0
 *
 * @package Swoft\Amqp\Connection
 */
class Connection extends AbstractConnection implements ConnectionInterface
{
    /**
     * @var AMQPSSLConnection | AMQPSocketConnection | AMQPStreamConnection
     */
    protected $connection;

    /**
     * @var AMQPChannel
     */
    protected $channel;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @param Pool   $pool
     * @param Client $client
     */
    public function initialize(Pool $pool, Client $client)
    {
        $this->pool     = $pool;
        $this->client   = $client;
        $this->lastTime = time();

        $this->id = $this->pool->getConnectionId();
    }

    /**
     * create
     *
     * @throws AMQPException
     * @throws \PhpAmqpLib\Exception\AMQPTimeoutException
     */
    public function create(): void
    {
        $this->createClient();
    }

    /**
     * createClient
     *
     * @throws AMQPException
     * @throws \PhpAmqpLib\Exception\AMQPTimeoutException
     */
    public function createClient(): void
    {
        $auth['host']     = $this->client->getHost();
        $auth['port']     = $this->client->getPort();
        $auth['user']     = $this->client->getUser();
        $auth['password'] = $this->client->getPassword();
        $auth['vhost']    = $this->client->getVhost();

        $setting = $this->client->getSetting();

        try {
            $this->connection = $this->connect([$auth], $setting['connect'] ?? []);
        } catch (Exception $e) {
            throw new AMQPException(
                sprintf('RabbitMQ connect error is %s file=%s line=%d', $e->getMessage(), $e->getFile(), $e->getLine())
            );
        }

        $this->channel();
    }

    /**
     * channel
     *
     * @param string|null $channel
     *
     * @return Connection
     * @throws AMQPException
     */
    public function channel($channelId = 'default'): Connection
    {
        $channels = $this->client->getChannels();
        $exchange = $this->client->getExchange();
        $queue    = $this->client->getQueue();

        if (!isset($channels['default'])) {
            if ($exchange && $queue) {
                $channels['default']['exchange'] = $exchange;
                $channels['default']['queue']    = $queue;
                $this->client->setChannels($channels);
            } else {
                $channels['default'] = Arr::first($channels);
            }
        }

        if (isset($channels[$channelId])) {
            if (!isset($channels[$channelId]['exchange'])) {
                throw new AMQPException('RabbitMQ connect error is Exchange empty');
            }
            if (!isset($channels[$channelId]['queue'])) {
                throw new AMQPException('RabbitMQ connect error is Queue empty');
            }
            $this->client->setExchange($channels[$channelId]['exchange']);
            $this->client->setQueue($channels[$channelId]['queue']);
        } else {
            throw new AMQPException('RabbitMQ connect error is Channel has not exists');
        }

        $channelKeys = array_keys($channels);
        $channelIds  = array_flip($channelKeys);

        $this->channel = $this->connection->channel($channelIds[$channelId] + 1);

        $this->declareQueue();
        $this->declareExchange();
        $this->bind();

        return $this;
    }

    /**
     * reconnect
     *
     * @return bool
     * @throws AMQPException
     * @throws \PhpAmqpLib\Exception\AMQPTimeoutException
     */
    public function reconnect(): bool
    {
        try {
            $this->createClient();
        } catch (Throwable $e) {
            Log::error('RabbitMQ reconnect error(%s)', $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * close
     *
     * @throws \PhpAmqpLib\Exception\AMQPTimeoutException
     */
    public function close(): void
    {
        $this->channel->close();
        $this->connection->close();
    }

    /**
     * release
     *
     * @param bool $force
     */
    public function release(bool $force = false): void
    {
        /* @var ConnectionManager $conManager */
        $conManager = BeanFactory::getBean(ConnectionManager::class);
        $conManager->releaseConnection($this->id);

        parent::release($force);
    }

    /**
     * declare exchange
     *
     * @throws AMQPException
     */
    protected function declareExchange(): void
    {
        $exchange = $this->client->getExchange();
        $type     = $this->client->getType();
        $setting  = $this->client->getSetting();

        try {
            $this->channel->exchange_declare(
                $exchange,
                $type,
                $setting['passive'] ?? false,
                $setting['durable'] ?? true,
                $setting['auto_delete'] ?? false,
                $setting['internal'] ?? false,
                $setting['nowait'] ?? false,
                $setting['arguments'] ?? [],
                $setting['ticket'] ?? null
            );
        } catch (Exception $exception) {
            throw new AMQPException(
                sprintf('RabbitMQ declare exchange error is %s file=%s line=%d', $e->getMessage(), $e->getFile(), $e->getLine())
            );
        }
    }

    /**
     * declare queue
     *
     * @throws AMQPException
     */
    protected function declareQueue(): void
    {
        $queue   = $this->client->getQueue();
        $setting = $this->client->getSetting();

        try {
            $this->channel->queue_declare(
                $queue,
                $setting['passive'] ?? false,
                $setting['durable'] ?? true,
                $setting['exclusive'] ?? false,
                $setting['auto_delete'] ?? false,
                $setting['nowait'] ?? false,
                $setting['arguments'] ?? [],
                $setting['ticket'] ?? null
            );
        } catch (Exception $exception) {
            throw new AMQPException(
                sprintf('RabbitMQ declare queue error is %s file=%s line=%d', $e->getMessage(), $e->getFile(), $e->getLine())
            );
        }
    }

    /**
     * bind
     *
     * @throws AMQPException
     */
    protected function bind(): void
    {
        $exchange = $this->client->getExchange();
        $queue    = $this->client->getQueue();
        $route    = $this->client->getRoute();
        $setting  = $this->client->getSetting();

        try {
            $this->channel->queue_bind(
                $queue,
                $exchange,
                $route,
                $setting['nowait'] ?? false,
                $setting['arguments'] ?? [],
                $setting['ticket'] ?? null
            );
        } catch (Exception $exception) {
            throw new AMQPException(
                sprintf('RabbitMQ bind queue and exchange error is %s file=%s line=%d', $e->getMessage(), $e->getFile(), $e->getLine())
            );
        }
    }

    /**
     * push
     *
     * @param string $body
     * @param array  $properties
     * @param string $routeKey
     *
     * @throws \PhpAmqpLib\Exception\AMQPConnectionClosedException
     */
    public function push(string $message, array $prop = [], string $route = ''): void
    {
        $exchange = $this->client->getExchange();
        $body     = new AMQPMessage($message, $prop);
        $this->channel->basic_publish($body, $exchange, $route);
        $this->release();
    }

    /**
     * pull
     *
     * @return string|null
     * @throws \PhpAmqpLib\Exception\AMQPTimeoutException
     */
    public function pull(): ?string
    {
        $queue = $this->client->getQueue();
        /* @var AMQPMessage $message */
        $message = $this->channel->basic_get($queue, true);
        $this->release();

        return $message ? $message->body : null;
    }

    /**
     * listen
     *
     * @param Closure|null $callback
     *
     * @return string
     * @throws \ErrorException
     * @throws \PhpAmqpLib\Exception\AMQPOutOfBoundsException
     * @throws \PhpAmqpLib\Exception\AMQPRuntimeException
     * @throws \PhpAmqpLib\Exception\AMQPTimeoutException
     */
    public function listen(Closure $callback = null): void
    {
        $queue   = $this->client->getQueue();
        $setting = $this->client->getSetting();
        $consume = $setting['consume'] ?? [];

        //consume the message
        $this->channel->basic_consume(
            $queue,
            $consume['consumer_tag'] ?? '',
            $consume['no_local'] ?? false,
            $consume['no_ack'] ?? false,
            $consume['exclusive'] ?? false,
            $consume['nowait'] ?? false,
            function (AMQPMessage $message) use ($callback, $consume) {
                $cancelTag = $consume['cancel_tag'] ?? [];
                $cancel    = is_array($cancelTag) ? in_array($message->body, $cancelTag) : $message->body == $cancelTag;
                $this->channel->basic_ack($message->delivery_info['delivery_tag']);
                if ($cancel) {
                    $this->channel->basic_cancel($message->delivery_info['consumer_tag']);
                }
                !empty($callback) && $callback($message);
            }
        );

        //wait for message
        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }

        $this->release();
    }

    /**
     * @return AMQPSocketConnection|AMQPSSLConnection|AMQPStreamConnection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * @return AMQPChannel
     */
    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }

    /**
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }
}